import java.awt.Component;
import java.awt.Desktop;
import java.awt.Dimension;
import java.awt.FlowLayout;
import java.awt.Font;
import java.awt.BorderLayout;
import java.awt.Color;
import java.awt.event.ActionEvent;
import java.awt.event.ActionListener;
import java.awt.event.MouseAdapter;
import java.awt.event.MouseEvent;
import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.FileWriter;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;
import java.util.Set;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.ThreadFactory;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

import javax.swing.BorderFactory;
import javax.swing.Box;
import javax.swing.BoxLayout;
import javax.swing.JButton;
import javax.swing.JCheckBox;
import javax.swing.JFrame;
import javax.swing.JLabel;
import javax.swing.JMenuItem;
import javax.swing.JOptionPane;
import javax.swing.JPanel;
import javax.swing.JPopupMenu;
import javax.swing.JProgressBar;
import javax.swing.JScrollPane;
import javax.swing.JSpinner;
import javax.swing.JSplitPane;
import javax.swing.JTable;
import javax.swing.JTextArea;
import javax.swing.JTextField;
import javax.swing.ListSelectionModel;
import javax.swing.SpinnerNumberModel;
import javax.swing.SwingUtilities;
import javax.swing.UIManager;
import javax.swing.border.EmptyBorder;
import javax.swing.border.TitledBorder;
import javax.swing.event.ListSelectionEvent;
import javax.swing.event.ListSelectionListener;
import javax.swing.table.DefaultTableCellRenderer;
import javax.swing.table.DefaultTableModel;

/**
 * Meshare - distributed file browser/searcher
 *
 * Single-file Java 8 GUI app. Reads a list of servers from "servers.txt",
 * pulls each server's "files.txt", then for every valid "<sha256>.<ext>" line
 * pulls the matching "info/<sha256>.json" and shows the metadata. Results
 * stream in as they are found. A search box lets the user filter by any
 * field. The user can download individual files, all currently matching
 * results, or every file from every server.
 *
 * Layout on disk:
 *   files/    binary downloads          (named <hash>.<ext from json>)
 *   info/     json metadata             (named <hash>.json)
 *   log/      per-download log files    (named <originalName>_<hash8>.log.json)
 *   servers.txt  one server URL per line
 *   files.txt    local list of files in files/ (one per line, maintained by us)
 */
public class Meshare extends JFrame {

    private static final long serialVersionUID = 1L;

    // ---------------- configuration ----------------
    private static final String SERVERS_FILE     = "servers.txt";
    private static final String FILES_DIR        = "files";
    private static final String INFO_DIR         = "info";
    private static final String LOG_DIR          = "log";
    private static final String LOCAL_FILES_LIST = "files.txt";

    private static final int MAX_RESULTS         = 200;
    private static final int MAX_FIELD_LENGTH    = 512;
    private static final int CONNECT_TIMEOUT_MS  = 10000;
    private static final int READ_TIMEOUT_MS     = 30000;
    private static final int MAX_LOG_LINES       = 1500;

    private static final String[] COLUMNS = {
        "Server", "Filename", "Ext", "Size", "Description", "Date", "Status"
    };

    // ---------------- UI ----------------
    private JTextField      searchField;
    private JCheckBox       randomTriesCheck;
    private JSpinner        randomTriesSpinner;
    private JButton         searchButton;
    private JButton         stopButton;
    private JButton         downloadSelectedButton;
    private JButton         downloadMatchingButton;
    private JButton         downloadAllButton;
    private JButton         viewJsonButton;
    private JButton         openFolderButton;
    private JLabel          statusLabel;
    private JLabel          serverCountLabel;
    private JTextArea       logArea;
    private JTextArea       detailsArea;
    private DefaultTableModel tableModel;
    private JTable          resultTable;
    private JProgressBar    progressBar;

    // ---------------- state ----------------
    private final List<FileEntry>     currentResults = new CopyOnWriteArrayList<FileEntry>();
    private final AtomicBoolean       searchActive   = new AtomicBoolean(false);
    private final AtomicBoolean       downloadActive = new AtomicBoolean(false);
    private final AtomicInteger       resultsCount   = new AtomicInteger(0);
    private final AtomicInteger       downloadedCount = new AtomicInteger(0);
    private ExecutorService           executor;

    // ===================================================================
    //                          inner class
    // ===================================================================
    public static class FileEntry {
        public String server;
        public String hash;
        public String extension;
        public String filename         = "";
        public String size             = "";
        public String publicKey        = "";
        public String serverPublicKey  = "";
        public String nodeInfo         = "";
        public String description      = "";
        public String date             = "";
        public volatile String status  = "";
        public volatile boolean isLocal = false;
        public final Map<String, String> rawFields = new LinkedHashMap<String, String>();
        public String rawJson = "";

        public String getKey()              { return hash + "." + extension; }
        public String getDisplayFilename()  { return filename.isEmpty() ? getKey() : filename; }
        public String getLocalPath()        {
            return new File(FILES_DIR, hash + "." + extension).getAbsolutePath();
        }
    }

    // ===================================================================
    //                          constructor
    // ===================================================================
    public Meshare() {
        super("Meshare");
        setSize(1280, 850);
        setMinimumSize(new Dimension(980, 620));
        setDefaultCloseOperation(JFrame.EXIT_ON_CLOSE);
        setLocationRelativeTo(null);

        ensureDirectories();

        executor = Executors.newFixedThreadPool(16, new ThreadFactory() {
            private int id = 0;
            @Override public Thread newThread(Runnable r) {
                Thread t = new Thread(r, "meshare-" + (++id));
                t.setDaemon(true);
                return t;
            }
        });

        initComponents();
        loadServerCount();

        Runtime.getRuntime().addShutdownHook(new Thread(new Runnable() {
            @Override public void run() { executor.shutdownNow(); }
        }));
    }

    private void ensureDirectories() {
        new File(FILES_DIR).mkdirs();
        new File(INFO_DIR).mkdirs();
        new File(LOG_DIR).mkdirs();
    }

