/*
 * ServersDiscovery
 * ----------------
 * Peer discovery + health check for a DHT-style file cluster that talks
 * plain HTTP and HTTPS.  Single Java 8 file, no external dependencies.
 *
 *   * Maintains "servers.txt"  -> list of active peer URLs (http or https)
 *   * Uses "files.txt" as test vectors: each line is "hash.ext" where
 *     "hash" is the lower-case hex SHA-256 of the file body
 *   * For every candidate server, a random test vector is requested and
 *     the returned bytes are hashed; the server is kept only if the hash
 *     matches.  Failed / unreachable servers are removed.
 *   * Discovery is BFS up to a configurable depth (default 2): we check
 *     the local "servers.txt", then for every passing peer we read its
 *     "servers.txt" and check those peers, and so on.
 *
 * USAGE
 *   java ServersDiscovery                  # use defaults
 *   java ServersDiscovery -http 8080 -https 8443 -depth 2 -interval 30
 *
 *   Interactive prompt accepts:
 *     add <url>       manually add a server
 *     remove <url>    remove a server
 *     list            show all known servers
 *     check           run a discovery cycle now
 *     files           list test vectors loaded from files.txt
 *     depth <n>       set crawl depth (1..N)
 *     -options        open options sub-prompt (http, https, depth, ...)
 *     help            show command help
 *     quit            exit
 *
 * IMPORTANT
 *   Run this program ALONGSIDE (or BEFORE) your main program.
 *   It manages the peer list and the file checks that keep the DHT honest.
 */
import com.sun.net.httpserver.*;

import javax.net.ssl.*;
import java.io.*;
import java.math.BigInteger;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.*;
import java.security.cert.*;
import java.text.SimpleDateFormat;
import java.util.*;
import java.util.concurrent.*;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.stream.*;

public class ServersDiscovery {

    /* =====================  configuration  ===================== */
    private static int    httpPort                 = 8080;
    private static int    httpsPort                = 8443;
    private static int    crawlDepth               = 2;
    private static int    discoveryIntervalSeconds = 60;
    private static int    requestTimeoutSeconds    = 10;
    private static String keystorePassword         = "serversdiscovery";
    private static String keystoreFile             = "keystore.jks";
    private static String bindHost                 = "0.0.0.0";
    private static String announceHttp             = "";
    private static String announceHttps            = "";

    /* =====================  runtime state  ===================== */
    private static final Path   SERVERS_FILE = Paths.get("servers.txt");
    private static final Path   FILES_FILE   = Paths.get("files.txt");
    private static final Path   FILES_DIR    = Paths.get("files");

    private static final Set<String>        servers    = ConcurrentHashMap.newKeySet();
    private static final List<String>       localFiles = new CopyOnWriteArrayList<>();
    private static final SecureRandom       random     = new SecureRandom();
    private static final AtomicInteger      cycleCount  = new AtomicInteger(0);
    private static volatile boolean         running    = true;
    private static ExecutorService          pool;

