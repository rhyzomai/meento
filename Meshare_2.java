import javax.swing.BorderFactory;
import javax.swing.Box;
import javax.swing.BoxLayout;
import javax.swing.JButton;
import javax.swing.JCheckBox;
import javax.swing.JFileChooser;
import javax.swing.JFrame;
import javax.swing.JLabel;
import javax.swing.JOptionPane;
import javax.swing.JPanel;
import javax.swing.JProgressBar;
import javax.swing.JScrollPane;
import javax.swing.JSeparator;
import javax.swing.JSplitPane;
import javax.swing.JTable;
import javax.swing.JTextField;
import javax.swing.ListSelectionModel;
import javax.swing.SwingConstants;
import javax.swing.SwingUtilities;
import javax.swing.SwingWorker;
import javax.swing.UIManager;
import javax.swing.border.EmptyBorder;
import javax.swing.border.TitledBorder;
import javax.swing.table.DefaultTableCellRenderer;
import javax.swing.table.DefaultTableModel;
import javax.swing.table.JTableHeader;
import javax.swing.table.TableColumnModel;
import javax.swing.table.TableRowSorter;
import java.awt.BorderLayout;
import java.awt.Color;
import java.awt.Component;
import java.awt.Cursor;
import java.awt.Desktop;
import java.awt.Dimension;
import java.awt.FlowLayout;
import java.awt.Font;
import java.awt.GridBagConstraints;
import java.awt.GridBagLayout;
import java.awt.Insets;
import java.awt.Toolkit;
import java.awt.datatransfer.StringSelection;
import java.awt.event.ActionEvent;
import java.awt.event.ActionListener;
import java.awt.event.KeyAdapter;
import java.awt.event.KeyEvent;
import java.awt.event.MouseAdapter;
import java.awt.event.MouseEvent;
import java.awt.event.WindowAdapter;
import java.awt.event.WindowEvent;
import java.io.BufferedInputStream;
import java.io.BufferedReader;
import java.io.BufferedWriter;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.FileWriter;
import java.io.Closeable;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.io.OutputStreamWriter;
import java.io.PrintWriter;
import java.io.RandomAccessFile;
import java.io.StringWriter;
import java.net.HttpURLConnection;
import java.net.URI;
import java.net.URL;
import java.net.URLConnection;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Random;
import java.util.TimeZone;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.ThreadFactory;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/*
 * Meshare - decentralized file index browser
 *
 * Reads servers.txt, fetches files.txt from each server, then fetches
 * the JSON metadata from each server's info/ folder. Results are
 * streamed into the table as they arrive. Supports search, single
 * click download, "download all", and "download matched".
 *
 * Java 8 compatible, no external libraries.
 */
public class Meshare extends JFrame {

    private static final long serialVersionUID = 1L;

    // ==================== Constants ====================
    private static final int MAX_RESULTS = 200;
    private static final int MAX_FIELD_LENGTH = 512;
    private static final int MAX_PARALLEL_FETCH = 16;
    private static final int MAX_PARALLEL_DOWNLOAD = 4;
    private static final int CONNECT_TIMEOUT_MS = 8000;
    private static final int READ_TIMEOUT_MS = 20000;
    private static final int MAX_DOWNLOAD_BYTES = 200 * 1024 * 1024; // 200 MB safety cap per file
    private static final String SERVERS_FILE = "servers.txt";
    private static final String DOWNLOAD_SUBDIR = "downloads";
    private static final String LOG_SUBDIR = "log";

    // ==================== Inner data model ====================
    static class FileEntry {
        final String serverBase;       // normalized base URL, ends with '/'
        final String serverDisplay;    // what to show in the Server column
        final String hash;             // sha256 hex
        final String originalName;     // name as it appears in files.txt (hash.ext)
        String extension;              // from JSON (preferred) or from files.txt
        String filename = "";          // human-friendly name from JSON
        long size = -1;
        String publicKey = "";
        String serverPublicKey = "";
        String nodeInfo = "";
        String description = "";
        String date = "";
        // Raw key/value pairs from the JSON, truncated to MAX_FIELD_LENGTH each.
        final Map<String, String> fields = new LinkedHashMap<String, String>();
        // Cached combined search text
        String searchHaystack = "";
        // Files.txt line used to download (may differ from JSON "extension")
        String filesLineName = "";

        FileEntry(String serverBase, String serverDisplay, String hash, String originalName) {
            this.serverBase = serverBase;
            this.serverDisplay = serverDisplay;
            this.hash = hash;
            this.originalName = originalName;
            int dot = originalName.lastIndexOf('.');
            this.extension = dot > 0 ? originalName.substring(dot + 1) : "";
            this.filesLineName = originalName;
        }

        void rebuildSearchHaystack() {
            StringBuilder sb = new StringBuilder();
            sb.append(serverDisplay).append(' ').append(serverBase).append(' ');
            sb.append(filename).append(' ');
            sb.append(description).append(' ');
            sb.append(extension).append(' ');
            sb.append(publicKey).append(' ');
            sb.append(serverPublicKey).append(' ');
            sb.append(nodeInfo).append(' ');
            sb.append(date).append(' ');
            sb.append(hash).append(' ');
            for (Map.Entry<String, String> e : fields.entrySet()) {
                sb.append(e.getKey()).append(' ').append(e.getValue()).append(' ');
            }
            searchHaystack = sb.toString().toLowerCase(Locale.ROOT);
        }
    }

    // ==================== UI components ====================
    private JTextField searchField;
    private JButton searchButton;
    private JButton reloadServersButton;
    private JButton downloadAllButton;
    private JButton downloadMatchedButton;
    private JButton cancelButton;
    private JCheckBox randomTriesCheckBox;
    private JTextField randomTriesField;
    private JTextField outputDirField;
    private JButton chooseOutputDirButton;
    private JLabel statusLabel;
    private JLabel countLabel;
    private JTable resultsTable;
    private DefaultTableModel tableModel;
    private TableRowSorter<DefaultTableModel> sorter;
    private JProgressBar progressBar;

    // ==================== State ====================
    private final List<FileEntry> allEntries =
            Collections.synchronizedList(new ArrayList<FileEntry>());
    private final List<String> servers =
            Collections.synchronizedList(new ArrayList<String>());
    private ExecutorService fetchExecutor;
    private ExecutorService downloadExecutor;
    private final AtomicInteger activeFetches = new AtomicInteger(0);
    private final AtomicBoolean loading = new AtomicBoolean(false);
    private final AtomicBoolean cancelRequested = new AtomicBoolean(false);
    private final AtomicBoolean downloadInProgress = new AtomicBoolean(false);

