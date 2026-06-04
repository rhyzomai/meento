import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.*;
import java.util.concurrent.*;

/**
 * MeshRankSmart — ranks mesh-node servers by smart file-spread scoring.
 *
 * Layout expected on every server:
 *   /files.txt          — one filename per line  (e.g. <sha256>.jpg)
 *   /files/<filename>   — the actual file
 *   /info/<hash>.json   — metadata JSON with at least "public_key" field
 *
 * Rules:
 *   1. If ANY file listed in files.txt does not match its hash ? server ineligible.
 *   2. Duplicate IPs are NOT eliminated; however each unique IP counts only once
 *      when computing scores (so 5 servers sharing an IP contribute only 1 point).
 *
 * Scoring (smart):
 *   Each server's score = number of DISTINCT IPs (other than itself) that also
 *   host at least one of that server's files.
 *
 *   Example: server "A" owns 1000 files. Server "B" (different IP) hosts 2 of
 *   them, server "C" (different IP) hosts 3 of them ? A's score = 2.
 *   If "D" shares the same IP as "B", D does not add another point.
 *
 * Output: rank_smart.txt (UTF-8), descending order by score.
 */
public class MeshRankSmart {

    // -- tunables ----------------------------------------------------------------
    private static final int    CONNECT_TIMEOUT_MS  = 8_000;
    private static final int    READ_TIMEOUT_MS     = 30_000;
    private static final int    THREAD_POOL_SIZE    = 16;
    private static final int    MAX_RANK            = 100;
    private static final String SERVERS_FILE        = "servers.txt";
    private static final String OUTPUT_FILE         = "rank_smart.txt";

    // -- entry point -------------------------------------------------------------
    public static void main(String[] args) throws Exception {
        System.out.println("=== MeshRankSmart starting ===");
        System.out.println("Reading " + SERVERS_FILE + " ...");

        Map<String, String> serverMap = readServerList(SERVERS_FILE); // baseUrl -> original
        if (serverMap.isEmpty()) {
            System.out.println("[WARN] " + SERVERS_FILE + " is empty or not found. Nothing to do.");
        } else {
            System.out.println("Found " + serverMap.size() + " server(s). Probing ...\n");
        }

        // -- parallel probe -------------------------------------------------------
        ExecutorService pool = Executors.newFixedThreadPool(
                Math.min(THREAD_POOL_SIZE, Math.max(1, serverMap.size())));

        List<Future<ServerResult>> futures = new ArrayList<>();
        for (Map.Entry<String, String> entry : serverMap.entrySet()) {
            String baseUrl  = entry.getKey();
            String original = entry.getValue();
            futures.add(pool.submit(new ServerProbe(original, baseUrl)));
        }
        pool.shutdown();
        pool.awaitTermination(10, TimeUnit.MINUTES);

        // -- collect results ------------------------------------------------------
        List<ServerResult> results = new ArrayList<>();
        for (Future<ServerResult> f : futures) {
            try { results.add(f.get()); }
            catch (Exception e) { /* already logged inside probe */ }
        }

        // -- only keep eligible servers -------------------------------------------
        List<ServerResult> eligible = new ArrayList<>();
        for (ServerResult r : results) {
            if (r.eligible) eligible.add(r);
        }

        System.out.println("\nEligible servers: " + eligible.size());

        // -------------------------------------------------------------------------
        // Smart scoring
        //
        // Goal: for each server S, count how many DISTINCT IPs (excluding S's own IP)
        //       host at least one file that S also hosts (i.e., a file S owns/lists).
        //
        // "Owns" = the file hash appears in S's validFileHashes.
        //
        // Steps:
        //   1. Build a map: fileHash -> Set<ipHash> of eligible servers that carry it
        //      (one representative per unique IP — first server seen for that IP wins)
        //   2. For each server S:
        //        score = number of distinct IPs (ipHash) that appear in the union of
        //                fileHash->ipSet for all hashes S owns, MINUS S's own IP.
        // -------------------------------------------------------------------------

        // Step 1: fileHash -> Set of DISTINCT ipHashes that host the file
        // We allow at most ONE representative per ipHash per file (already ensured by Set).
        Map<String, Set<String>> fileIpSets = new HashMap<>();
        for (ServerResult r : eligible) {
            if (r.ipHash == null) continue;
            for (String hash : r.validFileHashes) {
                fileIpSets.computeIfAbsent(hash, k -> new HashSet<>()).add(r.ipHash);
            }
        }

        // Step 2: score each server
        for (ServerResult s : eligible) {
            // Collect all distinct foreign IPs that host any of S's files
            Set<String> foreignIPs = new HashSet<>();
            for (String hash : s.validFileHashes) {
                Set<String> ips = fileIpSets.get(hash);
                if (ips == null) continue;
                for (String ip : ips) {
                    // Exclude S's own IP
                    if (!ip.equals(s.ipHash)) {
                        foreignIPs.add(ip);
                    }
                }
            }
            s.smartScore = foreignIPs.size();

            System.out.println("[SCORE] " + s.baseUrl
                    + "  files=" + s.validFileHashes.size()
                    + "  score=" + s.smartScore
                    + (s.ipHash == null ? "  [no-IP]" : ""));
        }

        // -- sort descending by smartScore ----------------------------------------
        eligible.sort((a, b) -> Integer.compare(b.smartScore, a.smartScore));

        List<ServerResult> topServers = eligible.subList(0, Math.min(MAX_RANK, eligible.size()));

        // -- write rank_smart.txt -------------------------------------------------
        writeRankFile(topServers, eligible.size());

        System.out.println("\n=== Done. Results written to " + OUTPUT_FILE + " ===");
        System.out.println("Press ENTER to exit ...");
        new BufferedReader(new InputStreamReader(System.in)).readLine();
    }