    /* =====================  main  ===================== */
    public static void main(String[] args) throws Exception {
        printBanner();

        // CLI flags
        for (int i = 0; i + 1 < args.length; i++) {
            switch (args[i]) {
                case "-http":            httpPort = Integer.parseInt(args[++i]); break;
                case "-https":           httpsPort = Integer.parseInt(args[++i]); break;
                case "-depth":           crawlDepth = Integer.parseInt(args[++i]); break;
                case "-interval":        discoveryIntervalSeconds = Integer.parseInt(args[++i]); break;
                case "-timeout":         requestTimeoutSeconds = Integer.parseInt(args[++i]); break;
                case "-bind":            bindHost = args[++i]; break;
                case "-kspass":          keystorePassword = args[++i]; break;
                case "-announce-http":   announceHttp = args[++i]; break;
                case "-announce-https":  announceHttps = args[++i]; break;
            }
        }

        if (announceHttp.isEmpty())  announceHttp  = "http://"  + hostIdentifier() + ":" + httpPort;
        if (announceHttps.isEmpty()) announceHttps = "https://" + hostIdentifier() + ":" + httpsPort;

        pool = Executors.newCachedThreadPool(r -> {
            Thread t = new Thread(r, "sd-worker");
            t.setDaemon(true);
            return t;
        });

        Files.createDirectories(FILES_DIR);
        loadServers();
        loadFiles();

        // Seed ourselves in the local list
        servers.add(announceHttp);
        servers.add(announceHttps);
        saveServers();

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            running = false;
            pool.shutdownNow();
            log("[*] Shutting down...");
        }, "sd-shutdown"));

        try { startHttpServer();  } catch (Exception e) { log("[!] HTTP  server failed: " + e.getMessage()); }
        try { startHttpsServer(); } catch (Exception e) { log("[!] HTTPS server failed: " + e.getMessage()); }

        log("[*] Announce HTTP  : " + announceHttp);
        log("[*] Announce HTTPS : " + announceHttps);
        log("[*] Crawl depth   : " + crawlDepth);
        log("[*] Interval      : " + discoveryIntervalSeconds + "s");
        log("");

        // initial discovery
        pool.submit(ServersDiscovery::runDiscovery);

        // periodic discovery
        ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor(r -> {
            Thread t = new Thread(r, "sd-scheduler");
            t.setDaemon(true);
            return t;
        });
        scheduler.scheduleAtFixedRate(() -> {
            if (!running) return;
            pool.submit(ServersDiscovery::runDiscovery);
        }, discoveryIntervalSeconds, discoveryIntervalSeconds, TimeUnit.SECONDS);

        startConsole();
    }

    /* =====================  banner / help  ===================== */
    private static void printBanner() {
        System.out.println();
        System.out.println("  ===============================================");
        System.out.println("            ServersDiscovery  v1.0");
        System.out.println("  ===============================================");
        System.out.println();
        System.out.println("  [!] Run this program ALONGSIDE (or BEFORE) your main program.");
        System.out.println("      It maintains servers.txt and removes peers that fail the");
        System.out.println("      random hash test, so the DHT stays honest.");
        System.out.println();
    }

    private static void printHelp() {
        log("");
        log("  Commands:");
        log("    add <url>      add a peer (http://host:port or https://host:port)");
        log("    remove <url>   remove a peer");
        log("    list           show all known peers");
        log("    check          run a discovery cycle now");
        log("    files          show test vectors loaded from files.txt");
        log("    depth <n>      set crawl depth  (current: " + crawlDepth + ")");
        log("    interval <s>   set discovery interval seconds  (current: " + discoveryIntervalSeconds + ")");
        log("    -options       open the options sub-prompt");
        log("    help           this help");
        log("    quit           exit the program");
        log("");
    }

    /* =====================  prompt loop  ===================== */
    private static void startConsole() {
        Thread t = new Thread(() -> {
            BufferedReader br = new BufferedReader(new InputStreamReader(System.in));
            log("[*] Console ready. Type 'help' for commands.");
            while (running) {
                String line = readPromptLine(br, "> ");
                if (line == null) break;          // EOF
                line = line.trim();
                if (line.isEmpty()) continue;
                String low = line.toLowerCase();
                try {
                    if (low.equals("quit") || low.equals("exit")) {
                        log("[*] Bye.");
                        System.exit(0);
                    } else if (low.equals("help") || low.equals("?")) {
                        printHelp();
                    } else if (low.equals("list")) {
                        listServers();
                    } else if (low.equals("check") || low.equals("discover")) {
                        pool.submit(ServersDiscovery::runDiscovery);
                    } else if (low.equals("files")) {
                        listFiles();
                    } else if (low.startsWith("add ")) {
                        String url = line.substring(4).trim();
                        if (isValidUrl(url)) {
                            addServer(url);
                            saveServers();
                            // Introduce ourselves to the new peer (best effort)
                            pool.submit(() -> { try { introduceOurselves(url); } catch (Exception ignored) {} });
                        } else {
                            log("  [!] Invalid URL. Must start with http:// or https://");
                        }
                    } else if (low.startsWith("remove ")) {
                        String url = line.substring(7).trim();
                        removeServer(url);
                        saveServers();
                    } else if (low.startsWith("depth ")) {
                        try {
                            crawlDepth = Math.max(1, Integer.parseInt(line.substring(6).trim()));
                            log("  [+] Crawl depth set to " + crawlDepth);
                        } catch (NumberFormatException e) { log("  [!] Bad number"); }
                    } else if (low.startsWith("interval ")) {
                        try {
                            discoveryIntervalSeconds = Math.max(1, Integer.parseInt(line.substring(9).trim()));
                            log("  [+] Interval set to " + discoveryIntervalSeconds + "s (restart the program to apply)");
                        } catch (NumberFormatException e) { log("  [!] Bad number"); }
                    } else if (low.equals("-options") || low.equals("options")) {
                        optionsMenu(br);
                    } else {
                        log("  [!] Unknown command. Type 'help'.");
                    }
                } catch (Exception e) {
                    log("  [!] Error: " + e.getMessage());
                }
            }
        }, "sd-console");
        t.setDaemon(false);
        t.start();
    }

    private static String readPromptLine(BufferedReader br, String prefix) {
        synchronized (System.out) {
            System.out.print(prefix);
            System.out.flush();
        }
        try {
            return br.readLine();
        } catch (IOException e) {
            return null;
        }
    }

    private static void listServers() {
        synchronized (System.out) {
            System.out.println("  === Active servers (" + servers.size() + ") ===");
            List<String> sorted = new ArrayList<>(servers);
            Collections.sort(sorted);
            for (String s : sorted) {
                String tag = isSelf(s) ? "  [self]" : "";
                System.out.println("    " + s + tag);
            }
            System.out.println();
        }
    }

    private static void listFiles() {
        synchronized (System.out) {
            System.out.println("  === Test vectors (" + localFiles.size() + ") ===");
            for (String f : localFiles) System.out.println("    " + f);
            System.out.println();
        }
    }

    /* =====================  -options sub-prompt  ===================== */
    private static void optionsMenu(BufferedReader br) {
        log("");
        log("  === Options (type 'ok' to return, 'save' to persist servers.txt) ===");
        log("  Format: key=value separated by spaces, e.g.  http=9090 depth=3");
        log("  Keys  : http https depth interval timeout bind kspass announce-http announce-https");
        while (running) {
            String line = readPromptLine(br, "  opt> ");
            if (line == null) return;
            line = line.trim();
            if (line.isEmpty() || line.equalsIgnoreCase("ok") || line.equalsIgnoreCase("cancel")) return;
            if (line.equalsIgnoreCase("save")) { saveServers(); log("  [+] Saved."); return; }
            for (String part : line.split("\\s+")) {
                int eq = part.indexOf('=');
                if (eq < 0) { log("    [!] Bad token: " + part); continue; }
                String k = part.substring(0, eq).trim().toLowerCase();
                String v = part.substring(eq + 1).trim();
                try {
                    switch (k) {
                        case "http":            httpPort = Integer.parseInt(v); break;
                        case "https":           httpsPort = Integer.parseInt(v); break;
                        case "depth":           crawlDepth = Math.max(1, Integer.parseInt(v)); break;
                        case "interval":        discoveryIntervalSeconds = Math.max(1, Integer.parseInt(v)); break;
                        case "timeout":         requestTimeoutSeconds = Math.max(1, Integer.parseInt(v)); break;
                        case "bind":            bindHost = v; break;
                        case "kspass":          keystorePassword = v; break;
                        case "announce-http":   announceHttp = v; break;
                        case "announce-https":  announceHttps = v; break;
                        default: log("    [!] Unknown key: " + k); continue;
                    }
                    log("    [+] " + k + " = " + v);
                } catch (NumberFormatException e) {
                    log("    [!] Invalid value for " + k);
                }
            }
        }
    }

    /* =====================  discovery (BFS)  ===================== */
    private static void runDiscovery() {
        int cycle = cycleCount.incrementAndGet();
        long t0 = System.currentTimeMillis();
        log("\n[Discovery #" + cycle + "] starting, depth=" + crawlDepth);

        Set<String> checked = ConcurrentHashMap.newKeySet();
        checked.add(announceHttp);
        checked.add(announceHttps);

        Set<String> frontier = new HashSet<>(servers);
        frontier.remove(announceHttp);
        frontier.remove(announceHttps);
        frontier.removeIf(checked::contains);

        Set<String> newDiscovered = ConcurrentHashMap.newKeySet();
        int depth = 0;

        while (depth < crawlDepth && !frontier.isEmpty() && running) {
            depth++;
            log("  [Depth " + depth + "/" + crawlDepth + "] checking " + frontier.size() + " server(s)");
            Set<String> next = ConcurrentHashMap.newKeySet();
            for (String url : frontier) {
                if (!running) break;
                if (checked.contains(url)) continue;
                checked.add(url);
                CheckResult r = checkServer(url);
                if (r.online && r.testPassed) {
                    log("    [+] PASS  " + url + "  (" + r.reason + ")");
                    addServer(url);
                    // Crawl their servers.txt for more peers
                    Set<String> peers = fetchServersList(url);
                    for (String p : peers) {
                        if (isSelf(p)) continue;
                        if (!checked.contains(p)) next.add(p);
                        if (!servers.contains(p))   newDiscovered.add(p);
                    }
                } else {
                    log("    [-] FAIL  " + url + "  (" + r.reason + ")");
                    removeServer(url);
                }
            }
            for (String s : next) {
                if (!servers.contains(s)) addServer(s);
            }
            frontier = next;
        }
        if (!newDiscovered.isEmpty()) {
            log("  [Gossip] " + newDiscovered.size() + " new peer(s) discovered and queued");
        }
        saveServers();
        long ms = System.currentTimeMillis() - t0;
        log("[Discovery #" + cycle + "] done in " + ms + "ms, active=" + servers.size());
    }

    private static final class CheckResult {
        boolean online;
        boolean testPassed;
        String  reason = "";
        String  url;
    }

    /* =====================  core check  ===================== */
    private static CheckResult checkServer(String baseUrl) {
        CheckResult r = new CheckResult();
        r.url = baseUrl;
        try {
            // 1. Files.txt at root?
            HttpURLConnection c = openConnection(baseUrl + "/files.txt");
            c.setRequestMethod("GET");
            c.setConnectTimeout(requestTimeoutSeconds * 1000);
            c.setReadTimeout(requestTimeoutSeconds * 1000);
            c.setRequestProperty("User-Agent", "ServersDiscovery/1.0");
            int code = c.getResponseCode();
            c.disconnect();
            if (code != 200) {
                r.online = false;
                r.reason = "no /files.txt (HTTP " + code + ")";
                return r;
            }

            // 2. Random file test (or pass-through if no test vectors)
            if (localFiles.isEmpty()) {
                r.online = true;
                r.testPassed = true;
                r.reason = "files.txt present; no test vectors locally";
                return r;
            }
            String filename = localFiles.get(random.nextInt(localFiles.size()));
            String hash = filename;
            int dot = filename.lastIndexOf('.');
            if (dot > 0) hash = filename.substring(0, dot);

            // 3. Fetch the file
            HttpURLConnection c2 = openConnection(baseUrl + "/files/" + filename);
            c2.setRequestMethod("GET");
            c2.setConnectTimeout(requestTimeoutSeconds * 1000);
            c2.setReadTimeout(requestTimeoutSeconds * 1000);
            c2.setRequestProperty("User-Agent", "ServersDiscovery/1.0");
            int code2 = c2.getResponseCode();
            if (code2 != 200) {
                r.online = true;
                r.testPassed = false;
                r.reason = "missing /files/" + filename + " (HTTP " + code2 + ")";
                return r;
            }

            // 4. Hash the body
            String computed;
            try (InputStream is = c2.getInputStream()) {
                computed = sha256Hex(is);
            }
            if (computed.equalsIgnoreCase(hash)) {
                r.online = true;
                r.testPassed = true;
                r.reason = "test=" + filename + " hash=ok";
            } else {
                r.online = true;
                r.testPassed = false;
                r.reason = "hash mismatch for " + filename
                         + " (want " + hash.substring(0, Math.min(8, hash.length())) + "..., got "
                         + computed.substring(0, Math.min(8, computed.length())) + "...)";
            }
        } catch (SocketTimeoutException e) {
            r.online = false; r.reason = "timeout";
        } catch (ConnectException e) {
            r.online = false; r.reason = "connect-refused";
        } catch (UnknownHostException e) {
            r.online = false; r.reason = "unknown-host " + e.getMessage();
        } catch (Exception e) {
            r.online = false; r.reason = e.getClass().getSimpleName() + ": " + e.getMessage();
        }
        return r;
    }

    private static Set<String> fetchServersList(String baseUrl) {
        Set<String> out = new HashSet<>();
        try {
            HttpURLConnection c = openConnection(baseUrl + "/servers.txt");
            c.setRequestMethod("GET");
            c.setConnectTimeout(requestTimeoutSeconds * 1000);
            c.setReadTimeout(requestTimeoutSeconds * 1000);
            c.setRequestProperty("User-Agent", "ServersDiscovery/1.0");
            int code = c.getResponseCode();
            if (code != 200) return out;
            try (BufferedReader br = new BufferedReader(new InputStreamReader(c.getInputStream(), "UTF-8"))) {
                String line;
                while ((line = br.readLine()) != null) {
                    line = line.trim();
                    if (isValidUrl(line)) out.add(line);
                }
            }
        } catch (Exception ignored) {}
        return out;
    }

    private static void introduceOurselves(String target) throws Exception {
        HttpURLConnection c = openConnection(target + "/servers.txt");
        c.setRequestMethod("POST");
        c.setDoOutput(true);
        c.setConnectTimeout(requestTimeoutSeconds * 1000);
        c.setReadTimeout(requestTimeoutSeconds * 1000);
        c.setRequestProperty("Content-Type", "text/plain; charset=utf-8");
        c.setRequestProperty("User-Agent", "ServersDiscovery/1.0");
        String body = announceHttp + "\n" + announceHttps + "\n";
        try (OutputStream os = c.getOutputStream()) {
            os.write(body.getBytes("UTF-8"));
        }
        int code = c.getResponseCode();
        log("  [i] Introduced ourselves to " + target + " -> HTTP " + code);
    }

    /* =====================  HTTP / HTTPS servers  ===================== */
    private static void startHttpServer() throws IOException {
        HttpServer server = HttpServer.create(new InetSocketAddress(bindHost, httpPort), 0);
        server.setExecutor(pool);
        server.createContext("/", new RootHandler());
        server.start();
        log("[+] HTTP  server listening on http://" + bindHost + ":" + httpPort);
    }

    private static void startHttpsServer() throws Exception {
        HttpsServer server = HttpsServer.create(new InetSocketAddress(bindHost, httpsPort), 0);

        KeyStore ks = loadOrCreateKeystore(keystoreFile, keystorePassword);
        KeyManagerFactory kmf = KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm());
        kmf.init(ks, keystorePassword.toCharArray());
        SSLContext ctx = SSLContext.getInstance("TLS");
        ctx.init(kmf.getKeyManagers(), null, null);
        server.setHttpsConfigurator(new HttpsConfigurator(ctx));

        server.setExecutor(pool);
        server.createContext("/", new RootHandler());
        server.start();
        log("[+] HTTPS server listening on https://" + bindHost + ":" + httpsPort + "  (self-signed)");
    }

    /* single handler for both HTTP and HTTPS contexts */
    private static class RootHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            try {
                String method = ex.getRequestMethod();
                String path   = ex.getRequestURI().getPath();
                if ("/".equals(path) || "/health".equals(path)) {
                    send(ex, 200, "text/plain; charset=utf-8",
                            ("ServersDiscovery OK\n" +
                             "self=" + announceHttp + " | " + announceHttps + "\n" +
                             "peers=" + servers.size() + "\n").getBytes("UTF-8"));
                    return;
                }
                if ("/files.txt".equals(path)) {
                    servePath(ex, FILES_FILE);
                    return;
                }
                if ("/servers.txt".equals(path)) {
                    if ("GET".equalsIgnoreCase(method)) {
                        servePath(ex, SERVERS_FILE);
                    } else if ("POST".equalsIgnoreCase(method) || "PUT".equalsIgnoreCase(method)) {
                        byte[] body = readAll(ex.getRequestBody());
                        int added = 0;
                        try (BufferedReader br = new BufferedReader(new InputStreamReader(
                                new ByteArrayInputStream(body), "UTF-8"))) {
                            String line;
                            while ((line = br.readLine()) != null) {
                                line = line.trim();
                                if (isValidUrl(line) && servers.add(line)) added++;
                            }
                        }
                        saveServers();
                        send(ex, 200, "text/plain; charset=utf-8",
                                ("OK added=" + added + " total=" + servers.size() + "\n").getBytes("UTF-8"));
                    } else {
                        ex.sendResponseHeaders(405, -1);
                    }
                    return;
                }
                if (path.startsWith("/files/")) {
                    String name = path.substring("/files/".length());
                    if (name.isEmpty() || name.contains("..") || name.contains("/") || name.contains("\\")) {
                        send(ex, 400, "text/plain", "Bad filename\n".getBytes("UTF-8"));
                        return;
                    }
                    servePath(ex, FILES_DIR.resolve(name));
                    return;
                }
                send(ex, 404, "text/plain", "Not found\n".getBytes("UTF-8"));
            } catch (Exception e) {
                log("[!] handler error: " + e.getMessage());
                try { send(ex, 500, "text/plain", ("Server error: " + e.getMessage() + "\n").getBytes("UTF-8")); }
                catch (Exception ignored) {}
            } finally {
                ex.close();
            }
        }
    }

    private static void servePath(HttpExchange ex, Path p) throws IOException {
        if (!Files.exists(p) || Files.isDirectory(p)) {
            send(ex, 404, "text/plain", "Not found\n".getBytes("UTF-8"));
            return;
        }
        byte[] data = Files.readAllBytes(p);
        ex.getResponseHeaders().set("Content-Type", "text/plain; charset=utf-8");
        ex.sendResponseHeaders(200, data.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(data); }
    }

    private static void send(HttpExchange ex, int code, String contentType, byte[] body) throws IOException {
        ex.getResponseHeaders().set("Content-Type", contentType);
        ex.sendResponseHeaders(code, body.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(body); }
    }

    /* =====================  persistence  ===================== */
    private static void loadServers() {
        if (!Files.exists(SERVERS_FILE)) {
            log("[*] servers.txt not found, starting empty");
            return;
        }
        try (Stream<String> lines = Files.lines(SERVERS_FILE)) {
            int n = 0;
            for (String s : (Iterable<String>) lines::iterator) {
                s = s.trim();
                if (!s.isEmpty() && isValidUrl(s)) { servers.add(s); n++; }
            }
            log("[*] Loaded " + n + " server(s) from servers.txt");
        } catch (IOException e) {
            log("[!] Failed to read servers.txt: " + e.getMessage());
        }
    }

    private static synchronized void saveServers() {
        try (BufferedWriter w = Files.newBufferedWriter(SERVERS_FILE)) {
            List<String> sorted = new ArrayList<>(servers);
            Collections.sort(sorted);
            for (String s : sorted) {
                w.write(s);
                w.newLine();
            }
        } catch (IOException e) {
            log("[!] Failed to write servers.txt: " + e.getMessage());
        }
    }

    private static void loadFiles() {
        if (!Files.exists(FILES_FILE)) {
            log("[!] files.txt not found; the random test will skip when no vectors are present");
            return;
        }
        try (Stream<String> lines = Files.lines(FILES_FILE)) {
            int n = 0;
            for (String line : (Iterable<String>) lines::iterator) {
                line = line.trim();
                if (line.isEmpty() || line.startsWith("#")) continue;
                if (!line.contains(".")) {
                    log("  [!] Skipping (no extension, expected hash.ext): " + line);
                    continue;
                }
                localFiles.add(line);
                n++;
                Path p = FILES_DIR.resolve(line);
                if (!Files.exists(p)) {
                    log("  [!] files.txt lists '" + line + "' but it's missing under files/");
                }
            }
            log("[*] Loaded " + n + " test vector(s) from files.txt");
        } catch (IOException e) {
            log("[!] Failed to read files.txt: " + e.getMessage());
        }
    }

    /* =====================  set ops  ===================== */
    private static void addServer(String url) {
        if (servers.add(url)) {
            log("  [+] added " + url);
        }
    }

    private static void removeServer(String url) {
        if (servers.remove(url)) {
            log("  [-] removed " + url);
        }
    }

    /* =====================  helpers  ===================== */
    private static boolean isValidUrl(String s) {
        return s != null && (s.startsWith("http://") || s.startsWith("https://"));
    }

    private static boolean isSelf(String url) {
        return url.equals(announceHttp) || url.equals(announceHttps);
    }

    private static byte[] readAll(InputStream is) throws IOException {
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = is.read(buf)) >= 0) if (n > 0) bos.write(buf, 0, n);
        return bos.toByteArray();
    }

    private static String sha256Hex(InputStream is) throws IOException {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            byte[] buf = new byte[8192];
            int n;
            while ((n = is.read(buf)) > 0) md.update(buf, 0, n);
            byte[] h = md.digest();
            StringBuilder sb = new StringBuilder(h.length * 2);
            for (byte b : h) sb.append(String.format("%02x", b));
            return sb.toString();
        } catch (NoSuchAlgorithmException e) {
            throw new IOException(e);
        }
    }

    private static void log(String s) {
        synchronized (System.out) {
            System.out.println(s);
        }
    }

    private static String hostIdentifier() {
        try {
            Enumeration<NetworkInterface> nis = NetworkInterface.getNetworkInterfaces();
            if (nis != null) {
                while (nis.hasMoreElements()) {
                    NetworkInterface ni = nis.nextElement();
                    if (ni.isLoopback() || ni.isPointToPoint() || !ni.isUp() || ni.isVirtual()) continue;
                    Enumeration<InetAddress> addrs = ni.getInetAddresses();
                    while (addrs.hasMoreElements()) {
                        InetAddress a = addrs.nextElement();
                        if (a instanceof Inet4Address && !a.isLoopbackAddress()) {
                            return a.getHostAddress();
                        }
                    }
                }
            }
        } catch (Exception ignored) {}
        return "localhost";
    }

    /* =====================  HTTPS client: trust all (self-signed friendly)  ===================== */
    static {
        try {
            TrustManager[] trustAll = new TrustManager[] {
                new X509TrustManager() {
                    public X509Certificate[] getAcceptedIssuers() { return new X509Certificate[0]; }
                    public void checkClientTrusted(X509Certificate[] c, String a) { }
                    public void checkServerTrusted(X509Certificate[] c, String a) { }
                }
            };
            SSLContext sc = SSLContext.getInstance("TLS");
            sc.init(null, trustAll, new SecureRandom());
            HttpsURLConnection.setDefaultSSLSocketFactory(sc.getSocketFactory());
            HttpsURLConnection.setDefaultHostnameVerifier((h, s) -> true);
        } catch (Exception e) {
            System.err.println("[!] Could not initialise trust-all SSL: " + e.getMessage());
        }
    }

    private static HttpURLConnection openConnection(String url) throws IOException {
        URL u = new URL(url);
        return (HttpURLConnection) u.openConnection();
    }

    /* =====================  self-signed keystore  ===================== */
    private static KeyStore loadOrCreateKeystore(String file, String password) throws Exception {
        File f = new File(file);
        if (f.exists()) {
            KeyStore ks = KeyStore.getInstance(KeyStore.getDefaultType());
            try (FileInputStream in = new FileInputStream(f)) {
                ks.load(in, password.toCharArray());
            }
            return ks;
        }
        log("[*] Generating self-signed certificate for HTTPS...");
        return createSelfSignedKeystore(file, password);
    }

    /* =====================  self-signed keystore (portable DER encoder)  ===================== */
    /* We hand-build a DER-encoded X.509v3 certificate using only public JDK
       classes, so the program stays single-file and works on Java 8+.        */
    private static final int[] OID_RSA          = {1, 2, 840, 113549, 1, 1, 1};
    private static final int[] OID_SHA256_RSA   = {1, 2, 840, 113549, 1, 1, 11};
    private static final int[] OID_CN           = {2, 5, 4, 3};
    private static final int[] OID_OU           = {2, 5, 4, 11};
    private static final int[] OID_O            = {2, 5, 4, 10};
    private static final int[] OID_L            = {2, 5, 4, 7};
    private static final int[] OID_ST           = {2, 5, 4, 8};
    private static final int[] OID_C            = {2, 5, 4, 6};
    private static final int[] OID_BC           = {2, 5, 29, 19};   // basicConstraints
    private static final int[] OID_SAN          = {2, 5, 29, 17};   // subjectAltName

    private static KeyStore createSelfSignedKeystore(String file, String password) throws Exception {
        KeyPairGenerator kpg = KeyPairGenerator.getInstance("RSA");
        kpg.initialize(2048);
        KeyPair kp = kpg.generateKeyPair();

        byte[] certDer = buildSelfSignedCertDer(kp);

        CertificateFactory cf = CertificateFactory.getInstance("X.509");
        X509Certificate cert = (X509Certificate) cf.generateCertificate(
                new ByteArrayInputStream(certDer));
        cert.verify(kp.getPublic()); // sanity check

        KeyStore ks = KeyStore.getInstance(KeyStore.getDefaultType());
        ks.load(null, null);
        char[] pwd = password.toCharArray();
        ks.setKeyEntry("serversdiscovery", kp.getPrivate(), pwd, new X509Certificate[]{cert});

        try (FileOutputStream out = new FileOutputStream(file)) {
            ks.store(out, pwd);
        }
        log("[*] Keystore written to " + file);
        return ks;
    }

    private static byte[] buildSelfSignedCertDer(KeyPair kp) throws Exception {
        // name
        byte[] name = buildName(new Object[]{
                OID_CN,  "ServersDiscovery",
                OID_OU,  "P2P",
                OID_O,   "Unknown",
                OID_L,   "Unknown",
                OID_ST,  "Unknown",
                OID_C,   "US"});

        // validity (10 years, starting 1 day ago to avoid clock-skew issues)
        byte[] notBefore = derUtcTime(new Date(System.currentTimeMillis() - 86_400_000L));
        byte[] notAfter  = derUtcTime(new Date(System.currentTimeMillis() + 3650L * 86_400_000L));
        byte[] validity  = derSequence(notBefore, notAfter);

        // SubjectPublicKeyInfo comes straight from the JDK for RSA public keys
        byte[] spki = kp.getPublic().getEncoded();

        // extensions: BasicConstraints (CA=false) + SubjectAltName (localhost, 127.0.0.1)
        byte[] bcValue = derSequence(derBoolean(false));
        byte[] bcExt   = derExtension(OID_BC, false, bcValue);

        byte[] sanValue = derSequence(
                derWrap(0x82, "localhost".getBytes(StandardCharsets.US_ASCII)),
                derWrap(0x87, new byte[]{127, 0, 0, 1}));
        byte[] sanExt   = derExtension(OID_SAN, false, sanValue);

        byte[] extensions = derContextExplicit(3, derSequence(bcExt, sanExt));

        // TBS Certificate
        byte[] version  = derContextExplicit(0, derInteger(2L)); // v3
        byte[] serial   = derInteger(new BigInteger(64, new SecureRandom()));
        byte[] sigAlgId = derAlgorithmIdentifier(OID_SHA256_RSA);

        byte[] tbsContent = concat(version, serial, sigAlgId, name, validity, name, spki, extensions);
        byte[] tbsBytes   = derSequence(tbsContent);

        // sign TBS
        Signature signer = Signature.getInstance("SHA256withRSA");
        signer.initSign(kp.getPrivate());
        signer.update(tbsBytes);
        byte[] sigValue = derBitString(signer.sign());

        // outer Certificate
        return derSequence(tbsBytes, sigAlgId, sigValue);
    }

    private static byte[] buildName(Object[] kvPairs) {
        if (kvPairs.length % 2 != 0) throw new IllegalArgumentException("name: expected (oid, value) pairs");
        List<byte[]> rdns = new ArrayList<>();
        for (int i = 0; i < kvPairs.length; i += 2) {
            int[] oid  = (int[]) kvPairs[i];
            String val = (String) kvPairs[i + 1];
            byte[] atv = derSequence(derOid(oid), derPrintableString(val));
            rdns.add(derSet(atv));
        }
        byte[][] arr = new byte[rdns.size()][];
        return derSequence(rdns.toArray(arr));
    }

    private static byte[] derExtension(int[] oid, boolean critical, byte[] value) {
        return derSequence(derOid(oid),
                critical ? derBoolean(true) : new byte[0],
                derOctetString(value));
    }

    private static byte[] derAlgorithmIdentifier(int[] oid) {
        return derSequence(derOid(oid), derNull());
    }

    private static byte[] derBoolean(boolean v) {
        return derWrap(0x01, new byte[]{v ? (byte) 0xFF : (byte) 0x00});
    }

    private static byte[] derSequence(byte[]... parts) { return derWrap(0x30, parts); }
    private static byte[] derSet(byte[]... parts)      { return derWrap(0x31, parts); }
    private static byte[] derNull()                    { return new byte[]{0x05, 0x00}; }
    private static byte[] derOctetString(byte[] c)    { return derWrap(0x04, c); }
    private static byte[] derContextExplicit(int t, byte[] c) { return derWrap(0xA0 | t, c); }
    private static byte[] derPrintableString(String s) {
        return derWrap(0x13, s.getBytes(StandardCharsets.US_ASCII));
    }
    private static byte[] derUtcTime(Date d) {
        SimpleDateFormat sdf = new SimpleDateFormat("yyMMddHHmmss");
        sdf.setTimeZone(TimeZone.getTimeZone("UTC"));
        return derWrap(0x17, (sdf.format(d) + "Z").getBytes(StandardCharsets.US_ASCII));
    }

    private static byte[] derInteger(long v) {
        return derWrap(0x02, BigInteger.valueOf(v).toByteArray());
    }
    private static byte[] derInteger(BigInteger v) {
        byte[] raw = v.toByteArray();
        // ensure positive encoding
        if (raw.length > 0 && (raw[0] & 0x80) != 0) {
            byte[] padded = new byte[raw.length + 1];
            System.arraycopy(raw, 0, padded, 1, raw.length);
            raw = padded;
        }
        return derWrap(0x02, raw);
    }
    private static byte[] derBitString(byte[] content) {
        byte[] withPrefix = new byte[content.length + 1]; // 0 unused bits
        System.arraycopy(content, 0, withPrefix, 1, content.length);
        return derWrap(0x03, withPrefix);
    }

    private static byte[] derOid(int[] comp) {
        if (comp.length < 2) throw new IllegalArgumentException("OID too short");
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        out.write(comp[0] * 40 + comp[1]);
        for (int i = 2; i < comp.length; i++) {
            int c = comp[i];
            if (c < 128) {
                out.write(c);
            } else {
                int n = 0;
                int[] stack = new int[5];
                while (c > 0) { stack[n++] = c & 0x7F; c >>= 7; }
                for (int j = n - 1; j >= 0; j--) {
                    out.write(j == 0 ? stack[j] : (stack[j] | 0x80));
                }
            }
        }
        return derWrap(0x06, out.toByteArray());
    }

    private static byte[] derWrap(int tag, byte[]... parts) {
        int total = 0;
        for (byte[] p : parts) total += p.length;
        byte[] lenBytes = derLength(total);
        byte[] result = new byte[1 + lenBytes.length + total];
        result[0] = (byte) tag;
        System.arraycopy(lenBytes, 0, result, 1, lenBytes.length);
        int off = 1 + lenBytes.length;
        for (byte[] p : parts) {
            System.arraycopy(p, 0, result, off, p.length);
            off += p.length;
        }
        return result;
    }

    private static byte[] derLength(int len) {
        if (len < 0x80) return new byte[]{(byte) len};
        int n = 0;
        int tmp = len;
        while (tmp > 0) { n++; tmp >>>= 8; }
        byte[] r = new byte[1 + n];
        r[0] = (byte) (0x80 | n);
        for (int i = n; i > 0; i--) {
            r[i] = (byte) (len & 0xFF);
            len >>>= 8;
        }
        return r;
    }

    private static byte[] concat(byte[]... parts) {
        int total = 0;
        for (byte[] p : parts) total += p.length;
        byte[] result = new byte[total];
        int off = 0;
        for (byte[] p : parts) {
            System.arraycopy(p, 0, result, off, p.length);
            off += p.length;
        }
        return result;
    }
}