    private final String baseDir;
    private String outputDir; // root for downloads/log

    private static final String[] TABLE_COLUMNS = new String[]{
            "Filename", "Description", "Size", "Ext", "Date", "Server"
    };

    // ==================== Constructor ====================
    public Meshare() {
        super("Meshare");
        setDefaultCloseOperation(JFrame.DISPOSE_ON_CLOSE);
        setSize(1180, 720);
        setMinimumSize(new Dimension(900, 520));
        setLocationRelativeTo(null);

        baseDir = System.getProperty("user.dir");
        outputDir = baseDir;

        fetchExecutor = Executors.newFixedThreadPool(MAX_PARALLEL_FETCH, daemonFactory("meshare-fetch"));
        downloadExecutor = Executors.newFixedThreadPool(MAX_PARALLEL_DOWNLOAD, daemonFactory("meshare-dl"));

        initUi();
        wireWindow();
        // Defer first load so the window has time to show.
        SwingUtilities.invokeLater(new Runnable() {
            public void run() { reloadServers(); }
        });
    }

    private static ThreadFactory daemonFactory(final String prefix) {
        return new ThreadFactory() {
            private int id = 0;
            public Thread newThread(Runnable r) {
                Thread t = new Thread(r, prefix + "-" + (id++));
                t.setDaemon(true);
                return t;
            }
        };
    }