    // -- read servers.txt --------------------------------------------------------
    private static Map<String, String> readServerList(String path) throws IOException {
        File f = new File(path);
        if (!f.exists()) return Collections.emptyMap();
        Map<String, String> out = new LinkedHashMap<>();
        try (BufferedReader br = new BufferedReader(new FileReader(f, StandardCharsets.UTF_8))) {
            String line;
            while ((line = br.readLine()) != null) {
                String original = line.trim();
                if (original.isEmpty() || original.startsWith("#")) continue;
                String normalised = original;
                if (!normalised.matches("(?i)https?://.*")) normalised = "http://" + normalised;
                String base = deriveBaseUrl(normalised);
                if (!out.containsKey(base)) out.put(base, original);
            }
        }
        return out;
    }

    // -- derive the directory base URL from any server entry ---------------------
    static String deriveBaseUrl(String raw) {
        String s = raw.replaceAll("/+$", "");
        int schemeEnd = s.indexOf("://");
        int pathStart = (schemeEnd >= 0) ? s.indexOf('/', schemeEnd + 3) : s.indexOf('/');
        if (pathStart < 0) return s;
        int lastSlash = s.lastIndexOf('/');
        if (lastSlash >= pathStart) {
            String lastSegment = s.substring(lastSlash + 1);
            if (lastSegment.contains(".")) {
                s = s.substring(0, lastSlash).replaceAll("/+$", "");
            }
        }
        return s;
    }

    // -- write rank_smart.txt ----------------------------------------------------
    private static void writeRankFile(List<ServerResult> servers, int totalEligible)
            throws IOException {
        try (PrintWriter pw = new PrintWriter(
                new BufferedWriter(new OutputStreamWriter(
                        new FileOutputStream(OUTPUT_FILE), StandardCharsets.UTF_8)))) {

            pw.println("============================================================");
            pw.println("  MESH NODE SMART RANK — " + new Date());
            pw.println("============================================================");
            pw.println();
            pw.println("  Scoring: number of distinct foreign IPs hosting your files.");
            pw.println("  Duplicate IPs count as one. Own IP not counted.");
            pw.println("  Total eligible servers: " + totalEligible);
            pw.println();
            pw.println("-- TOP " + MAX_RANK + " SERVERS (descending by smart score) ----------");
            pw.println();

            if (servers.isEmpty()) {
                pw.println("  (no eligible servers)");
            } else {
                int rank = 1;
                for (ServerResult r : servers) {
                    pw.printf("  %3d. %-60s  files=%-6d  score=%d%n",
                            rank++, r.baseUrl, r.validFileHashes.size(), r.smartScore);
                }
            }

            pw.println();
            pw.println("============================================================");
        }
    }