    // ===================================================================
    //                          UI
    // ===================================================================
    private void initComponents() {
        JPanel root = new JPanel(new BorderLayout(6, 6));
        root.setBorder(new EmptyBorder(8, 8, 8, 8));

        // ----- top -----
        JPanel topPanel = new JPanel();
        topPanel.setLayout(new BoxLayout(topPanel, BoxLayout.Y_AXIS));
        topPanel.setBorder(new TitledBorder("Search"));

        JPanel row1 = new JPanel(new FlowLayout(FlowLayout.LEFT, 6, 2));
        serverCountLabel = new JLabel("Servers: -");
        row1.add(serverCountLabel);
        JButton reloadBtn = new JButton("Reload servers.txt");
        reloadBtn.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { loadServerCount(); }
        });
        row1.add(reloadBtn);
        topPanel.add(row1);

        JPanel row2 = new JPanel(new FlowLayout(FlowLayout.LEFT, 6, 2));
        row2.add(new JLabel("Search:"));
        searchField = new JTextField(30);
        row2.add(searchField);

        randomTriesCheck = new JCheckBox("Random tries per server:");
        row2.add(randomTriesCheck);
        randomTriesSpinner = new JSpinner(new SpinnerNumberModel(10, 1, 1000000, 1));
        randomTriesSpinner.setPreferredSize(new Dimension(80, 26));
        randomTriesSpinner.setEnabled(false);
        row2.add(randomTriesSpinner);
        randomTriesCheck.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) {
                randomTriesSpinner.setEnabled(randomTriesCheck.isSelected());
            }
        });

        searchButton = new JButton("Search");
        stopButton   = new JButton("Stop");
        stopButton.setEnabled(false);
        row2.add(searchButton);
        row2.add(stopButton);
        topPanel.add(row2);

        root.add(topPanel, BorderLayout.NORTH);

        // ----- center: table -----
        tableModel = new DefaultTableModel(COLUMNS, 0) {
            private static final long serialVersionUID = 1L;
            @Override public boolean isCellEditable(int row, int column) { return false; }
        };
        resultTable = new JTable(tableModel);
        resultTable.setAutoCreateRowSorter(true);
        resultTable.setSelectionMode(ListSelectionModel.MULTIPLE_INTERVAL_SELECTION);
        resultTable.setRowHeight(22);
        resultTable.setIntercellSpacing(new Dimension(4, 2));
        resultTable.getTableHeader().setReorderingAllowed(false);
        resultTable.setDefaultRenderer(Object.class, new DefaultTableCellRenderer() {
            private static final long serialVersionUID = 1L;
            @Override
            public Component getTableCellRendererComponent(JTable table, Object value,
                                                           boolean isSelected, boolean hasFocus,
                                                           int row, int column) {
                Component c = super.getTableCellRendererComponent(table, value, isSelected, hasFocus, row, column);
                if (!isSelected) {
                    int modelRow = table.convertRowIndexToModel(row);
                    if (modelRow >= 0 && modelRow < currentResults.size()) {
                        FileEntry entry = currentResults.get(modelRow);
                        if (entry != null) {
                            if (column == 6) {
                                if (entry.isLocal)                          c.setBackground(new Color(210, 245, 210));
                                else if (entry.status != null
                                        && entry.status.toLowerCase().contains("download")) c.setBackground(new Color(220, 230, 255));
                                else if (entry.status != null
                                        && (entry.status.toLowerCase().contains("fail")
                                         || entry.status.toLowerCase().contains("mismatch"))) c.setBackground(new Color(255, 220, 220));
                                else                                        c.setBackground(Color.WHITE);
                            } else {
                                c.setBackground(entry.isLocal ? new Color(245, 252, 245) : Color.WHITE);
                            }
                        }
                    } else {
                        c.setBackground(Color.WHITE);
                    }
                }
                return c;
            }
        });

        int[] widths = {180, 200, 50, 80, 280, 150, 110};
        for (int i = 0; i < widths.length; i++) {
            resultTable.getColumnModel().getColumn(i).setPreferredWidth(widths[i]);
        }

        JScrollPane tableScroll = new JScrollPane(resultTable);
        tableScroll.setBorder(new TitledBorder("Results (max " + MAX_RESULTS + ")"));
        root.add(tableScroll, BorderLayout.CENTER);

        // ----- bottom: buttons + details + log -----
        JPanel bottomPanel = new JPanel(new BorderLayout(5, 5));

        JPanel buttonPanel = new JPanel(new FlowLayout(FlowLayout.LEFT, 6, 2));
        downloadSelectedButton = new JButton("Download Selected");
        downloadMatchingButton = new JButton("Download Matching");
        downloadAllButton      = new JButton("Download All");
        viewJsonButton         = new JButton("View JSON");
        openFolderButton       = new JButton("Open Files Folder");
        buttonPanel.add(downloadSelectedButton);
        buttonPanel.add(downloadMatchingButton);
        buttonPanel.add(downloadAllButton);
        buttonPanel.add(viewJsonButton);
        buttonPanel.add(openFolderButton);
        buttonPanel.add(Box.createHorizontalStrut(20));
        statusLabel = new JLabel("Ready");
        buttonPanel.add(statusLabel);
        progressBar = new JProgressBar();
        progressBar.setPreferredSize(new Dimension(160, 16));
        progressBar.setVisible(false);
        buttonPanel.add(progressBar);
        bottomPanel.add(buttonPanel, BorderLayout.NORTH);

        JSplitPane splitPane = new JSplitPane(JSplitPane.VERTICAL_SPLIT);
        splitPane.setResizeWeight(0.5);

        detailsArea = new JTextArea(7, 50);
        detailsArea.setEditable(false);
        detailsArea.setFont(new Font(Font.MONOSPACED, Font.PLAIN, 11));
        detailsArea.setText("Select a row to see details.");
        JScrollPane detailsScroll = new JScrollPane(detailsArea);
        detailsScroll.setBorder(new TitledBorder("Details"));

        logArea = new JTextArea(7, 50);
        logArea.setEditable(false);
        logArea.setFont(new Font(Font.MONOSPACED, Font.PLAIN, 11));
        JScrollPane logScroll = new JScrollPane(logArea);
        logScroll.setBorder(new TitledBorder("Log"));

        splitPane.setTopComponent(detailsScroll);
        splitPane.setBottomComponent(logScroll);
        bottomPanel.add(splitPane, BorderLayout.CENTER);

        root.add(bottomPanel, BorderLayout.SOUTH);

        setContentPane(root);

        // ----- listeners -----
        searchField.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { startSearch(); }
        });
        searchButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { startSearch(); }
        });
        stopButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { stopAll(); }
        });
        downloadSelectedButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { downloadSelected(); }
        });
        downloadMatchingButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { downloadMatching(); }
        });
        downloadAllButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { downloadAll(); }
        });
        viewJsonButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { viewSelectedJson(); }
        });
        openFolderButton.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { openFilesFolder(); }
        });

        resultTable.getSelectionModel().addListSelectionListener(new ListSelectionListener() {
            @Override public void valueChanged(ListSelectionEvent e) { updateDetails(); }
        });

        JPopupMenu popup = new JPopupMenu();
        JMenuItem dlItem   = new JMenuItem("Download");
        JMenuItem vjItem   = new JMenuItem("View JSON");
        JMenuItem openItem = new JMenuItem("Reveal in folder");
        popup.add(dlItem);
        popup.add(vjItem);
        popup.addSeparator();
        popup.add(openItem);
        resultTable.setComponentPopupMenu(popup);
        dlItem.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { downloadSelected(); }
        });
        vjItem.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { viewSelectedJson(); }
        });
        openItem.addActionListener(new ActionListener() {
            @Override public void actionPerformed(ActionEvent e) { revealInFolder(); }
        });

        resultTable.addMouseListener(new MouseAdapter() {
            @Override public void mouseClicked(MouseEvent e) {
                if (e.getClickCount() == 2 && resultTable.getSelectedRow() >= 0) {
                    downloadSelected();
                }
            }
        });
    }

    // ===================================================================
    //                          server handling
    // ===================================================================
    private void loadServerCount() {
        List<String> servers = readServers();
        String text = "Servers: " + servers.size();
        if (servers.isEmpty()) text += "  (edit servers.txt to add some)";
        serverCountLabel.setText(text);
    }

    private List<String> readServers() {
        List<String> list = new ArrayList<String>();
        File f = new File(SERVERS_FILE);
        if (!f.exists()) {
            log("servers.txt not found in " + new File(".").getAbsolutePath());
            return list;
        }
        BufferedReader r = null;
        try {
            r = new BufferedReader(new InputStreamReader(new FileInputStream(f), StandardCharsets.UTF_8));
            String line;
            while ((line = r.readLine()) != null) {
                line = line.trim();
                if (line.isEmpty() || line.startsWith("#")) continue;
                list.add(line);
            }
        } catch (IOException e) {
            log("Error reading servers.txt: " + e.getMessage());
        } finally {
            if (r != null) try { r.close(); } catch (IOException e) { /* ignore */ }
        }
        return list;
    }

    private static String normalizeServer(String url) {
        url = url.trim();
        if (url.isEmpty()) return url;
        if (url.endsWith("/")) return url;
        int q = url.indexOf('?');
        int h = url.indexOf('#');
        int cut = -1;
        if (q >= 0) cut = q;
        if (h >= 0 && (cut < 0 || h < cut)) cut = h;
        if (cut >= 0) return url;
        return url + "/";
    }

    // ===================================================================
    //                          search
    // ===================================================================
    private void startSearch() {
        if (searchActive.get()) {
            log("Search already in progress");
            return;
        }

        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                tableModel.setRowCount(0);
                currentResults.clear();
                resultsCount.set(0);
                searchButton.setEnabled(false);
                stopButton.setEnabled(true);
                downloadAllButton.setEnabled(false);
                statusLabel.setText("Searching...");
            }
        });

        final String query         = searchField.getText().trim();
        final boolean randomOn     = randomTriesCheck.isSelected();
        final int randomCount      = ((Number) randomTriesSpinner.getValue()).intValue();
        final List<String> servers = readServers();

        if (servers.isEmpty()) {
            log("No servers configured (add URLs to servers.txt)");
            finishSearch();
            return;
        }

        searchActive.set(true);

        log("Search started: query='" + query + "'"
                + (randomOn ? ", random=" + randomCount : "")
                + ", " + servers.size() + " server(s)");

        final List<Future<?>> futures = new ArrayList<Future<?>>();
        for (final String server : servers) {
            futures.add(executor.submit(new Runnable() {
                @Override public void run() {
                    try { searchServer(server, query, randomOn, randomCount); }
                    catch (Throwable t) { log("Error searching " + server + ": " + t.getMessage()); }
                }
            }));
        }

        executor.submit(new Runnable() {
            @Override public void run() {
                for (Future<?> f : futures) {
                    try { f.get(); } catch (Exception e) { /* ignore */ }
                }
                finishSearch();
            }
        });
    }

    private void finishSearch() {
        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                searchActive.set(false);
                searchButton.setEnabled(true);
                stopButton.setEnabled(false);
                downloadAllButton.setEnabled(true);
                statusLabel.setText("Search done: " + resultsCount.get() + " result(s)");
            }
        });
    }

    private void stopAll() {
        searchActive.set(false);
        downloadActive.set(false);
        setStatus("Stop requested...");
    }

    private void searchServer(String server, String query, boolean randomOn, int randomCount) {
        final String baseUrl = normalizeServer(server);
        setStatus("[" + server + "] fetching files.txt");

        String filesListContent = httpGet(baseUrl + "files.txt");
        if (filesListContent == null) {
            log("[" + server + "] failed to fetch files.txt");
            return;
        }

        String[] lines = filesListContent.split("\\r?\\n");
        List<Integer> validIdx = new ArrayList<Integer>();
        for (int i = 0; i < lines.length; i++) {
            if (parseFileLine(lines[i]) != null) validIdx.add(i);
        }

        log("[" + server + "] " + validIdx.size() + " valid entries (of " + lines.length + ")");

        if (validIdx.isEmpty()) return;

        if (randomOn) Collections.shuffle(validIdx, new Random());

        int toProcess = randomOn ? Math.min(randomCount, validIdx.size()) : validIdx.size();
        int processed = 0;

        for (int idx : validIdx) {
            if (!searchActive.get()) break;
            if (resultsCount.get() >= MAX_RESULTS) break;

            String line = lines[idx];
            String[] parsed = parseFileLine(line);
            if (parsed == null) continue;
            String hash = parsed[0];
            String ext  = parsed[1];

            FileEntry entry = new FileEntry();
            entry.server   = server;
            entry.hash     = hash;
            entry.extension = ext;

            File localBinary = new File(FILES_DIR, hash + "." + ext);
            File localInfo   = new File(INFO_DIR, hash + ".json");

            if (localBinary.exists() && localInfo.exists()) {
                // already downloaded -> skip request, just read local info
                entry.isLocal = true;
                entry.status  = "Downloaded";
                String content = readFile(localInfo);
                if (content != null) {
                    entry.rawJson = content;
                    Map<String, String> fields = parseJson(content);
                    populateEntry(entry, fields);
                }
            } else {
                entry.status = "Available";
                setStatus("[" + server + "] fetching " + hash + " info");
                String infoContent = httpGet(baseUrl + "info/" + hash + ".json");
                if (infoContent == null) {
                    continue; // skip - no info
                }
                entry.rawJson = infoContent;
                Map<String, String> fields = parseJson(infoContent);
                populateEntry(entry, fields);
                // Use the extension from the JSON if it is set and valid
                if (entry.extension == null || entry.extension.isEmpty()) {
                    entry.extension = ext;
                }
            }

            if (matchesQuery(entry, query)) {
                addResult(entry);
            }

            processed++;
            if (randomOn && processed >= randomCount) break;
        }
    }

    private void addResult(final FileEntry entry) {
        if (resultsCount.get() >= MAX_RESULTS) return;
        if (resultsCount.incrementAndGet() > MAX_RESULTS) {
            resultsCount.decrementAndGet();
            return;
        }
        currentResults.add(entry);

        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                Object[] row = new Object[] {
                    truncate(entry.server, 40),
                    truncate(entry.filename, 60),
                    entry.extension,
                    entry.size,
                    truncate(entry.description, 100),
                    entry.date,
                    entry.status
                };
                tableModel.addRow(row);
                statusLabel.setText("Results: " + resultsCount.get() + " / " + MAX_RESULTS);
            }
        });
    }

    private boolean matchesQuery(FileEntry entry, String query) {
        if (query == null || query.isEmpty()) return true;
        String q = query.toLowerCase();
        if (containsIc(entry.server,          q)) return true;
        if (containsIc(entry.filename,        q)) return true;
        if (containsIc(entry.description,     q)) return true;
        if (containsIc(entry.extension,       q)) return true;
        if (containsIc(entry.hash,            q)) return true;
        if (containsIc(entry.size,            q)) return true;
        if (containsIc(entry.date,            q)) return true;
        if (containsIc(entry.publicKey,       q)) return true;
        if (containsIc(entry.serverPublicKey, q)) return true;
        if (containsIc(entry.nodeInfo,        q)) return true;
        for (Map.Entry<String, String> f : entry.rawFields.entrySet()) {
            if (containsIc(f.getKey(),   q)) return true;
            if (containsIc(f.getValue(), q)) return true;
        }
        return false;
    }

    private static boolean containsIc(String s, String q) {
        return s != null && q != null && s.toLowerCase().contains(q);
    }

    // ===================================================================
    //                          download
    // ===================================================================
    private void downloadSelected() {
        int[] rows = resultTable.getSelectedRows();
        if (rows.length == 0) {
            log("No row selected");
            return;
        }
        List<FileEntry> todo = new ArrayList<FileEntry>();
        for (int row : rows) {
            int modelRow = resultTable.convertRowIndexToModel(row);
            if (modelRow >= 0 && modelRow < currentResults.size()) {
                FileEntry e = currentResults.get(modelRow);
                if (e != null && !e.isLocal) todo.add(e);
            }
        }
        if (todo.isEmpty()) {
            log("All selected entries are already downloaded");
            return;
        }
        log("Queuing " + todo.size() + " download(s)");
        for (FileEntry e : todo) {
            executor.submit(new Runnable() {
                @Override public void run() { downloadOne(e); }
            });
        }
    }

    private void downloadMatching() {
        List<FileEntry> todo = new ArrayList<FileEntry>();
        for (FileEntry e : currentResults) {
            if (!e.isLocal) todo.add(e);
        }
        if (todo.isEmpty()) {
            log("All matching entries are already downloaded");
            return;
        }
        log("Queuing " + todo.size() + " download(s) (matching)");
        for (FileEntry e : todo) {
            executor.submit(new Runnable() {
                @Override public void run() { downloadOne(e); }
            });
        }
    }

    private void downloadAll() {
        final List<String> servers = readServers();
        if (servers.isEmpty()) {
            log("No servers configured");
            return;
        }
        downloadActive.set(true);
        log("Download all started: " + servers.size() + " server(s)");
        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                downloadAllButton.setEnabled(false);
                stopButton.setEnabled(true);
                progressBar.setIndeterminate(true);
                progressBar.setVisible(true);
            }
        });

        final List<Future<?>> futures = new ArrayList<Future<?>>();
        for (final String server : servers) {
            futures.add(executor.submit(new Runnable() {
                @Override public void run() {
                    try { downloadAllFromServer(server); }
                    catch (Throwable t) { log("Error downloading from " + server + ": " + t.getMessage()); }
                }
            }));
        }

        executor.submit(new Runnable() {
            @Override public void run() {
                for (Future<?> f : futures) {
                    try { f.get(); } catch (Exception e) { /* ignore */ }
                }
                downloadActive.set(false);
                SwingUtilities.invokeLater(new Runnable() {
                    @Override public void run() {
                        downloadAllButton.setEnabled(true);
                        stopButton.setEnabled(false);
                        progressBar.setVisible(false);
                        progressBar.setIndeterminate(false);
                        log("Download all complete: " + downloadedCount.get() + " file(s) downloaded");
                    }
                });
            }
        });
    }

    private void downloadAllFromServer(String server) {
        final String baseUrl = normalizeServer(server);
        String filesListContent = httpGet(baseUrl + "files.txt");
        if (filesListContent == null) {
            log("[" + server + "] failed to fetch files.txt");
            return;
        }
        String[] lines = filesListContent.split("\\r?\\n");
        int downloaded = 0, skipped = 0, failed = 0;

        for (String line : lines) {
            if (!downloadActive.get()) break;
            String[] parsed = parseFileLine(line);
            if (parsed == null) continue;
            String hash = parsed[0];
            String ext  = parsed[1];

            // Fetch info first so we know the canonical extension
            File localInfo = new File(INFO_DIR, hash + ".json");
            String infoContent = null;
            Map<String, String> fields = null;

            if (localInfo.exists()) {
                infoContent = readFile(localInfo);
                if (infoContent != null) fields = parseJson(infoContent);
            } else {
                infoContent = httpGet(baseUrl + "info/" + hash + ".json");
                if (infoContent != null) {
                    fields = parseJson(infoContent);
                }
            }
            if (fields == null) { failed++; continue; }

            String jsonExt = fields.get("extension");
            if (jsonExt == null || jsonExt.isEmpty()) jsonExt = ext;

            File localBinary = new File(FILES_DIR, hash + "." + jsonExt);
            if (localBinary.exists() && localInfo.exists()) { skipped++; continue; }

            if (!httpDownload(baseUrl + "files/" + hash + "." + jsonExt, localBinary)) {
                failed++;
                log("[" + server + "] failed to download binary: " + hash + "." + jsonExt);
                continue;
            }
            if (!verifyHash(localBinary, hash)) {
                log("[" + server + "] hash mismatch: " + hash + "." + jsonExt);
                localBinary.delete();
                failed++;
                continue;
            }
            // Save info file (if we did not have it locally)
            if (!localInfo.exists() && infoContent != null) {
                try (FileWriter w = new FileWriter(localInfo)) { w.write(infoContent); }
                catch (IOException e) { log("Error saving info: " + e.getMessage()); }
            }
            writeLogFile(server, hash, jsonExt, fields);
            addToLocalFilesList(hash + "." + jsonExt);
            downloaded++;
            downloadedCount.incrementAndGet();
            log("[" + server + "] downloaded: " + hash + "." + jsonExt);
        }
        log("[" + server + "] summary: " + downloaded + " downloaded, "
                + skipped + " skipped, " + failed + " failed");
    }

    private void downloadOne(FileEntry entry) {
        if (entry.isLocal) {
            log("Already downloaded: " + entry.getKey());
            return;
        }

        final String baseUrl = normalizeServer(entry.server);
        final String hash    = entry.hash;

        updateEntryStatus(entry, "Downloading...");

        File localInfo = new File(INFO_DIR, hash + ".json");
        Map<String, String> fields = null;
        String infoContent = null;

        if (localInfo.exists()) {
            infoContent = readFile(localInfo);
            if (infoContent != null) fields = parseJson(infoContent);
        } else {
            infoContent = httpGet(baseUrl + "info/" + hash + ".json");
            if (infoContent != null) {
                try (FileWriter w = new FileWriter(localInfo)) { w.write(infoContent); }
                catch (IOException e) { log("Error saving info: " + e.getMessage()); }
                fields = parseJson(infoContent);
            }
        }
        if (fields == null) {
            updateEntryStatus(entry, "Failed (info)");
            log("Failed to get info for " + hash);
            return;
        }
        populateEntry(entry, fields);

        String jsonExt = fields.get("extension");
        if (jsonExt == null || jsonExt.isEmpty()) jsonExt = entry.extension;

        File localBinary = new File(FILES_DIR, hash + "." + jsonExt);
        if (!localBinary.exists()) {
            if (!httpDownload(baseUrl + "files/" + hash + "." + jsonExt, localBinary)) {
                updateEntryStatus(entry, "Failed (binary)");
                log("Failed to download binary: " + hash + "." + jsonExt);
                return;
            }
            if (!verifyHash(localBinary, hash)) {
                log("Hash mismatch: " + hash + "." + jsonExt);
                localBinary.delete();
                updateEntryStatus(entry, "Hash mismatch");
                return;
            }
        }

        entry.isLocal = true;
        updateEntryStatus(entry, "Downloaded");
        writeLogFile(entry.server, hash, jsonExt, fields);
        addToLocalFilesList(hash + "." + jsonExt);
        downloadedCount.incrementAndGet();
        log("Downloaded: " + hash + "." + jsonExt);
    }

    private void updateEntryStatus(final FileEntry entry, final String status) {
        entry.status = status;
        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                int modelRow = currentResults.indexOf(entry);
                if (modelRow >= 0 && modelRow < tableModel.getRowCount()) {
                    tableModel.setValueAt(status, modelRow, 6);
                    resultTable.repaint();
                }
            }
        });
    }

    // ===================================================================
    //                          HTTP
    // ===================================================================
    private String httpGet(String urlStr) {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(urlStr);
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            conn.setRequestProperty("User-Agent", "Meshare/1.0");
            conn.setRequestProperty("Accept", "*/*");
            conn.setInstanceFollowRedirects(true);

            int code = conn.getResponseCode();
            if (code >= 200 && code < 300) {
                InputStream is = conn.getInputStream();
                try {
                    return readStream(is);
                } finally {
                    try { is.close(); } catch (IOException e) { /* ignore */ }
                }
            } else {
                log("HTTP " + code + " for " + urlStr);
                return null;
            }
        } catch (Exception e) {
            log("GET failed: " + urlStr + " - " + e.getMessage());
            return null;
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    private boolean httpDownload(String urlStr, File dest) {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(urlStr);
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            conn.setRequestProperty("User-Agent", "Meshare/1.0");
            conn.setInstanceFollowRedirects(true);

            int code = conn.getResponseCode();
            if (code < 200 || code >= 300) {
                log("HTTP " + code + " for " + urlStr);
                return false;
            }
            File parent = dest.getParentFile();
            if (parent != null) parent.mkdirs();
            InputStream is = conn.getInputStream();
            FileOutputStream fos = new FileOutputStream(dest);
            try {
                byte[] buf = new byte[16 * 1024];
                int n;
                while ((n = is.read(buf)) >= 0) fos.write(buf, 0, n);
            } finally {
                try { is.close(); } catch (IOException e) { /* ignore */ }
                try { fos.close(); } catch (IOException e) { /* ignore */ }
            }
            return true;
        } catch (Exception e) {
            log("Download failed: " + urlStr + " - " + e.getMessage());
            return false;
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    private static String readStream(InputStream is) throws IOException {
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        byte[] buf = new byte[16 * 1024];
        int n;
        while ((n = is.read(buf)) >= 0) baos.write(buf, 0, n);
        return new String(baos.toByteArray(), StandardCharsets.UTF_8);
    }

    private static String readFile(File f) {
        if (!f.exists() || !f.isFile()) return null;
        FileInputStream fis = null;
        try {
            fis = new FileInputStream(f);
            return readStream(fis);
        } catch (IOException e) {
            return null;
        } finally {
            if (fis != null) try { fis.close(); } catch (IOException e) { /* ignore */ }
        }
    }

    // ===================================================================
    //                          hash & log
    // ===================================================================
    private boolean verifyHash(File file, String expectedHash) {
        FileInputStream fis = null;
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            fis = new FileInputStream(file);
            byte[] buf = new byte[16 * 1024];
            int n;
            while ((n = fis.read(buf)) >= 0) md.update(buf, 0, n);
            byte[] digest = md.digest();
            StringBuilder hex = new StringBuilder(64);
            for (byte b : digest) hex.append(String.format("%02x", b));
            return hex.toString().equalsIgnoreCase(expectedHash);
        } catch (Exception e) {
            log("Hash verify error: " + e.getMessage());
            return false;
        } finally {
            if (fis != null) try { fis.close(); } catch (IOException e) { /* ignore */ }
        }
    }

    private void writeLogFile(String server, String hash, String ext, Map<String, String> fields) {
        try {
            String filename = fields != null ? fields.get("filename") : null;
            String size     = fields != null ? fields.get("size")     : null;
            if (filename == null) filename = "";
            if (size     == null) size     = "";

            Map<String, Object> data = new LinkedHashMap<String, Object>();
            data.put("filename",  filename);
            data.put("hash",      hash);
            data.put("extension", ext);
            data.put("size",      size);
            data.put("server",    server);
            data.put("date",      new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX").format(new Date()));

            String logName = sanitizeFilename(filename);
            if (logName.isEmpty()) {
                logName = hash;
            } else {
                logName = logName + "_" + hash.substring(0, Math.min(8, hash.length()));
            }
            File logFile = new File(LOG_DIR, logName + ".log.json");
            FileWriter w = null;
            try {
                w = new FileWriter(logFile);
                w.write(toJsonObject(data));
            } finally {
                if (w != null) try { w.close(); } catch (IOException e) { /* ignore */ }
            }
        } catch (IOException e) {
            log("Error writing log: " + e.getMessage());
        }
    }

    private void addToLocalFilesList(String filename) {
        File f = new File(LOCAL_FILES_LIST);
        Set<String> existing = new HashSet<String>();
        if (f.exists()) {
            BufferedReader r = null;
            try {
                r = new BufferedReader(new InputStreamReader(new FileInputStream(f), StandardCharsets.UTF_8));
                String line;
                while ((line = r.readLine()) != null) {
                    String t = line.trim();
                    if (!t.isEmpty()) existing.add(t);
                }
            } catch (IOException e) { /* ignore */ }
            finally {
                if (r != null) try { r.close(); } catch (IOException e) { /* ignore */ }
            }
        }
        if (existing.add(filename)) {
            FileWriter w = null;
            try {
                w = new FileWriter(f, true);
                w.write(filename + "\n");
            } catch (IOException e) {
                log("Error updating files.txt: " + e.getMessage());
            } finally {
                if (w != null) try { w.close(); } catch (IOException e) { /* ignore */ }
            }
        }
    }

    // ===================================================================
    //                          JSON
    // ===================================================================
    public static Map<String, String> parseJson(String text) {
        Map<String, String> result = new LinkedHashMap<String, String>();
        if (text == null) return result;
        int i = skipWs(text, 0);
        if (i >= text.length() || text.charAt(i) != '{') return result;
        i++;
        i = skipWs(text, i);
        if (i < text.length() && text.charAt(i) == '}') return result;
        while (i < text.length()) {
            i = skipWs(text, i);
            if (i >= text.length() || text.charAt(i) != '"') break;
            Object[] keyRes = parseString(text, i);
            if (keyRes == null) break;
            String key = (String) keyRes[0];
            i = ((Integer) keyRes[1]).intValue();
            i = skipWs(text, i);
            if (i >= text.length() || text.charAt(i) != ':') break;
            i++;
            i = skipWs(text, i);
            String value;
            if (i < text.length() && text.charAt(i) == '"') {
                Object[] strRes = parseString(text, i);
                if (strRes == null) break;
                value = (String) strRes[0];
                i = ((Integer) strRes[1]).intValue();
            } else if (i < text.length() && text.charAt(i) == '{') {
                // skip nested object
                int start = i;
                int depth = 0;
                while (i < text.length()) {
                    char c = text.charAt(i);
                    if (c == '"') {
                        Object[] sres = parseString(text, i);
                        if (sres == null) break;
                        i = ((Integer) sres[1]).intValue();
                        continue;
                    }
                    if (c == '{') depth++;
                    else if (c == '}') { depth--; if (depth == 0) { i++; break; } }
                    i++;
                }
                value = text.substring(start, i);
            } else if (i < text.length() && text.charAt(i) == '[') {
                int start = i;
                int depth = 0;
                while (i < text.length()) {
                    char c = text.charAt(i);
                    if (c == '"') {
                        Object[] sres = parseString(text, i);
                        if (sres == null) break;
                        i = ((Integer) sres[1]).intValue();
                        continue;
                    }
                    if (c == '[') depth++;
                    else if (c == ']') { depth--; if (depth == 0) { i++; break; } }
                    i++;
                }
                value = text.substring(start, i);
            } else {
                int start = i;
                while (i < text.length()) {
                    char c = text.charAt(i);
                    if (c == ',' || c == '}' || Character.isWhitespace(c)) break;
                    i++;
                }
                value = text.substring(start, i);
            }
            if (value.length() > MAX_FIELD_LENGTH) {
                value = value.substring(0, MAX_FIELD_LENGTH) + "...";
            }
            result.put(key, value);
            i = skipWs(text, i);
            if (i < text.length() && text.charAt(i) == ',') {
                i++;
            } else if (i < text.length() && text.charAt(i) == '}') {
                break;
            } else {
                break;
            }
        }
        return result;
    }

    private static int skipWs(String text, int i) {
        while (i < text.length() && Character.isWhitespace(text.charAt(i))) i++;
        return i;
    }

    /** Returns Object[]{value, Integer(nextIndex)} on success, or null on failure. */
    private static Object[] parseString(String text, int i) {
        if (i >= text.length() || text.charAt(i) != '"') return null;
        i++;
        StringBuilder sb = new StringBuilder();
        while (i < text.length()) {
            char c = text.charAt(i);
            if (c == '"') {
                return new Object[] { sb.toString(), Integer.valueOf(i + 1) };
            } else if (c == '\\') {
                if (i + 1 >= text.length()) break;
                char n = text.charAt(i + 1);
                switch (n) {
                    case '"':  sb.append('"');  break;
                    case '\\': sb.append('\\'); break;
                    case '/':  sb.append('/');  break;
                    case 'n':  sb.append('\n'); break;
                    case 'r':  sb.append('\r'); break;
                    case 't':  sb.append('\t'); break;
                    case 'b':  sb.append('\b'); break;
                    case 'f':  sb.append('\f'); break;
                    case 'u':
                        if (i + 5 < text.length()) {
                            try {
                                int code = Integer.parseInt(text.substring(i + 2, i + 6), 16);
                                sb.append((char) code);
                                i += 4;
                            } catch (Exception e) { sb.append(n); }
                        } else {
                            sb.append(n);
                        }
                        break;
                    default: sb.append(n);
                }
                i += 2;
            } else {
                sb.append(c);
                i++;
            }
        }
        return null;
    }

    public static String toJsonObject(Map<String, Object> map) {
        StringBuilder sb = new StringBuilder("{");
        boolean first = true;
        for (Map.Entry<String, Object> e : map.entrySet()) {
            if (!first) sb.append(',');
            first = false;
            sb.append('"').append(escapeJsonString(e.getKey())).append('"').append(':');
            Object v = e.getValue();
            if (v == null) {
                sb.append("null");
            } else if (v instanceof Boolean) {
                sb.append(((Boolean) v).booleanValue() ? "true" : "false");
            } else if (v instanceof Number) {
                sb.append(v.toString());
            } else {
                sb.append('"').append(escapeJsonString(v.toString())).append('"');
            }
        }
        sb.append('}');
        return sb.toString();
    }

    public static String escapeJsonString(String s) {
        if (s == null) return "";
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"':  sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\n': sb.append("\\n");  break;
                case '\r': sb.append("\\r");  break;
                case '\t': sb.append("\\t");  break;
                case '\b': sb.append("\\b");  break;
                case '\f': sb.append("\\f");  break;
                default:
                    if (c < 0x20) {
                        sb.append(String.format("\\u%04x", (int) c));
                    } else {
                        sb.append(c);
                    }
            }
        }
        return sb.toString();
    }

    // ===================================================================
    //                          details / view
    // ===================================================================
    private void updateDetails() {
        int row = resultTable.getSelectedRow();
        if (row < 0) {
            detailsArea.setText("Select a row to see details.");
            return;
        }
        int modelRow = resultTable.convertRowIndexToModel(row);
        if (modelRow < 0 || modelRow >= currentResults.size()) {
            detailsArea.setText("");
            return;
        }
        FileEntry entry = currentResults.get(modelRow);
        StringBuilder sb = new StringBuilder();
        sb.append("Server:           ").append(safe(entry.server)).append('\n');
        sb.append("Hash:             ").append(safe(entry.hash)).append('\n');
        sb.append("Extension:        ").append(safe(entry.extension)).append('\n');
        sb.append("Filename:         ").append(safe(entry.filename)).append('\n');
        sb.append("Size:             ").append(safe(entry.size)).append('\n');
        sb.append("Description:      ").append(safe(entry.description)).append('\n');
        sb.append("Date:             ").append(safe(entry.date)).append('\n');
        sb.append("Public key:       ").append(safe(entry.publicKey)).append('\n');
        sb.append("Server pub key:   ").append(safe(entry.serverPublicKey)).append('\n');
        sb.append("Node info:        ").append(safe(entry.nodeInfo)).append('\n');
        sb.append("Status:           ").append(safe(entry.status)).append('\n');
        sb.append("Local binary:     ").append(new File(FILES_DIR, entry.hash + "." + entry.extension).getAbsolutePath()).append('\n');
        sb.append("Local info:       ").append(new File(INFO_DIR,  entry.hash + ".json").getAbsolutePath()).append('\n');
        if (entry.rawJson != null && !entry.rawJson.isEmpty()) {
            sb.append("--- Raw JSON ---\n");
            sb.append(entry.rawJson);
        }
        detailsArea.setText(sb.toString());
        detailsArea.setCaretPosition(0);
    }

    private void viewSelectedJson() {
        int row = resultTable.getSelectedRow();
        if (row < 0) {
            JOptionPane.showMessageDialog(this, "Select a row first");
            return;
        }
        int modelRow = resultTable.convertRowIndexToModel(row);
        if (modelRow < 0 || modelRow >= currentResults.size()) return;
        FileEntry entry = currentResults.get(modelRow);
        String text = entry.rawJson;
        if (text == null || text.isEmpty()) {
            File localInfo = new File(INFO_DIR, entry.hash + ".json");
            text = readFile(localInfo);
        }
        if (text == null) text = "(no JSON available)";

        JTextArea ta = new JTextArea(20, 70);
        ta.setText(text);
        ta.setEditable(false);
        ta.setFont(new Font(Font.MONOSPACED, Font.PLAIN, 12));
        JScrollPane sp = new JScrollPane(ta);
        JOptionPane.showMessageDialog(this, sp,
                "JSON: " + entry.getDisplayFilename(),
                JOptionPane.INFORMATION_MESSAGE);
    }

    private void openFilesFolder() {
        try {
            File f = new File(FILES_DIR).getAbsoluteFile();
            if (!f.exists()) f.mkdirs();
            Desktop.getDesktop().open(f);
        } catch (Exception e) {
            log("Cannot open folder: " + e.getMessage());
        }
    }

    private void revealInFolder() {
        int row = resultTable.getSelectedRow();
        if (row < 0) return;
        int modelRow = resultTable.convertRowIndexToModel(row);
        if (modelRow < 0 || modelRow >= currentResults.size()) return;
        FileEntry entry = currentResults.get(modelRow);
        File f = new File(FILES_DIR, entry.hash + "." + entry.extension);
        if (!f.exists()) {
            log("File not present locally: " + f.getName());
            return;
        }
        try {
            Desktop.getDesktop().open(f.getParentFile());
        } catch (Exception e) {
            log("Cannot open: " + e.getMessage());
        }
    }

    // ===================================================================
    //                          logging / utils
    // ===================================================================
    private void log(String message) {
        final String line = "[" +
                new SimpleDateFormat("HH:mm:ss").format(new Date()) + "] " + message;
        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                logArea.append(line + "\n");
                // Cap the log area
                int len = logArea.getDocument().getLength();
                if (len > 200000) {
                    String t = logArea.getText();
                    int cut = t.length() / 2;
                    int nl = t.indexOf('\n', cut);
                    if (nl > 0) logArea.setText(t.substring(nl + 1));
                }
                String full = logArea.getText();
                int lines = 0;
                for (int i = 0; i < full.length(); i++) if (full.charAt(i) == '\n') lines++;
                if (lines > MAX_LOG_LINES) {
                    int cut = full.length() / 2;
                    int nl = full.indexOf('\n', cut);
                    if (nl > 0) logArea.setText(full.substring(nl + 1));
                }
                logArea.setCaretPosition(logArea.getDocument().getLength());
            }
        });
    }

    private void setStatus(String text) {
        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() { statusLabel.setText(text); }
        });
    }

    private static String truncate(String s, int max) {
        if (s == null) return "";
        if (s.length() <= max) return s;
        return s.substring(0, Math.max(0, max - 3)) + "...";
    }

    private static String safe(String s) { return s == null ? "" : s; }

    private static String sanitizeFilename(String name) {
        if (name == null) return "";
        String n = name.replaceAll("[\\\\/:*?\"<>|]", "_").trim();
        if (n.length() > 80) n = n.substring(0, 80);
        return n;
    }

    /** Parse "<hash>.<ext>" where hash must be a 64-char hex SHA-256. */
    private static String[] parseFileLine(String line) {
        if (line == null) return null;
        line = line.trim();
        if (line.isEmpty()) return null;
        int dot = line.lastIndexOf('.');
        if (dot <= 0 || dot >= line.length() - 1) return null;
        String hash = line.substring(0, dot);
        String ext  = line.substring(dot + 1);
        if (!isValidSha256(hash)) return null;
        if (ext.isEmpty()) return null;
        if (ext.indexOf('/') >= 0 || ext.indexOf('\\') >= 0) return null;
        if (ext.length() > 16) return null;
        return new String[] { hash, ext };
    }

    private static boolean isValidSha256(String s) {
        if (s == null || s.length() != 64) return false;
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            boolean ok = (c >= '0' && c <= '9')
                      || (c >= 'a' && c <= 'f')
                      || (c >= 'A' && c <= 'F');
            if (!ok) return false;
        }
        return true;
    }

    private void populateEntry(FileEntry entry, Map<String, String> fields) {
        entry.rawFields.putAll(fields);
        entry.filename        = fields.getOrDefault("filename", "");
        entry.size            = fields.getOrDefault("size", "");
        String jsonExt        = fields.get("extension");
        if (jsonExt != null && !jsonExt.isEmpty()) entry.extension = jsonExt;
        entry.publicKey       = fields.getOrDefault("public_key", "");
        entry.serverPublicKey = fields.getOrDefault("server_public_key", "");
        entry.nodeInfo        = fields.getOrDefault("node_info", "");
        entry.description     = fields.getOrDefault("description", "");
        entry.date            = fields.getOrDefault("date", "");
    }

    // ===================================================================
    //                          main
    // ===================================================================
    public static void main(String[] args) {
        try {
            UIManager.setLookAndFeel(UIManager.getSystemLookAndFeelClassName());
        } catch (Exception e) { /* fall back to default */ }

        SwingUtilities.invokeLater(new Runnable() {
            @Override public void run() {
                Meshare app = new Meshare();
                app.setVisible(true);
            }
        });
    }
}