    // ==================== UI setup ====================
    private void initUi() {
        JPanel root = new JPanel(new BorderLayout(0, 0));
        root.setBorder(new EmptyBorder(8, 8, 8, 8));
        setContentPane(root);

        // --- Top: search row ---
        JPanel searchRow = new JPanel(new BorderLayout(6, 0));
        JLabel searchLabel = new JLabel("Search:");
        searchLabel.setFont(searchLabel.getFont().deriveFont(Font.BOLD));
        searchField = new JTextField();
        searchField.setToolTipText("Type to filter across filename, description, keys, date, server, etc.");
        searchButton = new JButton("Search");
        reloadServersButton = new JButton("Reload servers");
        searchRow.add(searchLabel, BorderLayout.WEST);
        searchRow.add(searchField, BorderLayout.CENTER);
        JPanel searchButtons = new JPanel(new FlowLayout(FlowLayout.RIGHT, 4, 0));
        searchButtons.add(reloadServersButton);
        searchButtons.add(searchButton);
        searchRow.add(searchButtons, BorderLayout.EAST);

        // --- Options row ---
        JPanel optionsRow = new JPanel();
        optionsRow.setLayout(new BoxLayout(optionsRow, BoxLayout.LINE_AXIS));
        randomTriesCheckBox = new JCheckBox("Random tries per server");
        randomTriesCheckBox.setSelected(false);
        randomTriesCheckBox.setToolTipText("If checked, randomly sample N files from each server's files.txt instead of fetching all of them.");
        randomTriesField = new JTextField("20", 5);
        randomTriesField.setMaximumSize(new Dimension(80, 26));
        randomTriesField.setEnabled(false);
        randomTriesCheckBox.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                randomTriesField.setEnabled(randomTriesCheckBox.isSelected());
            }
        });
        optionsRow.add(randomTriesCheckBox);
        optionsRow.add(Box.createHorizontalStrut(4));
        optionsRow.add(new JLabel("N:"));
        optionsRow.add(Box.createHorizontalStrut(4));
        optionsRow.add(randomTriesField);
        optionsRow.add(Box.createHorizontalStrut(16));
        JLabel outLabel = new JLabel("Output dir:");
        optionsRow.add(outLabel);
        optionsRow.add(Box.createHorizontalStrut(4));
        outputDirField = new JTextField(outputDir);
        outputDirField.setMaximumSize(new Dimension(360, 26));
        optionsRow.add(outputDirField);
        optionsRow.add(Box.createHorizontalStrut(4));
        chooseOutputDirButton = new JButton("...");
        chooseOutputDirButton.setMargin(new Insets(2, 6, 2, 6));
        chooseOutputDirButton.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) { chooseOutputDir(); }
        });
        optionsRow.add(chooseOutputDirButton);
        optionsRow.add(Box.createHorizontalGlue());

        JPanel topPanel = new JPanel();
        topPanel.setLayout(new BoxLayout(topPanel, BoxLayout.PAGE_AXIS));
        topPanel.add(searchRow);
        topPanel.add(Box.createVerticalStrut(6));
        topPanel.add(optionsRow);
        topPanel.setBorder(BorderFactory.createTitledBorder(
                BorderFactory.createEtchedBorder(), "Search & Options",
                TitledBorder.LEADING, TitledBorder.TOP));

        // --- Center: results table ---
        tableModel = new DefaultTableModel(TABLE_COLUMNS, 0) {
            private static final long serialVersionUID = 1L;
            public boolean isCellEditable(int row, int column) { return false; }
            public Class<?> getColumnClass(int columnIndex) {
                if (columnIndex == 2) return Long.class; // Size
                return String.class;
            }
        };
        resultsTable = new JTable(tableModel);
        resultsTable.setAutoCreateRowSorter(true);
        resultsTable.setSelectionMode(ListSelectionModel.SINGLE_SELECTION);
        resultsTable.setRowHeight(22);
        resultsTable.setShowGrid(false);
        resultsTable.setIntercellSpacing(new Dimension(0, 0));
        resultsTable.setFillsViewportHeight(true);
        JTableHeader th = resultsTable.getTableHeader();
        th.setFont(th.getFont().deriveFont(Font.BOLD));

        // Right-align the Size column
        DefaultTableCellRenderer rightRenderer = new DefaultTableCellRenderer();
        rightRenderer.setHorizontalAlignment(SwingConstants.RIGHT);
        resultsTable.getColumnModel().getColumn(2).setCellRenderer(rightRenderer);

        // Set widths
        TableColumnModel cm = resultsTable.getColumnModel();
        cm.getColumn(0).setPreferredWidth(260); // Filename
        cm.getColumn(1).setPreferredWidth(320); // Description
        cm.getColumn(2).setPreferredWidth(90);  // Size
        cm.getColumn(3).setPreferredWidth(50);  // Ext
        cm.getColumn(4).setPreferredWidth(170); // Date
        cm.getColumn(5).setPreferredWidth(180); // Server

        // Row sorter for live filter
        sorter = new TableRowSorter<DefaultTableModel>(tableModel);
        resultsTable.setRowSorter(sorter);

        // Tooltip showing full description / filename on hover
        resultsTable.addMouseMotionListener(new MouseAdapter() {
            public void mouseMoved(MouseEvent e) {
                int vrow = resultsTable.rowAtPoint(e.getPoint());
                if (vrow < 0) { resultsTable.setToolTipText(null); return; }
                int mrow = resultsTable.convertRowIndexToModel(vrow);
                if (mrow < 0 || mrow >= tableModel.getRowCount()) return;
                String fn = (String) tableModel.getValueAt(mrow, 0);
                String desc = (String) tableModel.getValueAt(mrow, 1);
                resultsTable.setToolTipText("<html><b>File:</b> " + escapeHtml(fn)
                        + "<br><b>Description:</b> " + escapeHtml(desc) + "</html>");
            }
        });

        // Double click to download the underlying file
        resultsTable.addMouseListener(new MouseAdapter() {
            public void mouseClicked(MouseEvent e) {
                if (e.getClickCount() == 2 && SwingUtilities.isLeftMouseButton(e)) {
                    int vrow = resultsTable.getSelectedRow();
                    if (vrow < 0) return;
                    int mrow = resultsTable.convertRowIndexToModel(vrow);
                    FileEntry fe = entryAtModelRow(mrow);
                    if (fe != null) downloadOneEntry(fe, true);
                }
            }
        });

        JScrollPane tableScroll = new JScrollPane(resultsTable);
        tableScroll.setBorder(BorderFactory.createTitledBorder(
                BorderFactory.createEtchedBorder(), "Results (max " + MAX_RESULTS + ")",
                TitledBorder.LEADING, TitledBorder.TOP));

        // --- Bottom: action buttons + status ---
        JPanel actionRow = new JPanel(new FlowLayout(FlowLayout.LEFT, 6, 4));
        downloadAllButton = new JButton("Download ALL (files + info) from all servers");
        downloadMatchedButton = new JButton("Download MATCHED (files + info)");
        cancelButton = new JButton("Cancel");
        cancelButton.setEnabled(false);
        actionRow.add(downloadAllButton);
        actionRow.add(downloadMatchedButton);
        actionRow.add(cancelButton);
        actionRow.add(Box.createHorizontalStrut(20));
        JLabel hint = new JLabel("Double-click a row to download that file. Right-click for more.");
        hint.setForeground(Color.DARK_GRAY);
        actionRow.add(hint);

        JPanel statusRow = new JPanel(new BorderLayout(8, 0));
        statusLabel = new JLabel("Ready.");
        countLabel = new JLabel("0 results");
        progressBar = new JProgressBar();
        progressBar.setIndeterminate(true);
        progressBar.setVisible(false);
        progressBar.setPreferredSize(new Dimension(180, 16));
        statusRow.add(statusLabel, BorderLayout.WEST);
        statusRow.add(progressBar, BorderLayout.CENTER);
        statusRow.add(countLabel, BorderLayout.EAST);

        JPanel southPanel = new JPanel();
        southPanel.setLayout(new BoxLayout(southPanel, BoxLayout.PAGE_AXIS));
        southPanel.add(actionRow);
        southPanel.add(statusRow);

        // Assemble
        root.add(topPanel, BorderLayout.NORTH);
        root.add(tableScroll, BorderLayout.CENTER);
        root.add(southPanel, BorderLayout.SOUTH);

        // --- Listeners ---
        ActionListener doSearch = new ActionListener() {
            public void actionPerformed(ActionEvent e) { applyFilter(); }
        };
        searchButton.addActionListener(doSearch);
        searchField.addActionListener(doSearch);
        searchField.getDocument().addDocumentListener(new javax.swing.event.DocumentListener() {
            public void insertUpdate(javax.swing.event.DocumentEvent e) { applyFilter(); }
            public void removeUpdate(javax.swing.event.DocumentEvent e) { applyFilter(); }
            public void changedUpdate(javax.swing.event.DocumentEvent e) { applyFilter(); }
        });
        reloadServersButton.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) { reloadServers(); }
        });
        downloadAllButton.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) { startDownloadAll(); }
        });
        downloadMatchedButton.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) { startDownloadMatched(); }
        });
        cancelButton.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) { requestCancel(); }
        });

        // Right-click menu on table
        javax.swing.JPopupMenu popup = new javax.swing.JPopupMenu();
        javax.swing.JMenuItem miDownload = new javax.swing.JMenuItem("Download this file");
        javax.swing.JMenuItem miDownloadInfo = new javax.swing.JMenuItem("Download this info (.json)");
        javax.swing.JMenuItem miCopyHash = new javax.swing.JMenuItem("Copy hash");
        javax.swing.JMenuItem miCopyServer = new javax.swing.JMenuItem("Copy server");
        javax.swing.JMenuItem miOpenInBrowser = new javax.swing.JMenuItem("Open file URL in browser");
        popup.add(miDownload);
        popup.add(miDownloadInfo);
        popup.addSeparator();
        popup.add(miCopyHash);
        popup.add(miCopyServer);
        popup.add(miOpenInBrowser);
        resultsTable.setComponentPopupMenu(popup);
        miDownload.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                FileEntry fe = selectedEntry();
                if (fe != null) downloadOneEntry(fe, true);
            }
        });
        miDownloadInfo.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                FileEntry fe = selectedEntry();
                if (fe != null) downloadInfoOnly(fe);
            }
        });
        miCopyHash.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                FileEntry fe = selectedEntry();
                if (fe != null) copyToClipboard(fe.hash);
            }
        });
        miCopyServer.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                FileEntry fe = selectedEntry();
                if (fe != null) copyToClipboard(fe.serverBase);
            }
        });
        miOpenInBrowser.addActionListener(new ActionListener() {
            public void actionPerformed(ActionEvent e) {
                FileEntry fe = selectedEntry();
                if (fe != null) openInBrowser(fileUrl(fe));
            }
        });
    }

    private void wireWindow() {
        addWindowListener(new WindowAdapter() {
            public void windowClosing(WindowEvent e) {
                shutdownExecutors();
            }
            public void windowClosed(WindowEvent e) {
                shutdownExecutors();
            }
        });
    }

    private void shutdownExecutors() {
        try { fetchExecutor.shutdownNow(); } catch (Exception ignored) {}
        try { downloadExecutor.shutdownNow(); } catch (Exception ignored) {}
    }

    // ==================== Server loading ====================
    private void reloadServers() {
        if (loading.get()) {
            setStatus("A load is already running.");
            return;
        }
        // Cancel any in-flight work from a previous load
        cancelRequested.set(true);
        try { fetchExecutor.shutdownNow(); } catch (Exception ignored) {}
        fetchExecutor = Executors.newFixedThreadPool(MAX_PARALLEL_FETCH, daemonFactory("meshare-fetch"));

        allEntries.clear();
        tableModel.setRowCount(0);
        cancelRequested.set(false);
        loading.set(true);
        progressBar.setVisible(true);
        cancelButton.setEnabled(true);
        reloadServersButton.setEnabled(false);
        downloadAllButton.setEnabled(false);
        downloadMatchedButton.setEnabled(false);

        // Read servers.txt
        final List<String> list = readServersTxt();
        servers.clear();
        servers.addAll(list);
        setStatus("Loaded " + list.size() + " server(s) from " + SERVERS_FILE + " ...");
        if (list.isEmpty()) {
            finishLoading();
            return;
        }

        // For each server, kick off a fetcher
        for (final String raw : list) {
            fetchExecutor.submit(new Runnable() {
                public void run() { fetchServer(raw); }
            });
        }

        // Watcher: when activeFetches drops to 0, finish.
        Thread watcher = new Thread(new Runnable() {
            public void run() {
                try {
                    while (activeFetches.get() > 0) {
                        Thread.sleep(150);
                        if (Thread.currentThread().isInterrupted()) return;
                    }
                } catch (InterruptedException e) {
                    return;
                }
                SwingUtilities.invokeLater(new Runnable() {
                    public void run() { finishLoading(); }
                });
            }
        }, "meshare-watcher");
        watcher.setDaemon(true);
        watcher.start();
    }

    private void finishLoading() {
        loading.set(false);
        progressBar.setVisible(false);
        cancelButton.setEnabled(false);
        reloadServersButton.setEnabled(true);
        downloadAllButton.setEnabled(true);
        downloadMatchedButton.setEnabled(true);
        applyFilter();
        setStatus("Done. " + allEntries.size() + " entries indexed from " + servers.size() + " server(s).");
    }

    private List<String> readServersTxt() {
        File f = new File(baseDir, SERVERS_FILE);
        List<String> out = new ArrayList<String>();
        if (!f.isFile()) {
            setStatus(SERVERS_FILE + " not found in " + baseDir);
            return out;
        }
        BufferedReader br = null;
        try {
            br = new BufferedReader(new InputStreamReader(new FileInputStream(f), StandardCharsets.UTF_8));
            String line;
            while ((line = br.readLine()) != null) {
                String t = line.trim();
                if (t.isEmpty() || t.startsWith("#")) continue;
                out.add(t);
            }
        } catch (IOException e) {
            setStatus("Failed to read " + SERVERS_FILE + ": " + e.getMessage());
        } finally {
            closeQuiet(br);
        }
        return out;
    }

    // ==================== Per-server fetch ====================
    private void fetchServer(String rawUrl) {
        if (cancelRequested.get()) return;
        activeFetches.incrementAndGet();
        try {
            String base = normalizeBase(rawUrl);
            String display = rawUrl;
            // Fetch files.txt
            String filesTxtUrl = base + "files.txt";
            String body;
            try {
                body = httpGetString(filesTxtUrl, CONNECT_TIMEOUT_MS, READ_TIMEOUT_MS);
            } catch (IOException e) {
                setStatus("[" + display + "] files.txt failed: " + e.getMessage());
                return;
            }
            if (body == null) body = "";
            // Parse lines
            String[] lines = body.split("\\r?\\n");
            List<String> fileNames = new ArrayList<String>();
            for (String l : lines) {
                String t = l.trim();
                if (t.isEmpty() || t.startsWith("#")) continue;
                fileNames.add(t);
            }
            if (fileNames.isEmpty()) {
                setStatus("[" + display + "] no entries in files.txt");
                return;
            }
            // Random tries?
            int total = fileNames.size();
            int tries = total;
            if (randomTriesCheckBox.isSelected()) {
                int n = parsePositiveInt(randomTriesField.getText(), total);
                if (n < total) {
                    Collections.shuffle(fileNames, new Random(System.nanoTime() ^ rawUrl.hashCode()));
                    tries = n;
                    fileNames = new ArrayList<String>(fileNames.subList(0, n));
                }
            }
            setStatus("[" + display + "] " + total + " file(s); sampling " + tries + " ...");
            int dispatched = 0;
            for (String fn : fileNames) {
                if (cancelRequested.get()) break;
                // If we've hit MAX_RESULTS, stop dispatching more
                if (allEntries.size() >= MAX_RESULTS) break;
                final String fileName = fn;
                final String sbase = base;
                final String sdisplay = display;
                fetchExecutor.submit(new Runnable() {
                    public void run() { fetchEntry(sbase, sdisplay, fileName); }
                });
                dispatched++;
            }
            setStatus("[" + display + "] dispatched " + dispatched + " fetch job(s)");
        } finally {
            activeFetches.decrementAndGet();
        }
    }

    private void fetchEntry(String base, String display, String filesLineName) {
        if (cancelRequested.get()) return;
        if (allEntries.size() >= MAX_RESULTS) return;
        // Parse hash from "hash.ext"
        String hash;
        int dot = filesLineName.lastIndexOf('.');
        if (dot <= 0) {
            // No extension: just use the whole thing as hash
            hash = filesLineName;
        } else {
            hash = filesLineName.substring(0, dot);
        }
        if (hash.isEmpty()) return;
        // Fetch info JSON
        String infoUrl = base + "info/" + hash + ".json";
        String json;
        try {
            json = httpGetString(infoUrl, CONNECT_TIMEOUT_MS, READ_TIMEOUT_MS);
        } catch (IOException e) {
            return; // silent: this is normal for orphan entries
        }
        if (json == null || json.isEmpty()) return;

        Map<String, String> flat;
        try {
            flat = parseFlatJson(json);
        } catch (Exception e) {
            return; // malformed
        }
        if (flat.isEmpty()) return;

        final FileEntry fe = new FileEntry(base, display, hash, filesLineName);
        fe.fields.putAll(flat);
        fe.filename = truncate(flat.get("filename"));
        if (fe.filename.isEmpty()) fe.filename = fe.originalName;
        fe.extension = firstNonEmpty(flat.get("extension"), fe.extension);
        fe.publicKey = truncate(flat.get("public_key"));
        fe.serverPublicKey = truncate(flat.get("server_public_key"));
        fe.nodeInfo = truncate(flat.get("node_info"));
        fe.description = truncate(flat.get("description"));
        fe.date = truncate(flat.get("date"));
        String sizeStr = flat.get("size");
        if (sizeStr != null) {
            try { fe.size = Long.parseLong(sizeStr.trim()); } catch (Exception ignored) {}
        }
        fe.rebuildSearchHaystack();

        synchronized (allEntries) {
            if (allEntries.size() >= MAX_RESULTS) return;
            allEntries.add(fe);
        }
        // Stream to table if it matches the current filter
        SwingUtilities.invokeLater(new Runnable() {
            public void run() { addRowIfMatches(fe); }
        });
    }

    private void addRowIfMatches(FileEntry fe) {
        if (tableModel.getRowCount() >= MAX_RESULTS) return;
        String q = searchField.getText();
        if (q == null) q = "";
        q = q.trim().toLowerCase(Locale.ROOT);
        if (q.isEmpty() || fe.searchHaystack.contains(q)) {
            insertRow(fe);
        }
        updateCount();
    }

    private void insertRow(FileEntry fe) {
        Object[] row = new Object[]{
                truncateForTable(fe.filename),
                truncateForTable(fe.description),
                fe.size >= 0 ? Long.valueOf(fe.size) : "",
                fe.extension,
                fe.date,
                truncateForTable(fe.serverDisplay)
        };
        tableModel.addRow(row);
        int modelRow = tableModel.getRowCount() - 1;
        // Stash a back-reference to the FileEntry
        resultsTable.putClientProperty("entry@" + modelRow, fe);
    }

    private FileEntry entryAtModelRow(int modelRow) {
        if (modelRow < 0) return null;
        Object o = resultsTable.getClientProperty("entry@" + modelRow);
        if (o instanceof FileEntry) return (FileEntry) o;
        return null;
    }

    private FileEntry selectedEntry() {
        int vrow = resultsTable.getSelectedRow();
        if (vrow < 0) return null;
        int mrow = resultsTable.convertRowIndexToModel(vrow);
        return entryAtModelRow(mrow);
    }

    private void updateCount() {
        int total = allEntries.size();
        int shown = tableModel.getRowCount();
        countLabel.setText(shown + " shown / " + total + " total");
    }

    // ==================== Filter / search ====================
    private void applyFilter() {
        // Rebuild table from allEntries applying the current query
        tableModel.setRowCount(0);
        String q = searchField.getText();
        if (q == null) q = "";
        q = q.trim().toLowerCase(Locale.ROOT);
        List<FileEntry> snapshot;
        synchronized (allEntries) { snapshot = new ArrayList<FileEntry>(allEntries); }
        int added = 0;
        for (FileEntry fe : snapshot) {
            if (q.isEmpty() || fe.searchHaystack.contains(q)) {
                if (added >= MAX_RESULTS) break;
                insertRow(fe);
                added++;
            }
        }
        updateCount();
        setStatus(shownMatchesStatus(added, snapshot.size(), q));
    }

    private String shownMatchesStatus(int shown, int total, String q) {
        if (q.isEmpty()) return "Showing " + shown + " of " + total + " entries.";
        return "Showing " + shown + " matches for \"" + q + "\" (" + total + " total).";
    }

    // ==================== Downloads ====================
    private void startDownloadAll() {
        if (downloadInProgress.get()) {
            setStatus("A download is already running.");
            return;
        }
        final List<String> serverSnapshot;
        synchronized (servers) { serverSnapshot = new ArrayList<String>(servers); }
        if (serverSnapshot.isEmpty()) {
            setStatus("No servers loaded.");
            return;
        }
        outputDir = outputDirField.getText().trim();
        if (outputDir.isEmpty()) outputDir = baseDir;
        SwingWorker<Void, String> worker = new SwingWorker<Void, String>() {
            protected Void doInBackground() {
                downloadInProgress.set(true);
                cancelRequested.set(false);
                setProgressButtons(false);
                publish("Download ALL started ...");
                for (String raw : serverSnapshot) {
                    if (cancelRequested.get()) break;
                    String base = normalizeBase(raw);
                    String display = raw;
                    publish("[" + display + "] fetching files.txt ...");
                    String body;
                    try {
                        body = httpGetString(base + "files.txt", CONNECT_TIMEOUT_MS, READ_TIMEOUT_MS);
                    } catch (IOException e) {
                        publish("[" + display + "] files.txt failed: " + e.getMessage());
                        continue;
                    }
                    if (body == null) body = "";
                    String[] lines = body.split("\\r?\\n");
                    int idx = 0;
                    for (String l : lines) {
                        if (cancelRequested.get()) break;
                        String t = l.trim();
                        if (t.isEmpty() || t.startsWith("#")) continue;
                        idx++;
                        String fileUrl = base + "files/" + t;
                        String infoUrl = base + "info/" + stripExt(t) + ".json";
                        publish("[" + display + "] (" + idx + ") " + t);
                        try {
                            downloadTo(fileUrl, destFor(base, "files", t));
                        } catch (IOException e) {
                            publish("[" + display + "] file failed: " + e.getMessage());
                        }
                        try {
                            downloadTo(infoUrl, destFor(base, "info", stripExt(t) + ".json"));
                        } catch (IOException e) {
                            publish("[" + display + "] info failed: " + e.getMessage());
                        }
                    }
                }
                publish("Download ALL finished.");
                return null;
            }
            protected void process(List<String> chunks) {
                for (String s : chunks) setStatus(s);
            }
            protected void done() {
                downloadInProgress.set(false);
                setProgressButtons(true);
            }
        };
        worker.execute();
    }

    private void startDownloadMatched() {
        if (downloadInProgress.get()) {
            setStatus("A download is already running.");
            return;
        }
        final List<FileEntry> snapshot;
        synchronized (allEntries) { snapshot = new ArrayList<FileEntry>(allEntries); }
        if (snapshot.isEmpty()) {
            setStatus("Nothing to download.");
            return;
        }
        String q = searchField.getText();
        if (q == null) q = "";
        q = q.trim().toLowerCase(Locale.ROOT);
        final String query = q;
        outputDir = outputDirField.getText().trim();
        if (outputDir.isEmpty()) outputDir = baseDir;

        SwingWorker<Void, String> worker = new SwingWorker<Void, String>() {
            protected Void doInBackground() {
                downloadInProgress.set(true);
                cancelRequested.set(false);
                setProgressButtons(false);
                int total = 0;
                int ok = 0;
                for (FileEntry fe : snapshot) {
                    if (cancelRequested.get()) break;
                    if (!query.isEmpty() && !fe.searchHaystack.contains(query)) continue;
                    total++;
                    // Use JSON's "extension" field if present, else fallback to originalName
                    String ext = fe.extension;
                    if (ext == null) ext = "";
                    ext = ext.trim();
                    if (ext.startsWith(".")) ext = ext.substring(1);
                    String fileName = fe.hash + (ext.isEmpty() ? "" : "." + ext);
                    String fileUrl = fe.serverBase + "files/" + fileName;
                    String infoUrl = fe.serverBase + "info/" + fe.hash + ".json";
                    publish("[" + fe.serverDisplay + "] " + fileName);
                    try {
                        if (downloadTo(fileUrl, destFor(fe.serverBase, "files", fileName))) ok++;
                    } catch (IOException e) {
                        publish("  file failed: " + e.getMessage());
                    }
                    try {
                        if (downloadTo(infoUrl, destFor(fe.serverBase, "info", fe.hash + ".json"))) ok++;
                    } catch (IOException e) {
                        publish("  info failed: " + e.getMessage());
                    }
                }
                publish("Download MATCHED done. " + ok + " of " + (total * 2) + " files saved.");
                return null;
            }
            protected void process(List<String> chunks) {
                for (String s : chunks) setStatus(s);
            }
            protected void done() {
                downloadInProgress.set(false);
                setProgressButtons(true);
            }
        };
        worker.execute();
    }

    private void downloadOneEntry(final FileEntry fe, final boolean alsoInfo) {
        if (downloadInProgress.get()) {
            setStatus("A download is already running.");
            return;
        }
        outputDir = outputDirField.getText().trim();
        if (outputDir.isEmpty()) outputDir = baseDir;
        SwingWorker<Void, String> worker = new SwingWorker<Void, String>() {
            protected Void doInBackground() {
                downloadInProgress.set(true);
                cancelRequested.set(false);
                setProgressButtons(false);
                String ext = fe.extension == null ? "" : fe.extension.trim();
                if (ext.startsWith(".")) ext = ext.substring(1);
                String fileName = fe.hash + (ext.isEmpty() ? "" : "." + ext);
                String fileUrl = fe.serverBase + "files/" + fileName;
                String infoUrl = fe.serverBase + "info/" + fe.hash + ".json";
                publish("Downloading " + fileName + " ...");
                try {
                    downloadTo(fileUrl, destFor(fe.serverBase, "files", fileName));
                    publish("Saved " + fileName);
                } catch (IOException e) {
                    publish("File failed: " + e.getMessage());
                }
                if (alsoInfo) {
                    try {
                        downloadTo(infoUrl, destFor(fe.serverBase, "info", fe.hash + ".json"));
                        publish("Saved info " + fe.hash + ".json");
                    } catch (IOException e) {
                        publish("Info failed: " + e.getMessage());
                    }
                }
                return null;
            }
            protected void process(List<String> chunks) {
                for (String s : chunks) setStatus(s);
            }
            protected void done() {
                downloadInProgress.set(false);
                setProgressButtons(true);
            }
        };
        worker.execute();
    }

    private void downloadInfoOnly(final FileEntry fe) {
        if (downloadInProgress.get()) {
            setStatus("A download is already running.");
            return;
        }
        outputDir = outputDirField.getText().trim();
        if (outputDir.isEmpty()) outputDir = baseDir;
        SwingWorker<Void, String> worker = new SwingWorker<Void, String>() {
            protected Void doInBackground() {
                downloadInProgress.set(true);
                cancelRequested.set(false);
                setProgressButtons(false);
                String infoUrl = fe.serverBase + "info/" + fe.hash + ".json";
                try {
                    downloadTo(infoUrl, destFor(fe.serverBase, "info", fe.hash + ".json"));
                    publish("Saved info " + fe.hash + ".json");
                } catch (IOException e) {
                    publish("Info failed: " + e.getMessage());
                }
                return null;
            }
            protected void process(List<String> chunks) {
                for (String s : chunks) setStatus(s);
            }
            protected void done() {
                downloadInProgress.set(false);
                setProgressButtons(true);
            }
        };
        worker.execute();
    }

    private void setProgressButtons(boolean enabled) {
        SwingUtilities.invokeLater(new Runnable() {
            public void run() {
                downloadAllButton.setEnabled(enabled);
                downloadMatchedButton.setEnabled(enabled);
                reloadServersButton.setEnabled(enabled);
                cancelButton.setEnabled(!enabled);
            }
        });
    }

    private void requestCancel() {
        cancelRequested.set(true);
        setStatus("Cancel requested ...");
    }

    /**
     * Returns true if the file was actually written.
     */
    private boolean downloadTo(String url, File dest) throws IOException {
        File parent = dest.getParentFile();
        if (parent != null && !parent.isDirectory() && !parent.mkdirs()) {
            throw new IOException("Cannot create directory: " + parent);
        }
        HttpURLConnection conn = openConn(url, "GET", CONNECT_TIMEOUT_MS, READ_TIMEOUT_MS);
        int code = conn.getResponseCode();
        if (code == HttpURLConnection.HTTP_NOT_FOUND) {
            conn.disconnect();
            return false;
        }
        if (code < 200 || code >= 300) {
            conn.disconnect();
            throw new IOException("HTTP " + code + " for " + url);
        }
        InputStream in = null;
        FileOutputStream out = null;
        try {
            in = new BufferedInputStream(conn.getInputStream());
            out = new FileOutputStream(dest);
            byte[] buf = new byte[16 * 1024];
            int n;
            long total = 0;
            while ((n = in.read(buf)) != -1) {
                if (cancelRequested.get()) {
                    out.close();
                    dest.delete();
                    throw new IOException("Cancelled");
                }
                out.write(buf, 0, n);
                total += n;
                if (total > MAX_DOWNLOAD_BYTES) {
                    out.close();
                    dest.delete();
                    throw new IOException("File exceeds " + MAX_DOWNLOAD_BYTES + " bytes; aborted.");
                }
            }
        } finally {
            closeQuiet(out);
            closeQuiet(in);
            try { conn.disconnect(); } catch (Exception ignored) {}
        }
        logDownload(url, dest);
        return true;
    }

    private void logDownload(String url, File dest) {
        try {
            File logRoot = new File(outputDir, LOG_SUBDIR);
            if (!logRoot.isDirectory() && !logRoot.mkdirs()) return;
            String name = dest.getName() + ".download.json";
            File out = new File(logRoot, name);
            StringBuilder sb = new StringBuilder();
            sb.append("{\n");
            sb.append("  \"file\": ").append(jsonString(dest.getAbsolutePath())).append(",\n");
            sb.append("  \"url\": ").append(jsonString(url)).append(",\n");
            sb.append("  \"name\": ").append(jsonString(dest.getName())).append(",\n");
            sb.append("  \"size\": ").append(dest.length()).append(",\n");
            sb.append("  \"date\": ").append(jsonString(nowIso())).append(",\n");
            sb.append("  \"timestamp\": ").append(System.currentTimeMillis()).append("\n");
            sb.append("}\n");
            BufferedWriter bw = null;
            try {
                bw = new BufferedWriter(new OutputStreamWriter(new FileOutputStream(out), StandardCharsets.UTF_8));
                bw.write(sb.toString());
            } finally {
                closeQuiet(bw);
            }
        } catch (Exception e) {
            // best-effort, never throw
        }
    }

    // ==================== Misc helpers ====================
    private File destFor(String baseUrl, String subdir, String fileName) {
        // Use a sanitized folder name from the URL
        String folder = sanitizeFolderName(baseUrl);
        File root = new File(outputDir, DOWNLOAD_SUBDIR);
        File f = new File(new File(root, folder), subdir);
        return new File(f, sanitizeFileName(fileName));
    }

    private static String sanitizeFolderName(String url) {
        String s = url.replaceFirst("^https?://", "");
        s = s.replaceAll("[\\\\/:*?\"<>|]+", "_");
        s = s.replaceAll("[\\s]+", "_");
        if (s.length() > 120) s = s.substring(0, 120);
        if (s.isEmpty()) s = "server";
        return s;
    }

    private static String sanitizeFileName(String name) {
        // Allow hash characters and extensions; strip any path-like parts.
        String s = name;
        int slash = Math.max(s.lastIndexOf('/'), s.lastIndexOf('\\'));
        if (slash >= 0) s = s.substring(slash + 1);
        // Replace anything that's not a safe char.
        s = s.replaceAll("[^A-Za-z0-9._-]", "_");
        if (s.isEmpty()) s = "file";
        return s;
    }

    private String fileUrl(FileEntry fe) {
        String ext = fe.extension == null ? "" : fe.extension.trim();
        if (ext.startsWith(".")) ext = ext.substring(1);
        String fileName = fe.hash + (ext.isEmpty() ? "" : "." + ext);
        return fe.serverBase + "files/" + fileName;
    }

    private void chooseOutputDir() {
        JFileChooser fc = new JFileChooser();
        fc.setFileSelectionMode(JFileChooser.DIRECTORIES_ONLY);
        fc.setCurrentDirectory(new File(outputDir));
        int r = fc.showOpenDialog(this);
        if (r == JFileChooser.APPROVE_OPTION) {
            File f = fc.getSelectedFile();
            if (f != null) {
                outputDir = f.getAbsolutePath();
                outputDirField.setText(outputDir);
            }
        }
    }

    private void setStatus(String s) {
        final String text = s;
        SwingUtilities.invokeLater(new Runnable() {
            public void run() { statusLabel.setText(text); }
        });
    }

    private void copyToClipboard(String s) {
        if (s == null) return;
        Toolkit.getDefaultToolkit().getSystemClipboard().setContents(new StringSelection(s), null);
        setStatus("Copied: " + s);
    }

    private void openInBrowser(String url) {
        try {
            if (Desktop.isDesktopSupported()) {
                Desktop.getDesktop().browse(new URI(url));
            } else {
                setStatus("Desktop browse not supported on this platform.");
            }
        } catch (Exception e) {
            setStatus("Open failed: " + e.getMessage());
        }
    }

    private static String nowIso() {
        SimpleDateFormat f = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.ROOT);
        return f.format(new Date());
    }

    private static String truncate(String s) {
        if (s == null) return "";
        if (s.length() <= MAX_FIELD_LENGTH) return s;
        return s.substring(0, MAX_FIELD_LENGTH);
    }

    private static String truncateForTable(String s) {
        if (s == null) return "";
        // Tables can take a bit more on screen; use 4x limit for visual quality
        int cap = MAX_FIELD_LENGTH * 2;
        if (s.length() <= cap) return s;
        return s.substring(0, cap) + " ...";
    }

    private static String firstNonEmpty(String a, String b) {
        if (a != null) {
            String t = a.trim();
            if (!t.isEmpty()) return t;
        }
        return b == null ? "" : b;
    }

    private static int parsePositiveInt(String s, int fallback) {
        if (s == null) return fallback;
        try {
            int v = Integer.parseInt(s.trim());
            return v > 0 ? v : fallback;
        } catch (Exception e) {
            return fallback;
        }
    }

    private static String stripExt(String name) {
        int dot = name.lastIndexOf('.');
        if (dot <= 0) return name;
        return name.substring(0, dot);
    }

    private static String escapeHtml(String s) {
        if (s == null) return "";
        return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                .replace("\"", "&quot;");
    }

    private static void closeQuiet(Closeable c) {
        if (c == null) return;
        try { c.close(); } catch (Exception ignored) {}
    }

    // ==================== Networking ====================
    private static String normalizeBase(String url) {
        String s = url.trim();
        if (!s.endsWith("/")) s = s + "/";
        return s;
    }

    private static HttpURLConnection openConn(String urlStr, String method,
                                              int connectTimeout, int readTimeout) throws IOException {
        URL u = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) u.openConnection();
        conn.setRequestMethod(method);
        conn.setConnectTimeout(connectTimeout);
        conn.setReadTimeout(readTimeout);
        conn.setInstanceFollowRedirects(true);
        conn.setRequestProperty("User-Agent", "Meshare/1.0 (Java)");
        conn.setRequestProperty("Accept", "*/*");
        return conn;
    }

    private static String httpGetString(String urlStr, int connectTimeout, int readTimeout) throws IOException {
        HttpURLConnection conn = openConn(urlStr, "GET", connectTimeout, readTimeout);
        int code;
        try {
            code = conn.getResponseCode();
        } catch (IOException e) {
            try { conn.disconnect(); } catch (Exception ignored) {}
            throw e;
        }
        if (code == HttpURLConnection.HTTP_NOT_FOUND) {
            try { conn.disconnect(); } catch (Exception ignored) {}
            return null;
        }
        if (code < 200 || code >= 300) {
            try { conn.disconnect(); } catch (Exception ignored) {}
            throw new IOException("HTTP " + code + " for " + urlStr);
        }
        InputStream in = null;
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        try {
            in = new BufferedInputStream(conn.getInputStream());
            byte[] buf = new byte[8 * 1024];
            int n;
            while ((n = in.read(buf)) != -1) baos.write(buf, 0, n);
        } finally {
            closeQuiet(in);
            try { conn.disconnect(); } catch (Exception ignored) {}
        }
        return new String(baos.toByteArray(), StandardCharsets.UTF_8);
    }

    // ==================== JSON parsing (no external libs) ====================
    /**
     * Parses a flat JSON object into a LinkedHashMap preserving order.
     * Strings, numbers, booleans, null are supported. Nested objects are
     * stored as their JSON source. Used only for display; not a general
     * purpose JSON library.
     */
    private static Map<String, String> parseFlatJson(String s) {
        Map<String, String> out = new LinkedHashMap<String, String>();
        if (s == null) return out;
        int i = skipWs(s, 0);
        if (i >= s.length() || s.charAt(i) != '{') return out;
        i++;
        while (true) {
            i = skipWs(s, i);
            if (i >= s.length()) return out;
            char c = s.charAt(i);
            if (c == '}') return out;
            if (c != '"') {
                // Skip unexpected token
                i++;
                continue;
            }
            // Key
            String[] keyRes = readString(s, i);
            if (keyRes == null) return out;
            String key = keyRes[0];
            i = skipWs(s, Integer.parseInt(keyRes[1]));
            if (i >= s.length() || s.charAt(i) != ':') return out;
            i = skipWs(s, i + 1);
            // Value
            if (i >= s.length()) return out;
            char vc = s.charAt(i);
            String valStr;
            if (vc == '"') {
                String[] valRes = readString(s, i);
                if (valRes == null) return out;
                valStr = unescapeJson(valRes[0]);
                i = Integer.parseInt(valRes[1]);
            } else if (vc == '{' || vc == '[') {
                // Read balanced sub-structure as raw
                int end = findMatching(s, i, vc);
                if (end < 0) return out;
                valStr = s.substring(i, end + 1);
                i = end + 1;
            } else {
                int start = i;
                while (i < s.length() && ",}] \t\r\n".indexOf(s.charAt(i)) < 0) i++;
                valStr = s.substring(start, i);
            }
            if (valStr == null) valStr = "";
            if (valStr.length() > MAX_FIELD_LENGTH) valStr = valStr.substring(0, MAX_FIELD_LENGTH);
            out.put(key, valStr);
            i = skipWs(s, i);
            if (i < s.length() && s.charAt(i) == ',') { i++; continue; }
        }
    }

    private static int skipWs(String s, int i) {
        while (i < s.length()) {
            char c = s.charAt(i);
            if (c == ' ' || c == '\t' || c == '\r' || c == '\n') i++;
            else break;
        }
        return i;
    }

    /**
     * Reads a JSON string starting at index i (where s.charAt(i) == '"').
     * Returns { unescaped, nextIndex } encoded as String[2], or null on error.
     */
    private static String[] readString(String s, int i) {
        if (i >= s.length() || s.charAt(i) != '"') return null;
        i++;
        StringBuilder sb = new StringBuilder();
        while (i < s.length()) {
            char c = s.charAt(i);
            if (c == '"') {
                return new String[]{sb.toString(), String.valueOf(i + 1)};
            }
            if (c == '\\') {
                if (i + 1 >= s.length()) return null;
                char e = s.charAt(i + 1);
                switch (e) {
                    case '"': sb.append('"'); break;
                    case '\\': sb.append('\\'); break;
                    case '/': sb.append('/'); break;
                    case 'b': sb.append('\b'); break;
                    case 'f': sb.append('\f'); break;
                    case 'n': sb.append('\n'); break;
                    case 'r': sb.append('\r'); break;
                    case 't': sb.append('\t'); break;
                    case 'u':
                        if (i + 5 >= s.length()) return null;
                        String hex = s.substring(i + 2, i + 6);
                        try {
                            sb.append((char) Integer.parseInt(hex, 16));
                        } catch (Exception ex) {
                            return null;
                        }
                        i += 4;
                        break;
                    default: sb.append(e); break;
                }
                i += 2;
            } else {
                sb.append(c);
                i++;
            }
        }
        return null;
    }

    private static int findMatching(String s, int open, char openChar) {
        char closeChar = openChar == '{' ? '}' : ']';
        int depth = 0;
        boolean inString = false;
        boolean escape = false;
        for (int i = open; i < s.length(); i++) {
            char c = s.charAt(i);
            if (inString) {
                if (escape) { escape = false; continue; }
                if (c == '\\') { escape = true; continue; }
                if (c == '"') inString = false;
                continue;
            }
            if (c == '"') { inString = true; continue; }
            if (c == openChar) depth++;
            else if (c == closeChar) {
                depth--;
                if (depth == 0) return i;
            }
        }
        return -1;
    }

    private static String unescapeJson(String s) {
        // readString already unescaped; this is a no-op kept for clarity.
        return s;
    }

    private static String jsonString(String s) {
        if (s == null) return "null";
        StringBuilder sb = new StringBuilder("\"");
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"': sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                case '\b': sb.append("\\b"); break;
                case '\f': sb.append("\\f"); break;
                default:
                    if (c < 0x20) sb.append(String.format(Locale.ROOT, "\\u%04x", (int) c));
                    else sb.append(c);
            }
        }
        sb.append('"');
        return sb.toString();
    }

    // ==================== main ====================
    public static void main(String[] args) {
        try {
            UIManager.setLookAndFeel(UIManager.getSystemLookAndFeelClassName());
        } catch (Exception e) {
            // keep cross-platform L&F
        }
        SwingUtilities.invokeLater(new Runnable() {
            public void run() {
                Meshare app = new Meshare();
                app.setVisible(true);
            }
        });
    }
}