    // -- SHA-256 of a string -----------------------------------------------------
    static String sha256(String input) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            byte[] digest = md.digest(input.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder(64);
            for (byte b : digest) sb.append(String.format("%02x", b & 0xff));
            return sb.toString();
        } catch (NoSuchAlgorithmException e) {
            throw new RuntimeException(e);
        }
    }

    // --------------------------------------------------------------------------
    //  Per-server result holder
    // --------------------------------------------------------------------------
    static class ServerResult {
        final String url;
        final String baseUrl;
        boolean eligible = true;
        String  ipHash   = null;                              // SHA-256 of resolved IP
        List<String> validFileHashes = new ArrayList<>();    // hashes that passed content check
        Map<String, String> filePublicKeys = new LinkedHashMap<>(); // hash -> public_key
        int smartScore = 0;                                  // distinct foreign IPs hosting my files

        ServerResult(String url, String baseUrl) {
            this.url     = url;
            this.baseUrl = baseUrl;
        }
    }

    // --------------------------------------------------------------------------
    //  Callable that probes one server
    // --------------------------------------------------------------------------
    static class ServerProbe implements Callable<ServerResult> {
        private final String originalUrl;
        private final String baseUrl;

        ServerProbe(String originalUrl, String baseUrl) {
            this.originalUrl = originalUrl;
            this.baseUrl     = baseUrl;
        }

        @Override
        public ServerResult call() {
            ServerResult result = new ServerResult(originalUrl, baseUrl);
            System.out.println("[PROBE] " + originalUrl + "  ?  base: " + baseUrl);

            try {
                // -- resolve IP and hash it ----------------------------------------
                String host = new URL(baseUrl).getHost();
                try {
                    InetAddress addr = InetAddress.getByName(host);
                    result.ipHash = sha256(addr.getHostAddress());
                } catch (Exception ex) {
                    System.out.println("[WARN]  " + originalUrl
                            + " — cannot resolve host: " + ex.getMessage());
                }

                // -- fetch files.txt -----------------------------------------------
                String filesTxt = fetchText(baseUrl + "/files.txt");
                if (filesTxt == null) {
                    System.out.println("[SKIP]  " + originalUrl + " — cannot fetch files.txt");
                    result.eligible = false;
                    return result;
                }

                List<String> filenames = parseLines(filesTxt);
                if (filenames.isEmpty()) {
                    System.out.println("[OK]    " + originalUrl + " — files.txt is empty (0 files)");
                    return result;
                }

                // -- verify each file ----------------------------------------------
                for (String filename : filenames) {
                    String expectedHash = stripExtension(filename).toLowerCase();
                    String algo = detectAlgo(expectedHash);
                    if (algo == null) continue; // skip non-hash filenames

                    byte[] fileBytes = fetchBytes(baseUrl + "/files/" + filename);
                    if (fileBytes == null) {
                        System.out.println("[ELIMINATED] " + originalUrl
                                + " — file not found: " + filename);
                        result.eligible = false;
                        return result;
                    }

                    String actualHash = hashBytes(fileBytes, algo).toLowerCase();
                    if (!actualHash.equals(expectedHash)) {
                        System.out.println("[ELIMINATED] " + originalUrl
                                + " — hash mismatch for " + filename
                                + " (expected=" + expectedHash.substring(0, 12) + "..."
                                + " actual=" + actualHash.substring(0, 12) + "...)");
                        result.eligible = false;
                        return result;
                    }

                    // -- fetch info JSON -------------------------------------------
                    String infoJson = fetchText(baseUrl + "/info/" + expectedHash + ".json");
                    String publicKey = "";
                    if (infoJson != null) {
                        publicKey = extractJsonField(infoJson, "public_key");
                    }

                    result.validFileHashes.add(expectedHash);
                    result.filePublicKeys.put(expectedHash, publicKey);
                }

                System.out.println("[OK]    " + originalUrl
                        + " — " + result.validFileHashes.size() + " valid file(s)");

            } catch (Exception ex) {
                System.out.println("[ERROR] " + originalUrl + " — " + ex.getMessage());
                result.eligible = false;
            }

            return result;
        }

        private static String detectAlgo(String hex) {
            if (hex.length() == 32) return "MD5";
            if (hex.length() == 64) return "SHA-256";
            return null;
        }

        private static String stripExtension(String name) {
            int dot = name.lastIndexOf('.');
            return (dot > 0) ? name.substring(0, dot) : name;
        }

        private static String hashBytes(byte[] data, String algo) {
            try {
                MessageDigest md = MessageDigest.getInstance(algo);
                byte[] digest = md.digest(data);
                StringBuilder sb = new StringBuilder(digest.length * 2);
                for (byte b : digest) sb.append(String.format("%02x", b & 0xff));
                return sb.toString();
            } catch (NoSuchAlgorithmException e) {
                throw new RuntimeException(e);
            }
        }

        private static String fetchText(String rawUrl) {
            try {
                byte[] bytes = fetchBytes(rawUrl);
                if (bytes == null) return null;
                return new String(bytes, StandardCharsets.UTF_8);
            } catch (Exception e) {
                return null;
            }
        }

        private static byte[] fetchBytes(String rawUrl) {
            try {
                URL u = new URL(rawUrl);
                HttpURLConnection conn = (HttpURLConnection) u.openConnection();
                conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
                conn.setReadTimeout(READ_TIMEOUT_MS);
                conn.setRequestProperty("User-Agent", "MeshRankSmart/1.0");
                conn.setInstanceFollowRedirects(true);
                int code = conn.getResponseCode();
                if (code != 200) return null;
                try (InputStream is = conn.getInputStream()) {
                    return readAllBytes(is);
                }
            } catch (Exception e) {
                return null;
            }
        }

        private static byte[] readAllBytes(InputStream is) throws IOException {
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            byte[] chunk = new byte[8192];
            int n;
            while ((n = is.read(chunk)) != -1) buf.write(chunk, 0, n);
            return buf.toByteArray();
        }

        private static List<String> parseLines(String text) {
            List<String> out = new ArrayList<>();
            for (String line : text.split("[\r\n]+")) {
                line = line.trim();
                if (!line.isEmpty()) out.add(line);
            }
            return out;
        }

        private static String extractJsonField(String json, String field) {
            String search = "\"" + field + "\"";
            int idx = json.indexOf(search);
            if (idx < 0) return "";
            int colon = json.indexOf(':', idx + search.length());
            if (colon < 0) return "";
            int start = colon + 1;
            while (start < json.length() && Character.isWhitespace(json.charAt(start))) start++;
            if (start >= json.length()) return "";
            char first = json.charAt(start);
            if (first == '"') {
                StringBuilder sb = new StringBuilder();
                int i = start + 1;
                while (i < json.length()) {
                    char c = json.charAt(i);
                    if (c == '\\' && i + 1 < json.length()) {
                        char esc = json.charAt(i + 1);
                        switch (esc) {
                            case '"':  sb.append('"');  break;
                            case '\\': sb.append('\\'); break;
                            case '/':  sb.append('/');  break;
                            case 'n':  sb.append('\n'); break;
                            case 'r':  sb.append('\r'); break;
                            case 't':  sb.append('\t'); break;
                            default:   sb.append(esc);  break;
                        }
                        i += 2;
                    } else if (c == '"') {
                        break;
                    } else {
                        sb.append(c);
                        i++;
                    }
                }
                return sb.toString();
            } else {
                int end = start;
                while (end < json.length()
                        && json.charAt(end) != ','
                        && json.charAt(end) != '}'
                        && json.charAt(end) != ']') {
                    end++;
                }
                return json.substring(start, end).trim();
            }
        }
    }
}