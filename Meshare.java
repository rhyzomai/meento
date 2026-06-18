import javax.swing.*;
import javax.swing.border.*;
import javax.swing.table.*;
import java.awt.*;
import java.awt.event.*;
import java.io.*;
import java.net.*;
import java.text.*;
import java.util.*;
import java.util.List;
import java.util.concurrent.*;
import java.util.concurrent.atomic.*;

/**
 * Meshare – Distributed file search and download GUI
 * Java 8 compatible · single file · no external libraries
 *
 * Optimisations applied:
 *  #1  I/O-bound thread pool (4× cores, capped at 64)
 *  #2  ConcurrentLinkedQueue + AtomicInteger for results (no broad lock)
 *  #3  HTTP keep-alive via persistent system property + reused buffers
 *  #4  JSON parser works on char[] – zero substring allocation for keys/values
 *  #5  Query terms pre-split once before the search loop
 *  #6  EDT batching via javax.swing.Timer (100 ms flush)
 *  #7  Download retry (3 attempts, exponential back-off) + Range resume
 *  #8  Shared toJson(Map) helper used by log writer
 *  #9  files.txt streamed line-by-line (no full-body buffer)
 * #10  Null-safe executor guard on cancel
 * #11  Per-server extension cache (ConcurrentHashMap)
 */
public class Meshare extends JFrame {

    // -- Constants -------------------------------------------------------------
    private static final int    MAX_RESULTS     = 200;
    private static final int    MAX_FIELD_LEN   = 512;
    private static final int    CONNECT_TIMEOUT = 8_000;
    private static final int    READ_TIMEOUT    = 20_000;
    private static final int    DL_RETRIES      = 3;
    private static final int    BATCH_MS        = 100;   // EDT flush interval
    private static final String LOG_DIR         = "log";

    // Columns: Filename is new; Hash stays hidden
    private static final String[] COL_NAMES = {
        "Filename", "Name", "Extension", "Size", "Description", "Tags", "Server", "Hash"
    };
    private static final int COL_HASH = 7; // hidden column index

    // -- Concurrency -----------------------------------------------------------
    /** Thread count for I/O-bound work: 4× cores, capped at 64. */
    private static int ioThreads() {
        return Math.min(Runtime.getRuntime().availableProcessors() * 4, 64);
    }

    // -- State -----------------------------------------------------------------
    private final List<String>   servers     = new ArrayList<>();
    /** Lock-free result queue; drained to resultList on EDT. */
    private final ConcurrentLinkedQueue<FileEntry> pendingQueue = new ConcurrentLinkedQueue<>();
    /** EDT-only list – used for download index lookups. */
    private final List<FileEntry> resultList = new ArrayList<>();
    private final AtomicInteger   resultCount = new AtomicInteger(0);

    /** Per-server extension cache (opt #11). */
    private final ConcurrentHashMap<String, List<String>> extCache = new ConcurrentHashMap<>();

    private final DefaultTableModel tableModel = new DefaultTableModel(COL_NAMES, 0) {
        @Override public boolean isCellEditable(int r, int c) { return false; }
    };

    private volatile boolean    searching     = false;
    private ExecutorService     searchExecutor;
    /** Drains pendingQueue ? table on the EDT (opt #6). */
    private javax.swing.Timer   batchTimer;

    // -- UI --------------------------------------------------------------------
    private JTextField   searchField;
    private JButton      searchBtn;
    private JTable       table;
    private JLabel       statusLabel;
    private JCheckBox    randomModeChk;
    private JSpinner     randomCountSpinner;
    private JProgressBar progressBar;

    // -------------------------------------------------------------------------
    public Meshare() {
        super("Meshare");
        // Opt #3 – global HTTP keep-alive
        System.setProperty("http.keepAlive", "true");
        System.setProperty("http.maxConnections", "20");
        loadServers();
        buildUI();
        applyTheme();
        setDefaultCloseOperation(JFrame.EXIT_ON_CLOSE);
        setSize(1200, 720);
        setMinimumSize(new Dimension(860, 520));
        setLocationRelativeTo(null);
        setVisible(true);
    }

