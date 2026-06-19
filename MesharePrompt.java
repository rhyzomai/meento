import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.io.OutputStreamWriter;
import java.io.Writer;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Random;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * MesharePrompt
 *
 * Single-file, prompt-based (console) Java 8 program with no external
 * dependencies. It reads a list of servers from "servers.txt", and for
 * each server fetches "<server>/files.txt" (a list of "<sha256>.<ext>"
 * filenames, one per line). For every valid entry it can fetch the
 * matching metadata JSON from "<server>/info/<sha256>.json" and let the
 * user search/download matched files (binary from "<server>/files/<name>"
 * plus its JSON sidecar from "<server>/info/<hash>.json").
 *
 * Usage (interactive prompt):
 *   - Type any plain text and press Enter to search across all servers.
 *     Results stream in as they are found (max 200), each with a number.
 *   - After a search, type a result number to download that single file
 *     (binary + json), or type "all" to download every matched result.
 *   - Type "-options" to open the settings menu (toggle random-tries
 *     mode and its count, change result limit, etc).
 *   - Type "-getall" to download EVERY file (binary + json) from EVERY
 *     server, regardless of search.
 *   - Type "-help" to show the command list again.
 *   - Type "-exit" or "-quit" to leave the program.
 *
 * Local layout (created next to where the program is run):
 *   ./files.txt   -> servers root listing (input, also used as cache/list)
 *   ./servers.txt -> list of server base URLs, one per line
 *   ./files/      -> downloaded binary files (named <hash>.<ext>)
 *   ./info/       -> downloaded JSON metadata (named <hash>.json)
 *   ./log/        -> one JSON file per successful download, logging date
 *
 * Rules implemented per spec:
 *   - files.txt lines must match "<sha256-hex-64><.ext>" or are skipped.
 *   - A binary file is only downloaded if its sha256 actually matches the
 *     hash encoded in its filename; if it does not match, neither the
 *     binary nor its json sidecar is kept/downloaded.
 *   - If a hash is already present locally (already downloaded before),
 *     the program skips re-requesting its info JSON and skips
 *     re-downloading it entirely, but still shows it in results using
 *     the locally cached JSON metadata.
 *   - Each JSON field value is truncated to a maximum of 512 characters.
 *   - At most 200 results are shown per search.
 *   - "-options" lets the user enable "random tries": instead of
 *     checking every line of a server's files.txt, only N random lines
 *     are picked and checked.
 */
public class MesharePrompt {

    // ---------------------------------------------------------------
    // Constants / configuration
    // ---------------------------------------------------------------

    private static final String SERVERS_FILE = "servers.txt";
    private static final String FILES_DIR = "files";
    private static final String INFO_DIR = "info";
    private static final String LOG_DIR = "log";

    private static final int MAX_RESULTS = 200;
    private static final int MAX_FIELD_LEN = 512;
    private static final int CONNECT_TIMEOUT_MS = 8000;
    private static final int READ_TIMEOUT_MS = 15000;
    private static final int THREAD_POOL_SIZE = 8;

    private static final Pattern HASH_FILENAME_PATTERN =
            Pattern.compile("^([a-fA-F0-9]{64})\\.([A-Za-z0-9]{1,15})$");

    // ---------------------------------------------------------------
    // Mutable session options (changed via "-options")
    // ---------------------------------------------------------------

    private boolean randomTriesEnabled = false;
    private int randomTriesCount = 10;
    private int resultLimit = MAX_RESULTS;

    // ---------------------------------------------------------------
    // Session state
    // ---------------------------------------------------------------

    private final List<String> servers = new ArrayList<String>();

    // Hashes already present locally (files/<hash>.<ext> AND info/<hash>.json)
    private final Set<String> alreadyDownloadedHashes = new HashSet<String>();

    // Last search results, indexed 1..N as shown to the user
    private final List<FileEntry> lastResults = new ArrayList<FileEntry>();