    // -- Load servers.txt ------------------------------------------------------
    private void loadServers() {
        File f = new File("servers.txt");
        if (!f.exists()) {
            JOptionPane.showMessageDialog(null,
                "servers.txt not found in the working directory.\n" +
                "Place it next to the jar and restart.",
                "Warning", JOptionPane.WARNING_MESSAGE);
            return;
        }
        try (BufferedReader br = new BufferedReader(new FileReader(f))) {
            String line;
            while ((line = br.readLine()) != null) {
                line = line.trim();
                if (!line.isEmpty()) {
                    if (line.endsWith("/")) line = line.substring(0, line.length() - 1);
                    servers.add(line);
                }
            }
        } catch (IOException e) {
            showError("Cannot read servers.txt: " + e.getMessage());
        }
    }

    // -- UI construction -------------------------------------------------------
    private void buildUI() {
        JPanel root = new JPanel(new BorderLayout(8, 8));
        root.setBorder(new EmptyBorder(12, 14, 12, 14));
        setContentPane(root);

        // -- Top bar ----------------------------------------------------------
        JPanel top = new JPanel(new BorderLayout(8, 6));
        JLabel title = new JLabel("Meshare");
        title.setFont(new Font("SansSerif", Font.BOLD, 22));
        top.add(title, BorderLayout.NORTH);

        JPanel searchRow = new JPanel(new BorderLayout(6, 0));
        searchField = new JTextField();
        searchField.setFont(new Font("SansSerif", Font.PLAIN, 14));
        searchField.setToolTipText("Type keywords – all metadata fields are searched");
        searchField.addActionListener(e -> startSearch());

        searchBtn = makeButton("Search", "Search all servers", Color.decode("#2563EB"));
        searchBtn.addActionListener(e -> startSearch());
        searchRow.add(searchField, BorderLayout.CENTER);
        searchRow.add(searchBtn,   BorderLayout.EAST);
        top.add(searchRow, BorderLayout.CENTER);

        // Options row
        JPanel optRow = new JPanel(new FlowLayout(FlowLayout.LEFT, 10, 0));
        randomModeChk     = new JCheckBox("Random probe mode");
        randomModeChk.setToolTipText(
            "Probe N random hashes per server instead of listing all files");
        randomCountSpinner = new JSpinner(new SpinnerNumberModel(50, 1, 10000, 10));
        randomCountSpinner.setEnabled(false);
        ((JSpinner.DefaultEditor) randomCountSpinner.getEditor()).getTextField().setColumns(6);
        JLabel spinLabel = new JLabel("tries per server:");
        spinLabel.setEnabled(false);
        randomModeChk.addItemListener(e -> {
            boolean on = randomModeChk.isSelected();
            randomCountSpinner.setEnabled(on);
            spinLabel.setEnabled(on);
        });
        optRow.add(randomModeChk);
        optRow.add(spinLabel);
        optRow.add(randomCountSpinner);
        top.add(optRow, BorderLayout.SOUTH);
        root.add(top, BorderLayout.NORTH);

        // -- Table -------------------------------------------------------------
        table = new JTable(tableModel);
        table.setSelectionMode(ListSelectionModel.MULTIPLE_INTERVAL_SELECTION);
        table.setRowHeight(24);
        table.setFont(new Font("SansSerif", Font.PLAIN, 13));
        table.getTableHeader().setFont(new Font("SansSerif", Font.BOLD, 13));
        table.setAutoCreateRowSorter(true);
        table.setFillsViewportHeight(true);
        table.setGridColor(Color.decode("#E5E7EB"));
        table.setShowGrid(true);

        int[] widths = {170, 160, 70, 80, 220, 140, 190, 0};
        for (int i = 0; i < widths.length; i++) {
            TableColumn col = table.getColumnModel().getColumn(i);
            col.setPreferredWidth(widths[i]);
            if (i == COL_HASH) { col.setMinWidth(0); col.setMaxWidth(0); col.setWidth(0); }
        }

        table.addMouseListener(new MouseAdapter() {
            @Override public void mouseClicked(MouseEvent e) {
                if (e.getClickCount() == 2) downloadSelected();
            }
        });

        JScrollPane scroll = new JScrollPane(table);
        scroll.setBorder(BorderFactory.createLineBorder(Color.decode("#D1D5DB")));
        root.add(scroll, BorderLayout.CENTER);

        // -- Bottom bar --------------------------------------------------------
        JPanel bottom = new JPanel(new BorderLayout(8, 4));
        JPanel btnBar = new JPanel(new FlowLayout(FlowLayout.LEFT, 10, 0));

        JButton dlAllBtn      = makeButton("? Download All (All Servers)",
            "Download every file from every server", Color.decode("#059669"));
        JButton dlMatchBtn    = makeButton("? Download Matches",
            "Download files matching current search", Color.decode("#7C3AED"));
        JButton dlSelectedBtn = makeButton("? Download Selected",
            "Download selected rows (or double-click a row)", Color.decode("#0891B2"));

        dlAllBtn.addActionListener(e      -> downloadAll(false));
        dlMatchBtn.addActionListener(e    -> downloadAll(true));
        dlSelectedBtn.addActionListener(e -> downloadSelected());

        btnBar.add(dlAllBtn);
        btnBar.add(dlMatchBtn);
        btnBar.add(dlSelectedBtn);
        bottom.add(btnBar, BorderLayout.WEST);

        progressBar = new JProgressBar();
        progressBar.setStringPainted(true);
        progressBar.setVisible(false);
        bottom.add(progressBar, BorderLayout.CENTER);

        statusLabel = new JLabel("Ready. " + servers.size() + " server(s) loaded.");
        statusLabel.setFont(new Font("SansSerif", Font.PLAIN, 12));
        statusLabel.setForeground(Color.decode("#6B7280"));
        bottom.add(statusLabel, BorderLayout.SOUTH);

        root.add(bottom, BorderLayout.SOUTH);

        // Opt #6 – batch EDT timer
        batchTimer = new javax.swing.Timer(BATCH_MS, e -> flushPendingToTable());
        batchTimer.setRepeats(true);
    }

    // -- Theme -----------------------------------------------------------------
    private void applyTheme() {
        try { UIManager.setLookAndFeel(UIManager.getSystemLookAndFeelClassName()); }
        catch (Exception ignored) {}
    }

    private JButton makeButton(String text, String tip, Color bg) {
        JButton b = new JButton(text);
        b.setToolTipText(tip);
        b.setBackground(bg);
        b.setForeground(Color.WHITE);
        b.setFocusPainted(false);
        b.setFont(new Font("SansSerif", Font.BOLD, 13));
        b.setBorder(new CompoundBorder(
            new LineBorder(bg.darker(), 1, true),
            new EmptyBorder(5, 14, 5, 14)));
        b.setCursor(Cursor.getPredefinedCursor(Cursor.HAND_CURSOR));
        return b;
    }

    // -------------------------------------------------------------------------
    // SEARCH
    // -------------------------------------------------------------------------
    private void startSearch() {
        if (searching) {
            // Cancel (opt #10 – null-safe guard)
            searching = false;
            if (searchExecutor != null && !searchExecutor.isShutdown())
                searchExecutor.shutdownNow();
            batchTimer.stop();
            flushPendingToTable(); // drain remainder
            searchBtn.setText("Search");
            setStatus("Search cancelled.");
            progressBar.setVisible(false);
            return;
        }

        // Opt #5 – pre-split query terms
        String raw = searchField.getText().trim().toLowerCase(Locale.ROOT);
        final String[] terms = raw.isEmpty() ? new String[0] : raw.split("\\s+");

        clearResults();
        searching = true;
        searchBtn.setText("Cancel");
        progressBar.setIndeterminate(true);
        progressBar.setVisible(true);
        setStatus("Searching " + servers.size() + " server(s)…");

        boolean randomMode = randomModeChk.isSelected();
        int randomTries    = (Integer) randomCountSpinner.getValue();

        // Opt #1 – I/O-bound pool size
        searchExecutor = Executors.newFixedThreadPool(
            Math.min(Math.max(servers.size(), 1) * 4, 64));
        AtomicInteger done = new AtomicInteger(0);

        batchTimer.start(); // opt #6

        for (String server : servers) {
            final String srv = server;
            searchExecutor.submit(() -> {
                try {
                    searchServer(srv, terms, randomMode, randomTries);
                } catch (Exception ex) {
                    SwingUtilities.invokeLater(() ->
                        setStatus("Error on " + srv + ": " + ex.getMessage()));
                } finally {
                    int d = done.incrementAndGet();
                    SwingUtilities.invokeLater(() -> {
                        setStatus("Searched " + d + "/" + servers.size() +
                            " server(s). " + resultCount.get() + " result(s) found.");
                        if (d == servers.size()) {
                            searching = false;
                            searchBtn.setText("Search");
                            progressBar.setVisible(false);
                            batchTimer.stop();
                            flushPendingToTable();
                        }
                    });
                }
            });
        }
        searchExecutor.shutdown();
    }