    private final BufferedReader stdin =
            new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));

    public static void main(String[] args) {
        MesharePrompt app = new MesharePrompt();
        app.run();
    }

    // ---------------------------------------------------------------
    // Main loop
    // ---------------------------------------------------------------

    private void run() {
        printBanner();
        ensureLocalDirs();
        loadServers();
        indexAlreadyDownloaded();

        printHelp();

        while (true) {
            System.out.print("\n> ");
            String line = readLine();
            if (line == null) {
                break; // EOF (stdin closed)
            }
            line = line.trim();
            if (line.isEmpty()) {
                continue;
            }

            try {
                if (line.equalsIgnoreCase("-exit") || line.equalsIgnoreCase("-quit")) {
                    System.out.println("Bye.");
                    break;
                } else if (line.equalsIgnoreCase("-help")) {
                    printHelp();
                } else if (line.equalsIgnoreCase("-options")) {
                    openOptionsMenu();
                } else if (line.equalsIgnoreCase("-getall")) {
                    downloadEverythingFromAllServers();
                } else if (line.equalsIgnoreCase("all")) {
                    downloadAllLastResults();
                } else if (isInteger(line)) {
                    downloadResultByIndex(Integer.parseInt(line));
                } else if (line.startsWith("-")) {
                    System.out.println("Unknown command: " + line + " (type -help)");
                } else {
                    doSearch(line);
                }
            } catch (Exception e) {
                System.out.println("Error: " + e.getMessage());
            }
        }
    }

    private void printBanner() {
        System.out.println("=========================================");
        System.out.println(" MesharePrompt");
        System.out.println("=========================================");
    }

    private void printHelp() {
        System.out.println();
        System.out.println("Type any text to search across all servers (matches any JSON field).");
        System.out.println("After results appear:");
        System.out.println("  <number>   download that single result (file + json)");
        System.out.println("  all        download every result from the last search");
        System.out.println("Commands:");
        System.out.println("  -options   open settings (random tries, result limit)");
        System.out.println("  -getall    download EVERY file from EVERY server (files + info)");
        System.out.println("  -help      show this help again");
        System.out.println("  -exit      quit the program");
        System.out.println();
        System.out.println("Current options: random tries = " + (randomTriesEnabled
                ? ("ON (" + randomTriesCount + " per server)") : "OFF")
                + " | result limit = " + resultLimit);
    }

    private String readLine() {
        try {
            return stdin.readLine();
        } catch (IOException e) {
            return null;
        }
    }

    private boolean isInteger(String s) {
        if (s.isEmpty()) return false;
        for (int i = 0; i < s.length(); i++) {
            if (!Character.isDigit(s.charAt(i))) return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Setup: directories, servers.txt, local index
    // ---------------------------------------------------------------

    private void ensureLocalDirs() {
        new File(FILES_DIR).mkdirs();
        new File(INFO_DIR).mkdirs();
        new File(LOG_DIR).mkdirs();
    }

    private void loadServers() {
        File f = new File(SERVERS_FILE);
        if (!f.exists()) {
            System.out.println("WARNING: " + SERVERS_FILE + " not found. No servers to query.");
            return;
        }
        List<String> lines = readAllLinesLocal(f);
        for (String raw : lines) {
            String s = raw.trim();
            if (s.isEmpty()) continue;
            // normalize: drop trailing slash for consistent concatenation
            while (s.endsWith("/")) {
                s = s.substring(0, s.length() - 1);
            }
            if (!servers.contains(s)) {
                servers.add(s);
            }
        }
        System.out.println("Loaded " + servers.size() + " server(s) from " + SERVERS_FILE + ".");
    }

    /** Builds the in-memory set of hashes we already have both files/<hash>.* and info/<hash>.json for. */
    private void indexAlreadyDownloaded() {
        File infoDir = new File(INFO_DIR);
        File filesDir = new File(FILES_DIR);
        File[] infoFiles = infoDir.listFiles();
        if (infoFiles == null) return;

        for (File jf : infoFiles) {
            String name = jf.getName();
            if (!name.toLowerCase().endsWith(".json")) continue;
            String hash = name.substring(0, name.length() - 5);
            if (!isValidSha256Hex(hash)) continue;

            // Confirm a matching binary exists in files/ with this hash as basename
            File[] matches = filesDir.listFiles(new HashPrefixFilter(hash));
            if (matches != null && matches.length > 0) {
                alreadyDownloadedHashes.add(hash.toLowerCase());
            }
        }
        if (!alreadyDownloadedHashes.isEmpty()) {
            System.out.println("Found " + alreadyDownloadedHashes.size()
                    + " file(s) already downloaded locally; these will be skipped on the network "
                    + "but still shown in results.");
        }
    }

    private static class HashPrefixFilter implements java.io.FilenameFilter {
        private final String prefix;
        HashPrefixFilter(String hash) { this.prefix = hash.toLowerCase() + "."; }
        public boolean accept(File dir, String name) {
            return name.toLowerCase().startsWith(prefix);
        }
    }

    private boolean isValidSha256Hex(String s) {
        if (s.length() != 64) return false;
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            boolean hex = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
            if (!hex) return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Options menu
    // ---------------------------------------------------------------

    private void openOptionsMenu() {
        while (true) {
            System.out.println();
            System.out.println("--- Options ---");
            System.out.println("1) Random tries: " + (randomTriesEnabled ? "ON" : "OFF"));
            System.out.println("2) Random tries count: " + randomTriesCount);
            System.out.println("3) Result limit (max " + MAX_RESULTS + "): " + resultLimit);
            System.out.println("0) Back to main prompt");
            System.out.print("option> ");
            String choice = readLine();
            if (choice == null) return;
            choice = choice.trim();

            if (choice.equals("0") || choice.equalsIgnoreCase("back")) {
                return;
            } else if (choice.equals("1")) {
                randomTriesEnabled = !randomTriesEnabled;
                System.out.println("Random tries is now " + (randomTriesEnabled ? "ON" : "OFF") + ".");
            } else if (choice.equals("2")) {
                System.out.print("Enter number of random tries per server: ");
                String v = readLine();
                try {
                    int n = Integer.parseInt(v.trim());
                    if (n <= 0) throw new NumberFormatException();
                    randomTriesCount = n;
                    System.out.println("Random tries count set to " + n + ".");
                } catch (Exception e) {
                    System.out.println("Invalid number, unchanged.");
                }
            } else if (choice.equals("3")) {
                System.out.print("Enter result limit (1-" + MAX_RESULTS + "): ");
                String v = readLine();
                try {
                    int n = Integer.parseInt(v.trim());
                    if (n <= 0 || n > MAX_RESULTS) throw new NumberFormatException();
                    resultLimit = n;
                    System.out.println("Result limit set to " + n + ".");
                } catch (Exception e) {
                    System.out.println("Invalid number, must be between 1 and " + MAX_RESULTS + ". Unchanged.");
                }
            } else {
                System.out.println("Unknown option.");
            }
        }
    }

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------

    /**
     * Searches all servers concurrently for entries whose JSON metadata
     * (any field) contains the query substring (case-insensitive).
     * Streams results to the console as they arrive, up to resultLimit.
     */
    private void doSearch(String query) {
        lastResults.clear();
        final String needle = query.toLowerCase();

        if (servers.isEmpty()) {
            System.out.println("No servers configured.");
            return;
        }

        System.out.println("Searching for \"" + query + "\" across " + servers.size() + " server(s)...");

        final LinkedBlockingQueue<FileEntry> matchQueue = new LinkedBlockingQueue<FileEntry>();
        final Object printLock = new Object();
        final int[] shownCount = {0};
        final boolean[] limitReached = {false};

        ExecutorService pool = Executors.newFixedThreadPool(Math.min(THREAD_POOL_SIZE, servers.size()));

        // Consumer thread: prints results as they arrive, stops accepting after limit.
        Thread printer = new Thread(new Runnable() {
            public void run() {
                while (true) {
                    FileEntry entry;
                    try {
                        entry = matchQueue.poll(200, TimeUnit.MILLISECONDS);
                    } catch (InterruptedException ie) {
                        return;
                    }
                    if (entry == POISON) {
                        return;
                    }
                    if (entry == null) {
                        continue;
                    }
                    synchronized (printLock) {
                        if (shownCount[0] >= resultLimit) {
                            limitReached[0] = true;
                            continue;
                        }
                        shownCount[0]++;
                        lastResults.add(entry);
                        printResultLine(shownCount[0], entry);
                    }
                }
            }
        });
        printer.start();

        List<java.util.concurrent.Future<?>> futures = new ArrayList<java.util.concurrent.Future<?>>();
        for (final String server : servers) {
            futures.add(pool.submit(new Runnable() {
                public void run() {
                    searchServer(server, needle, matchQueue, printLock, shownCount, resultLimit);
                }
            }));
        }

        for (java.util.concurrent.Future<?> fut : futures) {
            try {
                fut.get();
            } catch (Exception e) {
                // individual server errors are already handled/logged inside searchServer
            }
        }
        pool.shutdown();

        // signal printer to stop, give it a moment to flush
        try {
            matchQueue.put(POISON);
        } catch (InterruptedException ignored) {
        }
        try {
            printer.join(2000);
        } catch (InterruptedException ignored) {
        }

        System.out.println();
        if (shownCount[0] == 0) {
            System.out.println("No matches found.");
        } else {
            System.out.println(shownCount[0] + " result(s) shown"
                    + (limitReached[0] ? " (limit of " + resultLimit + " reached, more may exist)" : "")
                    + ". Type a number to download it, or \"all\" to download all of them.");
        }
    }

    private static final FileEntry POISON = new FileEntry();

    private void printResultLine(int index, FileEntry entry) {
        StringBuilder sb = new StringBuilder();
        sb.append("[").append(index).append("] ");
        sb.append(entry.hash).append(".").append(entry.extension);
        sb.append("  (server: ").append(entry.server).append(")");
        if (entry.alreadyDownloaded) {
            sb.append("  [ALREADY DOWNLOADED]");
        }
        System.out.println(sb.toString());
        String filename = entry.json.get("filename");
        String description = entry.json.get("description");
        if (filename != null && !filename.isEmpty()) {
            System.out.println("      filename: " + filename);
        }
        if (description != null && !description.isEmpty()) {
            System.out.println("      description: " + description);
        }
        String size = entry.json.get("size");
        if (size != null && !size.isEmpty()) {
            System.out.println("      size: " + size + " bytes");
        }
    }

    /**
     * Fetches and scans one server's files.txt, checking (all or random
     * subset of) entries against the search query, pushing matches to
     * the shared queue as they are confirmed.
     */
    private void searchServer(String server, String needle, LinkedBlockingQueue<FileEntry> out,
                               Object printLock, int[] shownCount, int limit) {
        List<String> lines;
        try {
            String filesTxtUrl = server + "/files.txt";
            String content = httpGetText(filesTxtUrl);
            lines = splitLines(content);
        } catch (Exception e) {
            synchronized (printLock) {
                System.out.println("[" + server + "] could not fetch files.txt: " + e.getMessage());
            }
            return;
        }

        List<String[]> validEntries = new ArrayList<String[]>(); // [hash, ext, originalName]
        for (String raw : lines) {
            String name = raw.trim();
            if (name.isEmpty()) continue;
            Matcher m = HASH_FILENAME_PATTERN.matcher(name);
            if (!m.matches()) {
                continue; // skip malformed lines per spec
            }
            validEntries.add(new String[]{m.group(1).toLowerCase(), m.group(2), name});
        }

        if (validEntries.isEmpty()) {
            return;
        }

        List<String[]> toCheck;
        if (randomTriesEnabled) {
            toCheck = pickRandomSubset(validEntries, randomTriesCount);
        } else {
            toCheck = validEntries;
        }

        for (String[] e : toCheck) {
            synchronized (printLock) {
                if (shownCount[0] >= limit) {
                    return; // stop early, limit already hit
                }
            }
            String hash = e[0];
            String ext = e[1];

            FileEntry fe;
            if (alreadyDownloadedHashes.contains(hash)) {
                // Skip network request entirely; load cached json from disk to show info
                fe = buildEntryFromLocalCache(server, hash, ext);
                if (fe == null) {
                    continue;
                }
            } else {
                fe = fetchEntryInfo(server, hash, ext);
                if (fe == null) {
                    continue; // fetch failed or invalid json
                }
            }

            if (matchesQuery(fe, needle)) {
                try {
                    out.put(fe);
                } catch (InterruptedException ignored) {
                }
            }
        }
    }

    private List<String[]> pickRandomSubset(List<String[]> source, int n) {
        if (n >= source.size()) {
            return source;
        }
        List<String[]> copy = new ArrayList<String[]>(source);
        java.util.Collections.shuffle(copy, new Random());
        return copy.subList(0, n);
    }

    private boolean matchesQuery(FileEntry entry, String needleLower) {
        if (needleLower.isEmpty()) return true;
        if (entry.hash.toLowerCase().contains(needleLower)) return true;
        if (entry.extension.toLowerCase().contains(needleLower)) return true;
        for (String value : entry.json.values()) {
            if (value != null && value.toLowerCase().contains(needleLower)) {
                return true;
            }
        }
        return false;
    }

    /** Fetches info/<hash>.json from the server and parses it into a FileEntry, with field truncation. */
    private FileEntry fetchEntryInfo(String server, String hash, String ext) {
        String infoUrl = server + "/info/" + hash + ".json";
        String jsonText;
        try {
            jsonText = httpGetText(infoUrl);
        } catch (Exception e) {
            return null;
        }
        java.util.Map<String, String> fields = SimpleJson.parseObjectTruncated(jsonText, MAX_FIELD_LEN);
        if (fields == null) {
            return null;
        }
        FileEntry fe = new FileEntry();
        fe.server = server;
        fe.hash = hash;
        fe.extension = ext;
        fe.json = fields;
        fe.alreadyDownloaded = false;
        return fe;
    }

    /** Builds a FileEntry from the local cached info/<hash>.json (used when already downloaded). */
    private FileEntry buildEntryFromLocalCache(String server, String hash, String ext) {
        File jf = new File(INFO_DIR, hash + ".json");
        if (!jf.exists()) return null;
        String jsonText;
        try {
            jsonText = readFileAsString(jf);
        } catch (IOException e) {
            return null;
        }
        java.util.Map<String, String> fields = SimpleJson.parseObjectTruncated(jsonText, MAX_FIELD_LEN);
        if (fields == null) {
            fields = new java.util.LinkedHashMap<String, String>();
        }
        FileEntry fe = new FileEntry();
        fe.server = server;
        fe.hash = hash;
        fe.extension = ext;
        fe.json = fields;
        fe.alreadyDownloaded = true;
        return fe;
    }

    // ---------------------------------------------------------------
    // Downloading: single result / all results / everything
    // ---------------------------------------------------------------

    private void downloadResultByIndex(int idx) {
        if (idx < 1 || idx > lastResults.size()) {
            System.out.println("No such result number. Run a search first.");
            return;
        }
        FileEntry entry = lastResults.get(idx - 1);
        downloadOne(entry);
    }

    private void downloadAllLastResults() {
        if (lastResults.isEmpty()) {
            System.out.println("No results to download. Run a search first.");
            return;
        }
        System.out.println("Downloading " + lastResults.size() + " result(s)...");
        int ok = 0;
        for (FileEntry entry : lastResults) {
            if (downloadOne(entry)) ok++;
        }
        System.out.println("Done. " + ok + "/" + lastResults.size() + " downloaded successfully.");
    }

    /** Downloads the binary + ensures json sidecar is saved for one entry, verifying the sha256 hash. */
    private boolean downloadOne(FileEntry entry) {
        if (entry.alreadyDownloaded) {
            System.out.println(entry.hash + "." + entry.extension + " already downloaded, skipping.");
            return true;
        }

        String fileUrl = entry.server + "/files/" + entry.hash + "." + entry.extension;
        File tmp;
        try {
            tmp = downloadToTempFile(fileUrl);
        } catch (Exception e) {
            System.out.println("FAILED to download " + fileUrl + ": " + e.getMessage());
            return false;
        }

        String actualHash;
        try {
            actualHash = sha256OfFile(tmp);
        } catch (Exception e) {
            tmp.delete();
            System.out.println("FAILED to hash downloaded file for " + entry.hash + ": " + e.getMessage());
            return false;
        }

        if (!actualHash.equalsIgnoreCase(entry.hash)) {
            tmp.delete();
            System.out.println("HASH MISMATCH for " + entry.hash + "." + entry.extension
                    + " (got " + actualHash + ") - discarding binary and json, not saved.");
            return false;
        }

        File destFile = new File(FILES_DIR, entry.hash + "." + entry.extension);
        File destJson = new File(INFO_DIR, entry.hash + ".json");

        try {
            moveFile(tmp, destFile);
            writeJsonSidecar(destJson, entry.json);
            writeDownloadLog(entry.hash + "." + entry.extension);
            alreadyDownloadedHashes.add(entry.hash.toLowerCase());
            entry.alreadyDownloaded = true;
            System.out.println("OK: saved " + destFile.getPath() + " and " + destJson.getPath());
            return true;
        } catch (IOException e) {
            System.out.println("FAILED to save files for " + entry.hash + ": " + e.getMessage());
            return false;
        }
    }

    /** "-getall": download every valid entry from every server, files+info, ignoring search/match. */
    private void downloadEverythingFromAllServers() {
        if (servers.isEmpty()) {
            System.out.println("No servers configured.");
            return;
        }
        System.out.println("Downloading ALL files from ALL " + servers.size() + " server(s)...");

        final int[] total = {0};
        final int[] ok = {0};
        final Object lock = new Object();

        ExecutorService pool = Executors.newFixedThreadPool(Math.min(THREAD_POOL_SIZE, servers.size()));
        List<java.util.concurrent.Future<?>> futures = new ArrayList<java.util.concurrent.Future<?>>();

        for (final String server : servers) {
            futures.add(pool.submit(new Runnable() {
                public void run() {
                    List<String> lines;
                    try {
                        lines = splitLines(httpGetText(server + "/files.txt"));
                    } catch (Exception e) {
                        synchronized (lock) {
                            System.out.println("[" + server + "] could not fetch files.txt: " + e.getMessage());
                        }
                        return;
                    }
                    for (String raw : lines) {
                        String name = raw.trim();
                        if (name.isEmpty()) continue;
                        Matcher m = HASH_FILENAME_PATTERN.matcher(name);
                        if (!m.matches()) continue;
                        String hash = m.group(1).toLowerCase();
                        String ext = m.group(2);

                        synchronized (lock) {
                            total[0]++;
                        }

                        if (alreadyDownloadedHashes.contains(hash)) {
                            continue; // already have it, skip both request and download
                        }

                        FileEntry fe = fetchEntryInfo(server, hash, ext);
                        if (fe == null) {
                            continue;
                        }
                        boolean success = downloadOne(fe);
                        if (success) {
                            synchronized (lock) {
                                ok[0]++;
                            }
                        }
                    }
                }
            }));
        }

        for (java.util.concurrent.Future<?> fut : futures) {
            try {
                fut.get();
            } catch (Exception ignored) {
            }
        }
        pool.shutdown();

        System.out.println("Done. " + ok[0] + "/" + total[0] + " new file(s) downloaded "
                + "(entries already present locally were skipped).");
    }

    // ---------------------------------------------------------------
    // Networking helpers
    // ---------------------------------------------------------------

    private String httpGetText(String urlStr) throws IOException {
        HttpURLConnection conn = openConnection(urlStr);
        InputStream in = null;
        try {
            int code = conn.getResponseCode();
            if (code != HttpURLConnection.HTTP_OK) {
                throw new IOException("HTTP " + code);
            }
            in = conn.getInputStream();
            return readAllText(in);
        } finally {
            if (in != null) try { in.close(); } catch (IOException ignored) {}
            conn.disconnect();
        }
    }

    private File downloadToTempFile(String urlStr) throws IOException {
        HttpURLConnection conn = openConnection(urlStr);
        InputStream in = null;
        OutputStream out = null;
        File tmp = File.createTempFile("meshare_", ".tmp");
        tmp.deleteOnExit();
        try {
            int code = conn.getResponseCode();
            if (code != HttpURLConnection.HTTP_OK) {
                throw new IOException("HTTP " + code);
            }
            in = conn.getInputStream();
            out = new FileOutputStream(tmp);
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) != -1) {
                out.write(buf, 0, n);
            }
        } finally {
            if (in != null) try { in.close(); } catch (IOException ignored) {}
            if (out != null) try { out.close(); } catch (IOException ignored) {}
            conn.disconnect();
        }
        return tmp;
    }

    private HttpURLConnection openConnection(String urlStr) throws IOException {
        // Using the URL(String) constructor for Java 8 compatibility (URI.toURL()
        // is a newer convenience added after Java 8 and not required here).
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
        conn.setReadTimeout(READ_TIMEOUT_MS);
        conn.setRequestMethod("GET");
        conn.setRequestProperty("User-Agent", "MesharePrompt/1.0");
        conn.setInstanceFollowRedirects(true);
        return conn;
    }

    private String readAllText(InputStream in) throws IOException {
        java.io.ByteArrayOutputStream bos = new java.io.ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) != -1) {
            bos.write(buf, 0, n);
        }
        return new String(bos.toByteArray(), StandardCharsets.UTF_8);
    }

    // ---------------------------------------------------------------
    // Local file helpers
    // ---------------------------------------------------------------

    private List<String> readAllLinesLocal(File f) {
        List<String> result = new ArrayList<String>();
        BufferedReader br = null;
        try {
            br = new BufferedReader(new InputStreamReader(
                    new java.io.FileInputStream(f), StandardCharsets.UTF_8));
            String line;
            while ((line = br.readLine()) != null) {
                result.add(line);
            }
        } catch (IOException e) {
            System.out.println("Could not read " + f.getPath() + ": " + e.getMessage());
        } finally {
            if (br != null) try { br.close(); } catch (IOException ignored) {}
        }
        return result;
    }

    private String readFileAsString(File f) throws IOException {
        InputStream in = new java.io.FileInputStream(f);
        try {
            return readAllText(in);
        } finally {
            in.close();
        }
    }

    private List<String> splitLines(String content) {
        List<String> result = new ArrayList<String>();
        String[] parts = content.split("\\r\\n|\\r|\\n");
        for (String p : parts) {
            result.add(p);
        }
        return result;
    }

    private void moveFile(File src, File dest) throws IOException {
        if (dest.exists()) {
            dest.delete();
        }
        boolean renamed = src.renameTo(dest);
        if (!renamed) {
            // cross-filesystem fallback: copy then delete
            copyFile(src, dest);
            src.delete();
        }
    }

    private void copyFile(File src, File dest) throws IOException {
        InputStream in = new java.io.FileInputStream(src);
        OutputStream out = new FileOutputStream(dest);
        try {
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) != -1) {
                out.write(buf, 0, n);
            }
        } finally {
            in.close();
            out.close();
        }
    }

    private String sha256OfFile(File f) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        InputStream in = new java.io.FileInputStream(f);
        try {
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) != -1) {
                md.update(buf, 0, n);
            }
        } finally {
            in.close();
        }
        byte[] digest = md.digest();
        StringBuilder sb = new StringBuilder(digest.length * 2);
        for (byte b : digest) {
            String hex = Integer.toHexString(0xff & b);
            if (hex.length() == 1) sb.append('0');
            sb.append(hex);
        }
        return sb.toString();
    }

    private void writeJsonSidecar(File dest, java.util.Map<String, String> fields) throws IOException {
        String json = SimpleJson.writeObject(fields);
        Writer w = new OutputStreamWriter(new FileOutputStream(dest), StandardCharsets.UTF_8);
        try {
            w.write(json);
        } finally {
            w.close();
        }
    }

    private void writeDownloadLog(String fileName) {
        try {
            java.util.Map<String, String> logFields = new java.util.LinkedHashMap<String, String>();
            logFields.put("file", fileName);
            logFields.put("downloaded_at", new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX").format(new Date()));
            File logFile = new File(LOG_DIR, fileName + ".json");
            String json = SimpleJson.writeObject(logFields);
            Writer w = new OutputStreamWriter(new FileOutputStream(logFile), StandardCharsets.UTF_8);
            try {
                w.write(json);
            } finally {
                w.close();
            }
        } catch (IOException e) {
            System.out.println("WARNING: could not write log entry for " + fileName + ": " + e.getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Data holder
    // ---------------------------------------------------------------

    private static class FileEntry {
        String server;
        String hash;
        String extension;
        java.util.Map<String, String> json = new java.util.LinkedHashMap<String, String>();
        boolean alreadyDownloaded;
    }

    // ---------------------------------------------------------------
    // Minimal dependency-free JSON support (flat object of string-ish values only,
    // matching the metadata structure used by this system: filename, size,
    // extension, public_key, server_public_key, node_info, description, date).
    // ---------------------------------------------------------------

    private static final class SimpleJson {

        /**
         * Parses a flat JSON object into a LinkedHashMap<String,String>,
         * truncating each value to maxLen characters. Numbers/booleans/null
         * are converted to their literal text form. Returns null if the
         * text is not a valid flat JSON object.
         */
        static java.util.Map<String, String> parseObjectTruncated(String text, int maxLen) {
            if (text == null) return null;
            String s = text.trim();
            if (s.isEmpty() || s.charAt(0) != '{') return null;

            java.util.Map<String, String> result = new java.util.LinkedHashMap<String, String>();
            int[] pos = {0};
            try {
                skipWs(s, pos);
                expect(s, pos, '{');
                skipWs(s, pos);
                if (peek(s, pos) == '}') {
                    pos[0]++;
                    return result;
                }
                while (true) {
                    skipWs(s, pos);
                    String key = parseString(s, pos);
                    skipWs(s, pos);
                    expect(s, pos, ':');
                    skipWs(s, pos);
                    String value = parseValueAsString(s, pos);
                    if (value.length() > maxLen) {
                        value = value.substring(0, maxLen);
                    }
                    result.put(key, value);
                    skipWs(s, pos);
                    char c = peek(s, pos);
                    if (c == ',') {
                        pos[0]++;
                        continue;
                    } else if (c == '}') {
                        pos[0]++;
                        break;
                    } else {
                        return null; // malformed
                    }
                }
                return result;
            } catch (Exception e) {
                return null;
            }
        }

        private static char peek(String s, int[] pos) {
            if (pos[0] >= s.length()) throw new RuntimeException("unexpected end of json");
            return s.charAt(pos[0]);
        }

        private static void expect(String s, int[] pos, char c) {
            if (peek(s, pos) != c) throw new RuntimeException("expected '" + c + "'");
            pos[0]++;
        }

        private static void skipWs(String s, int[] pos) {
            while (pos[0] < s.length() && Character.isWhitespace(s.charAt(pos[0]))) {
                pos[0]++;
            }
        }

        private static String parseString(String s, int[] pos) {
            expect(s, pos, '"');
            StringBuilder sb = new StringBuilder();
            while (true) {
                char c = peek(s, pos);
                pos[0]++;
                if (c == '"') {
                    break;
                } else if (c == '\\') {
                    char esc = peek(s, pos);
                    pos[0]++;
                    switch (esc) {
                        case '"': sb.append('"'); break;
                        case '\\': sb.append('\\'); break;
                        case '/': sb.append('/'); break;
                        case 'b': sb.append('\b'); break;
                        case 'f': sb.append('\f'); break;
                        case 'n': sb.append('\n'); break;
                        case 'r': sb.append('\r'); break;
                        case 't': sb.append('\t'); break;
                        case 'u':
                            String hex = s.substring(pos[0], pos[0] + 4);
                            pos[0] += 4;
                            sb.append((char) Integer.parseInt(hex, 16));
                            break;
                        default: sb.append(esc);
                    }
                } else {
                    sb.append(c);
                }
            }
            return sb.toString();
        }

        private static String parseValueAsString(String s, int[] pos) {
            char c = peek(s, pos);
            if (c == '"') {
                return parseString(s, pos);
            } else if (c == '{') {
                // nested object: capture raw text as string representation
                int start = pos[0];
                int depth = 0;
                do {
                    char cc = peek(s, pos);
                    if (cc == '{') depth++;
                    if (cc == '}') depth--;
                    pos[0]++;
                } while (depth > 0);
                return s.substring(start, pos[0]);
            } else if (c == '[') {
                int start = pos[0];
                int depth = 0;
                do {
                    char cc = peek(s, pos);
                    if (cc == '[') depth++;
                    if (cc == ']') depth--;
                    pos[0]++;
                } while (depth > 0);
                return s.substring(start, pos[0]);
            } else {
                // number, true, false, null -> read until , or } or whitespace
                int start = pos[0];
                while (pos[0] < s.length()) {
                    char cc = s.charAt(pos[0]);
                    if (cc == ',' || cc == '}' || Character.isWhitespace(cc)) break;
                    pos[0]++;
                }
                return s.substring(start, pos[0]);
            }
        }

        /** Serializes a flat string map to pretty JSON text. */
        static String writeObject(java.util.Map<String, String> fields) {
            StringBuilder sb = new StringBuilder();
            sb.append("{\n");
            int i = 0;
            int size = fields.size();
            for (java.util.Map.Entry<String, String> e : fields.entrySet()) {
                sb.append("    \"").append(escape(e.getKey())).append("\": ");
                sb.append("\"").append(escape(e.getValue())).append("\"");
                i++;
                if (i < size) sb.append(",");
                sb.append("\n");
            }
            sb.append("}");
            return sb.toString();
        }

        private static String escape(String s) {
            if (s == null) return "";
            StringBuilder sb = new StringBuilder();
            for (int i = 0; i < s.length(); i++) {
                char c = s.charAt(i);
                switch (c) {
                    case '"': sb.append("\\\""); break;
                    case '\\': sb.append("\\\\"); break;
                    case '\n': sb.append("\\n"); break;
                    case '\r': sb.append("\\r"); break;
                    case '\t': sb.append("\\t"); break;
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
    }
}