    /** Called on a worker thread – must NOT touch Swing components. */
    private void searchServer(String server, String[] terms,
                               boolean randomMode, int randomTries) {
        if (!searching) return;

        // Opt #9 – stream lines; random mode still needs extensions from real list
        List<String> hashNames;
        if (randomMode) {
            hashNames = buildRandomEntries(server, randomTries);
        } else {
            hashNames = streamFilesList(server);
        }
        if (hashNames == null || hashNames.isEmpty()) return;

        for (String entry : hashNames) {
            if (!searching || resultCount.get() >= MAX_RESULTS) return;

            String hash = stripExtension(entry);
            if (hash.isEmpty()) continue;

            String jsonStr = fetchText(server + "/info/" + hash + ".json");
            if (jsonStr == null || jsonStr.isEmpty()) continue;

            // Opt #4 – char[]-based parser
            Map<String, String> meta = parseJson(jsonStr);
            if (meta.isEmpty()) continue;

            if (!meta.containsKey("extension") || meta.get("extension").isEmpty()) {
                String ext = getExtension(entry);
                if (!ext.isEmpty()) meta.put("extension", ext.substring(1)); // strip leading dot
            }

            // Opt #5 – pre-split terms
            if (terms.length > 0 && !matchesTerms(meta, terms)) continue;

            // Opt #2 – lock-free enqueue
            int idx = resultCount.incrementAndGet();
            if (idx > MAX_RESULTS) { resultCount.decrementAndGet(); return; }
            pendingQueue.add(new FileEntry(server, hash, meta));
        }
    }

    // -------------------------------------------------------------------------
    // Opt #6 – batch flush: drain queue ? table model (EDT only)
    // -------------------------------------------------------------------------
    private void flushPendingToTable() {
        FileEntry fe;
        while ((fe = pendingQueue.poll()) != null) {
            resultList.add(fe);
            tableModel.addRow(toRow(fe));
        }
    }

    private Object[] toRow(FileEntry fe) {
        return new Object[]{
            fe.meta.getOrDefault("filename",    ""),
            fe.meta.getOrDefault("name",        ""),
            fe.meta.getOrDefault("extension",   ""),
            fe.meta.getOrDefault("size",        ""),
            fe.meta.getOrDefault("description", ""),
            fe.meta.getOrDefault("tags",        ""),
            fe.server,
            fe.hash
        };
    }

    // -------------------------------------------------------------------------
    // Opt #9 – stream files.txt line by line
    // -------------------------------------------------------------------------
    private List<String> streamFilesList(String server) {
        List<String> list = new ArrayList<>();
        HttpURLConnection conn = null;
        try {
            conn = openConnection(server + "/files.txt");
            if (conn.getResponseCode() != 200) return list;
            // Opt #3 – reuse the same BufferedReader stream
            try (BufferedReader br = new BufferedReader(
                    new InputStreamReader(conn.getInputStream(), "UTF-8"))) {
                String line;
                while ((line = br.readLine()) != null) {
                    line = line.trim();
                    if (!line.isEmpty()) list.add(line);
                }
            }
        } catch (Exception ignored) {
        } finally {
            if (conn != null) conn.disconnect();
        }
        return list;
    }

    // -------------------------------------------------------------------------
    // Opt #11 – random probe with cached extension list
    // -------------------------------------------------------------------------
    private List<String> buildRandomEntries(String server, int tries) {
        List<String> extensions = extCache.computeIfAbsent(server, srv -> {
            List<String> real = streamFilesList(srv);
            Set<String> seen = new LinkedHashSet<>();
            for (String e : real) {
                String ext = getExtension(e);
                if (!ext.isEmpty()) seen.add(ext);
            }
            List<String> exts = new ArrayList<>(seen);
            if (exts.isEmpty()) exts.add(".bin");
            return exts;
        });

        Random rng = new Random();
        List<String> out = new ArrayList<>(tries);
        char[] hexChars = "0123456789abcdef".toCharArray();
        char[] buf = new char[64];
        for (int i = 0; i < tries; i++) {
            for (int j = 0; j < 64; j++) buf[j] = hexChars[rng.nextInt(16)];
            out.add(new String(buf) + extensions.get(rng.nextInt(extensions.size())));
        }
        return out;
    }

    // -------------------------------------------------------------------------
    // DOWNLOAD
    // -------------------------------------------------------------------------
    private void downloadSelected() {
        int[] rows = table.getSelectedRows();
        if (rows.length == 0) {
            showError("No rows selected. Double-click a row or select rows first.");
            return;
        }
        List<FileEntry> toDownload = new ArrayList<>();
        for (int r : rows) {
            int model = table.convertRowIndexToModel(r);
            if (model < resultList.size()) toDownload.add(resultList.get(model));
        }
        startDownload(toDownload, "selected");
    }

    private void downloadAll(boolean matchOnly) {
        if (matchOnly) {
            if (resultList.isEmpty()) { showError("No search results to download."); return; }
            startDownload(new ArrayList<>(resultList), "matches");
        } else {
            int ans = JOptionPane.showConfirmDialog(this,
                "This will download ALL files from ALL " + servers.size() + " server(s).\nContinue?",
                "Confirm Download All", JOptionPane.YES_NO_OPTION);
            if (ans != JOptionPane.YES_OPTION) return;

            setStatus("Collecting file list from all servers…");
            progressBar.setIndeterminate(true);
            progressBar.setVisible(true);

            List<FileEntry> all = Collections.synchronizedList(new ArrayList<>());
            ExecutorService ex  = Executors.newFixedThreadPool(ioThreads());

            for (String srv : servers) {
                ex.submit(() -> {
                    List<String> entries = streamFilesList(srv);
                    for (String entry : entries) {
                        String hash = stripExtension(entry);
                        if (hash.isEmpty()) continue;
                        String json = fetchText(srv + "/info/" + hash + ".json");
                        Map<String, String> meta = json != null ? parseJson(json) : new LinkedHashMap<String, String>();
                        if (!meta.containsKey("extension") || meta.get("extension").isEmpty()) {
                            String ext = getExtension(entry);
                            if (!ext.isEmpty()) meta.put("extension", ext.substring(1));
                        }
                        all.add(new FileEntry(srv, hash, meta));
                    }
                });
            }
            ex.shutdown();
            new Thread(() -> {
                try { ex.awaitTermination(10, TimeUnit.MINUTES); }
                catch (InterruptedException ignored) {}
                SwingUtilities.invokeLater(() -> {
                    progressBar.setVisible(false);
                    startDownload(all, "all");
                });
            }, "meshare-collect").start();
        }
    }

    private void startDownload(List<FileEntry> entries, String label) {
        if (entries.isEmpty()) { showError("Nothing to download."); return; }

        JFileChooser fc = new JFileChooser();
        fc.setFileSelectionMode(JFileChooser.DIRECTORIES_ONLY);
        fc.setDialogTitle("Choose download destination folder");
        if (fc.showOpenDialog(this) != JFileChooser.APPROVE_OPTION) return;
        File dest = fc.getSelectedFile();

        progressBar.setIndeterminate(false);
        progressBar.setMaximum(entries.size());
        progressBar.setValue(0);
        progressBar.setVisible(true);
        setStatus("Downloading " + entries.size() + " file(s) [" + label + "]…");

        AtomicInteger count = new AtomicInteger(0);
        AtomicInteger ok    = new AtomicInteger(0);

        ExecutorService dlEx = Executors.newFixedThreadPool(
            Math.min(ioThreads(), entries.size()));

        for (FileEntry fe : entries) {
            dlEx.submit(() -> {
                boolean success = downloadEntry(fe, dest);
                if (success) ok.incrementAndGet();
                int c = count.incrementAndGet();
                SwingUtilities.invokeLater(() -> {
                    progressBar.setValue(c);
                    setStatus("Downloaded " + c + "/" + entries.size() + " (" + label + ")");
                    if (c == entries.size()) {
                        progressBar.setVisible(false);
                        setStatus("Done. " + ok.get() + "/" + entries.size() + " downloaded OK.");
                    }
                });
            });
        }
        dlEx.shutdown();
    }

    private boolean downloadEntry(FileEntry fe, File destRoot) {
        String ext = fe.meta.getOrDefault("extension", "bin");
        if (ext.startsWith(".")) ext = ext.substring(1);
        String fileFilename = fe.hash + (ext.isEmpty() ? "" : "." + ext);
        String infoFilename = fe.hash + ".json";

        String serverFolder = fe.server.replaceAll("[^a-zA-Z0-9._-]", "_");
        File filesDir = new File(destRoot, serverFolder + "/files");
        File infoDir  = new File(destRoot, serverFolder + "/info");
        filesDir.mkdirs();
        infoDir.mkdirs();

        boolean okFile = downloadBinaryWithRetry(fe.server + "/files/" + fileFilename,
                                                  new File(filesDir, fileFilename));
        boolean okInfo = downloadBinaryWithRetry(fe.server + "/info/"  + infoFilename,
                                                  new File(infoDir,  infoFilename));
        if (okFile && okInfo) writeDownloadLog(fe, fileFilename);
        return okFile && okInfo;
    }

    // -------------------------------------------------------------------------
    // Opt #7 – retry with back-off + Range header resume
    // -------------------------------------------------------------------------
    private boolean downloadBinaryWithRetry(String urlStr, File dest) {
        for (int attempt = 1; attempt <= DL_RETRIES; attempt++) {
            long existing = dest.exists() ? dest.length() : 0L;
            try {
                HttpURLConnection conn = openConnection(urlStr);
                if (existing > 0) conn.setRequestProperty("Range", "bytes=" + existing + "-");
                int code = conn.getResponseCode();
                // 206 = partial, 200 = full, 416 = already complete
                if (code == 416) return true; // server says we have it all
                if (code != 200 && code != 206) { conn.disconnect(); backOff(attempt); continue; }

                try (InputStream  in  = conn.getInputStream();
                     OutputStream out = new FileOutputStream(dest, code == 206)) {
                    byte[] buf = new byte[16_384]; // 16 KB buffer
                    int n;
                    while ((n = in.read(buf)) != -1) out.write(buf, 0, n);
                }
                conn.disconnect();
                return true;
            } catch (Exception e) {
                backOff(attempt);
            }
        }
        return false;
    }

    private void backOff(int attempt) {
        try { Thread.sleep(200L * (1 << (attempt - 1))); } // 200, 400, 800 ms
        catch (InterruptedException ie) { Thread.currentThread().interrupt(); }
    }

    // -------------------------------------------------------------------------
    // Opt #8 – shared toJson helper used by log writer
    // -------------------------------------------------------------------------
    private String toJson(Map<String, String> map) {
        StringBuilder sb = new StringBuilder("{\n");
        List<Map.Entry<String, String>> entries = new ArrayList<>(map.entrySet());
        for (int i = 0; i < entries.size(); i++) {
            sb.append("  ").append(jsonString(entries.get(i).getKey()))
              .append(": ").append(jsonString(entries.get(i).getValue()));
            if (i < entries.size() - 1) sb.append(",");
            sb.append("\n");
        }
        return sb.append("}").toString();
    }

    private void writeDownloadLog(FileEntry fe, String filename) {
        try {
            new File(LOG_DIR).mkdirs();
            String date = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssZ").format(new Date());
            Map<String, String> log = new LinkedHashMap<>();
            log.put("filename",     filename);
            log.put("hash",         fe.hash);
            log.put("server",       fe.server);
            log.put("downloadDate", date);
            // embed meta as nested JSON string (valid enough for log purposes)
            log.put("_meta", toJson(fe.meta));
            try (FileWriter fw = new FileWriter(new File(LOG_DIR, filename + ".json"))) {
                fw.write(toJson(log));
            }
        } catch (Exception ignored) {}
    }

    // -------------------------------------------------------------------------
    // Network helpers
    // -------------------------------------------------------------------------
    private HttpURLConnection openConnection(String urlStr) throws IOException {
        HttpURLConnection conn = (HttpURLConnection) new URL(urlStr).openConnection();
        conn.setConnectTimeout(CONNECT_TIMEOUT);
        conn.setReadTimeout(READ_TIMEOUT);
        conn.setRequestProperty("User-Agent", "Meshare/1.0");
        conn.setRequestProperty("Connection", "keep-alive"); // opt #3
        return conn;
    }

    private String fetchText(String urlStr) {
        HttpURLConnection conn = null;
        try {
            conn = openConnection(urlStr);
            if (conn.getResponseCode() != 200) return null;
            // Opt #3 – stream directly; no ByteArrayOutputStream round-trip for text
            try (BufferedReader br = new BufferedReader(
                    new InputStreamReader(conn.getInputStream(), "UTF-8"))) {
                StringBuilder sb = new StringBuilder(512);
                char[] buf = new char[2048];
                int n;
                while ((n = br.read(buf)) != -1) sb.append(buf, 0, n);
                return sb.toString();
            }
        } catch (Exception e) {
            return null;
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Opt #4 – JSON parser on char[] – no substring allocation for scanning
    // -------------------------------------------------------------------------
    private Map<String, String> parseJson(String json) {
        Map<String, String> map = new LinkedHashMap<>();
        if (json == null) return map;
        char[] s = json.toCharArray();
        int len = s.length;
        int i = 0;
        // skip to opening brace
        while (i < len && s[i] != '{') i++;
        if (i >= len) return map;
        i++; // consume '{'

        while (i < len) {
            while (i < len && s[i] <= ' ') i++;
            if (i >= len || s[i] == '}') break;
            if (s[i] != '"') { i++; continue; }

            // read key
            int keyStart = ++i;
            while (i < len && !(s[i] == '"' && s[i-1] != '\\')) i++;
            String key = new String(s, keyStart, i - keyStart);
            i++; // closing quote

            // skip to value
            while (i < len && (s[i] <= ' ' || s[i] == ':')) i++;

            // read value
            String value;
            if (i < len && s[i] == '"') {
                int valStart = ++i;
                while (i < len && !(s[i] == '"' && s[i-1] != '\\')) i++;
                value = new String(s, valStart, i - valStart);
                i++;
            } else {
                int valStart = i;
                int depth = 0;
                while (i < len) {
                    if (s[i] == '{' || s[i] == '[') depth++;
                    else if (s[i] == '}' || s[i] == ']') { if (depth == 0) break; depth--; }
                    else if (s[i] == ',' && depth == 0) break;
                    i++;
                }
                value = new String(s, valStart, i - valStart).trim();
            }

            value = unescape(value);
            if (value.length() > MAX_FIELD_LEN) value = value.substring(0, MAX_FIELD_LEN);
            if (!key.isEmpty()) map.put(unescape(key), value);

            while (i < len && (s[i] <= ' ' || s[i] == ',')) i++;
        }
        return map;
    }

    private String unescape(String s) {
        if (s.indexOf('\\') < 0) return s; // fast path – nothing to escape
        return s.replace("\\\"", "\"")
                .replace("\\\\", "\\")
                .replace("\\n",  "\n")
                .replace("\\r",  "\r")
                .replace("\\t",  "\t");
    }

    // -------------------------------------------------------------------------
    // Opt #5 – multi-term matching (all terms must match at least one field)
    // -------------------------------------------------------------------------
    private boolean matchesTerms(Map<String, String> meta, String[] terms) {
        // Build concatenated lower-case blob once
        StringBuilder blob = new StringBuilder();
        for (String v : meta.values()) blob.append(v.toLowerCase(Locale.ROOT)).append(' ');
        String text = blob.toString();
        for (String term : terms) {
            if (!text.contains(term)) return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------
    private void clearResults() {
        pendingQueue.clear();
        resultList.clear();
        resultCount.set(0);
        tableModel.setRowCount(0);
        setStatus("Ready.");
    }

    private void setStatus(String msg) { statusLabel.setText(msg); }

    private void showError(String msg) {
        JOptionPane.showMessageDialog(this, msg, "Meshare", JOptionPane.WARNING_MESSAGE);
    }

    // -------------------------------------------------------------------------
    // String utilities
    // -------------------------------------------------------------------------
    private String stripExtension(String filename) {
        int dot = filename.lastIndexOf('.');
        return dot < 0 ? filename : filename.substring(0, dot);
    }

    private String getExtension(String filename) {
        int dot = filename.lastIndexOf('.');
        return dot < 0 ? "" : filename.substring(dot); // includes leading dot
    }

    private String jsonString(String s) {
        if (s == null) return "null";
        return '"' + s.replace("\\", "\\\\")
                       .replace("\"", "\\\"")
                       .replace("\n", "\\n")
                       .replace("\r", "\\r")
                       .replace("\t", "\\t") + '"';
    }

    // -------------------------------------------------------------------------
    // Data model
    // -------------------------------------------------------------------------
    private static final class FileEntry {
        final String              server;
        final String              hash;
        final Map<String, String> meta;

        FileEntry(String server, String hash, Map<String, String> meta) {
            this.server = server;
            this.hash   = hash;
            this.meta   = meta;
        }
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------
    public static void main(String[] args) {
        SwingUtilities.invokeLater(Meshare::new);
    }
}