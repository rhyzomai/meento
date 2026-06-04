import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.*;
import java.util.concurrent.*;

/**
 * MeshRank — ranks mesh-node servers by file count and public-key prevalence.
 *
 * Layout expected on every server:
 *   /files.txt          — one filename per line  (e.g. <sha256>.jpg)
 *   /files/<filename>   — the actual file
 *   /info/<hash>.json   — metadata JSON with at least "public_key" field
 *
 * Elimination rules:
 *   1. If ANY file listed in files.txt does not match its hash  ? server eliminated.
 *   2. If two servers share the same current IP (SHA-256 of IP)  ? both eliminated.
 *
 * Ranking:
 *   - Top-100 servers by number of valid files stored.
 *   - Top-100 public keys by how many valid servers carry them as the majority key.
 *
 * Output: rank.txt (UTF-8)
 */
public class MeshRank {

    // -- tunables ----------------------------------------------------------------
    private static final int    CONNECT_TIMEOUT_MS  = 8_000;
    private static final int    READ_TIMEOUT_MS     = 30_000;
    private static final int    THREAD_POOL_SIZE    = 16;
    private static final int    MAX_RANK            = 100;
    private static final String SERVERS_FILE        = "servers.txt";
    private static final String OUTPUT_FILE         = "rank.txt";

    // -- entry point -------------------------------------------------------------
    public static void main(String[] args) throws Exception {
        System.out.println("=== MeshRank starting ===");
        System.out.println("Reading " + SERVERS_FILE + " …");

        Map<String, String> serverMap = readServerList(SERVERS_FILE); // baseUrl -> original
        if (serverMap.isEmpty()) {
            System.out.println("[WARN] " + SERVERS_FILE + " is empty or not found. Nothing to do.");
        } else {
            System.out.println("Found " + serverMap.size() + " server(s). Probing …\n");
        }

        // -- parallel probe -------------------------------------------------------
        ExecutorService pool = Executors.newFixedThreadPool(
                Math.min(THREAD_POOL_SIZE, Math.max(1, serverMap.size())));

        List<Future<ServerResult>> futures = new ArrayList<>();
        for (Map.Entry<String, String> entry : serverMap.entrySet()) {
            String baseUrl   = entry.getKey();
            String original  = entry.getValue();
            futures.add(pool.submit(new ServerProbe(original, baseUrl)));
        }
        pool.shutdown();
        pool.awaitTermination(10, TimeUnit.MINUTES);

        // -- collect raw results --------------------------------------------------
        List<ServerResult> results = new ArrayList<>();
        for (Future<ServerResult> f : futures) {
            try { results.add(f.get()); }
            catch (Exception e) { /* already logged inside probe */ }
        }

        // -- duplicate-IP elimination ---------------------------------------------
        eliminateDuplicateIPs(results);

        // -- only keep eligible servers -------------------------------------------
        List<ServerResult> eligible = new ArrayList<>();
        for (ServerResult r : results) {
            if (r.eligible) eligible.add(r);
        }

        // -- consensus public-key per file across eligible servers ----------------
        // fileHash -> list of public_keys reported by all eligible servers
        Map<String, List<String>> fileKeyVotes = new HashMap<>();
        for (ServerResult r : eligible) {
            for (Map.Entry<String, String> e : r.filePublicKeys.entrySet()) {
                fileKeyVotes.computeIfAbsent(e.getKey(), k -> new ArrayList<>()).add(e.getValue());
            }
        }
        // consensus: majority public_key wins; ties ? discard
        Map<String, String> consensusKey = new HashMap<>(); // fileHash -> winning key
        for (Map.Entry<String, List<String>> e : fileKeyVotes.entrySet()) {
            String winner = majorityVote(e.getValue());
            if (winner != null) consensusKey.put(e.getKey(), winner);
        }

        // For each eligible server, count only files whose info-key matches consensus
        // and accumulate per-public-key server count
        Map<String, Integer> pkServerCount = new HashMap<>(); // publicKey -> #servers carrying it

        for (ServerResult r : eligible) {
            // Recount using consensus
            int consensusFileCount = 0;
            Set<String> keysOnThisServer = new HashSet<>();
            for (String hash : r.validFileHashes) {
                String ck = consensusKey.get(hash);
                String sk = r.filePublicKeys.get(hash);
                if (ck != null && ck.equals(sk)) {
                    consensusFileCount++;
                    keysOnThisServer.add(ck);
                }
            }
            r.consensusFileCount = consensusFileCount;
            // Each unique consensus-key on this server counts +1 server for that key
            for (String pk : keysOnThisServer) {
                pkServerCount.merge(pk, 1, Integer::sum);
            }
        }

        // -- build rankings -------------------------------------------------------
        eligible.sort((a, b) -> Integer.compare(b.consensusFileCount, a.consensusFileCount));
        List<ServerResult> topServers = eligible.subList(0, Math.min(MAX_RANK, eligible.size()));

        List<Map.Entry<String, Integer>> pkRank = new ArrayList<>(pkServerCount.entrySet());
        pkRank.sort((a, b) -> Integer.compare(b.getValue(), a.getValue()));
        if (pkRank.size() > MAX_RANK) pkRank = pkRank.subList(0, MAX_RANK);

        // -- write rank.txt -------------------------------------------------------
        writeRankFile(topServers, pkRank);

        System.out.println("\n=== Done. Results written to " + OUTPUT_FILE + " ===");
        System.out.println("Press ENTER to exit …");
        new BufferedReader(new InputStreamReader(System.in)).readLine();
    }

    // -- read servers.txt — returns LinkedHashMap original?baseUrl (deduped by base) --
    private static Map<String, String> readServerList(String path) throws IOException {
        File f = new File(path);
        if (!f.exists()) return Collections.emptyMap();
        Map<String, String> out = new LinkedHashMap<>(); // baseUrl -> originalLine (first seen wins)
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

    /**
     * Derive the directory base URL from any server entry.
     *
     * Rules (in order):
     *  1. Strip trailing slashes.
     *  2. Look at the last path segment (after the final '/').
     *     If it contains a '.' it is treated as a filename and removed.
     *  3. Strip trailing slashes again.
     *
     * Examples:
     *   http://localhost/meento/1/index.php  ?  http://localhost/meento/1
     *   https://test.com/index.php           ?  https://test.com
     *   https://test.com                     ?  https://test.com
     *   https://test.com/                    ?  https://test.com
     *   http://localhost/meento/1/           ?  http://localhost/meento/1
     */
    static String deriveBaseUrl(String raw) {
        // Remove trailing slashes
        String s = raw.replaceAll("/+$", "");

        // Find where the path starts (after scheme://host[:port])
        // We only touch the path portion, never the host.
        int schemeEnd = s.indexOf("://");
        int pathStart = (schemeEnd >= 0) ? s.indexOf('/', schemeEnd + 3) : s.indexOf('/');

        if (pathStart < 0) {
            // No path at all — just scheme://host
            return s;
        }

        // Last segment
        int lastSlash = s.lastIndexOf('/');
        if (lastSlash >= pathStart) {
            String lastSegment = s.substring(lastSlash + 1); // may be empty
            if (lastSegment.contains(".")) {
                // It's a file (e.g. index.php) — drop it
                s = s.substring(0, lastSlash);
                // Strip any trailing slashes that may have been left
                s = s.replaceAll("/+$", "");
            }
        }

        return s;
    }

    // -- duplicate-IP check ------------------------------------------------------
    private static void eliminateDuplicateIPs(List<ServerResult> results) {
        // ipHash -> first server that claimed it
        Map<String, ServerResult> seen = new LinkedHashMap<>();
        for (ServerResult r : results) {
            if (!r.eligible || r.ipHash == null) continue;
            if (seen.containsKey(r.ipHash)) {
                ServerResult first = seen.get(r.ipHash);
                first.eligible = false;
                r.eligible = false;
                System.out.println("[ELIMINATED] Duplicate IP (SHA-256: " + r.ipHash.substring(0, 16)
                        + "…): " + first.baseUrl + " and " + r.baseUrl);
            } else {
                seen.put(r.ipHash, r);
            }
        }
    }

    // -- majority vote (>50%) — null on tie or empty ------------------------------
    private static String majorityVote(List<String> votes) {
        if (votes == null || votes.isEmpty()) return null;
        Map<String, Integer> counts = new HashMap<>();
        for (String v : votes) counts.merge(v, 1, Integer::sum);
        int threshold = votes.size() / 2; // strictly more than half
        String best = null; int bestCount = 0;
        for (Map.Entry<String, Integer> e : counts.entrySet()) {
            if (e.getValue() > bestCount) { bestCount = e.getValue(); best = e.getKey(); }
        }
        if (bestCount > threshold) return best;
        return null; // tie or no majority
    }

    // -- write rank.txt ----------------------------------------------------------
    private static void writeRankFile(List<ServerResult> servers,
                                      List<Map.Entry<String, Integer>> pkRank) throws IOException {
        try (PrintWriter pw = new PrintWriter(
                new BufferedWriter(new OutputStreamWriter(
                        new FileOutputStream(OUTPUT_FILE), StandardCharsets.UTF_8)))) {

            pw.println("============================================================");
            pw.println("  MESH NODE RANK — " + new Date());
            pw.println("============================================================");
            pw.println();

            // -- SERVER RANKING ---------------------------------------------------
            pw.println("-- TOP " + MAX_RANK + " SERVERS BY FILE COUNT -------------------------");
            pw.println();
            if (servers.isEmpty()) {
                pw.println("  (no eligible servers)");
            } else {
                int rank = 1;
                for (ServerResult r : servers) {
                    pw.printf("  %3d. %-60s  %d file(s)%n", rank++, r.baseUrl, r.consensusFileCount);
                }
            }
            pw.println();

            // -- PUBLIC-KEY RANKING -----------------------------------------------
            pw.println("-- TOP " + MAX_RANK + " PUBLIC KEYS BY SERVER COUNT ------------------");
            pw.println();
            if (pkRank.isEmpty()) {
                pw.println("  (no public-key data)");
            } else {
                int rank = 1;
                for (Map.Entry<String, Integer> e : pkRank) {
                    pw.printf("  %3d. %-80s  %d server(s)%n", rank++, e.getKey(), e.getValue());
                }
            }
            pw.println();
            pw.println("============================================================");
        }
    }

    // ----------------------------------------------------------------------------
    //  Per-server result holder
    // ----------------------------------------------------------------------------
    static class ServerResult {
        final String url;          // original entry from servers.txt
        final String baseUrl;      // directory base (no trailing slash, no filename)
        boolean eligible = true;
        String  ipHash   = null;   // SHA-256 of resolved IP
        List<String> validFileHashes = new ArrayList<>();           // hashes that passed content check
        Map<String, String> filePublicKeys = new LinkedHashMap<>(); // hash -> public_key from info JSON
        int consensusFileCount = 0;                                 // filled in after consensus step

        ServerResult(String url, String baseUrl) {
            this.url     = url;
            this.baseUrl = baseUrl;
        }
    }

    // ----------------------------------------------------------------------------
    //  Callable that probes one server
    // ----------------------------------------------------------------------------
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
                // -- resolve IP and hash it ---------------------------------------
                String host = new URL(baseUrl).getHost();
                try {
                    InetAddress addr = InetAddress.getByName(host);
                    result.ipHash = sha256(addr.getHostAddress());
                } catch (Exception ex) {
                    System.out.println("[WARN]  " + originalUrl
                            + " — cannot resolve host: " + ex.getMessage());
                }

                // -- fetch files.txt ----------------------------------------------
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

                // -- verify each file ---------------------------------------------
                for (String filename : filenames) {
                    // derive expected hash and algorithm from filename
                    String expectedHash = stripExtension(filename).toLowerCase();
                    String algo = detectAlgo(expectedHash);

                    if (algo == null) {
                        // Not an MD5 or SHA-256 name — skip silently
                        continue;
                    }

                    // fetch file bytes
                    byte[] fileBytes = fetchBytes(baseUrl + "/files/" + filename);
                    if (fileBytes == null) {
                        System.out.println("[ELIMINATED] " + originalUrl
                                + " — file not found: " + filename);
                        result.eligible = false;
                        return result;
                    }

                    // compute actual hash
                    String actualHash = hashBytes(fileBytes, algo).toLowerCase();
                    if (!actualHash.equals(expectedHash)) {
                        System.out.println("[ELIMINATED] " + originalUrl
                                + " — hash mismatch for " + filename
                                + " (expected=" + expectedHash.substring(0, 12) + "…"
                                + " actual=" + actualHash.substring(0, 12) + "…)");
                        result.eligible = false;
                        return result;
                    }

                    // -- fetch info JSON ------------------------------------------
                    String hash = expectedHash;
                    String infoJson = fetchText(baseUrl + "/info/" + hash + ".json");
                    String publicKey = "";
                    if (infoJson != null) {
                        publicKey = extractJsonField(infoJson, "public_key");
                    }

                    result.validFileHashes.add(hash);
                    result.filePublicKeys.put(hash, publicKey);
                }

                System.out.println("[OK]    " + originalUrl + " — " + result.validFileHashes.size()
                        + " valid file(s)");

            } catch (Exception ex) {
                System.out.println("[ERROR] " + originalUrl + " — " + ex.getMessage());
                result.eligible = false;
            }

            return result;
        }

        // -- detect algorithm by hex-string length --------------------------------
        private static String detectAlgo(String hex) {
            if (hex.length() == 32)  return "MD5";
            if (hex.length() == 64)  return "SHA-256";
            return null;
        }

        // -- strip extension ------------------------------------------------------
        private static String stripExtension(String name) {
            int dot = name.lastIndexOf('.');
            return (dot > 0) ? name.substring(0, dot) : name;
        }

        // -- hash bytes -----------------------------------------------------------
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

        // -- fetch text from URL --------------------------------------------------
        private static String fetchText(String rawUrl) {
            try {
                byte[] bytes = fetchBytes(rawUrl);
                if (bytes == null) return null;
                return new String(bytes, StandardCharsets.UTF_8);
            } catch (Exception e) {
                return null;
            }
        }

        // -- fetch raw bytes from URL ---------------------------------------------
        private static byte[] fetchBytes(String rawUrl) {
            try {
                URL u = new URL(rawUrl);
                HttpURLConnection conn = (HttpURLConnection) u.openConnection();
                conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
                conn.setReadTimeout(READ_TIMEOUT_MS);
                conn.setRequestProperty("User-Agent", "MeshRank/1.0");
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

        // -- InputStream ? byte[] (Java 8 compatible) -----------------------------
        private static byte[] readAllBytes(InputStream is) throws IOException {
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            byte[] chunk = new byte[8192];
            int n;
            while ((n = is.read(chunk)) != -1) buf.write(chunk, 0, n);
            return buf.toByteArray();
        }

        // -- split newline-separated lines, trim, drop blanks --------------------
        private static List<String> parseLines(String text) {
            List<String> out = new ArrayList<>();
            for (String line : text.split("[\r\n]+")) {
                line = line.trim();
                if (!line.isEmpty()) out.add(line);
            }
            return out;
        }

        // -- naive JSON field extractor (no external library) ---------------------
        // Handles: "public_key" : "somevalue"
        private static String extractJsonField(String json, String field) {
            // Pattern: "field"\s*:\s*"value"
            String search = "\"" + field + "\"";
            int idx = json.indexOf(search);
            if (idx < 0) return "";
            int colon = json.indexOf(':', idx + search.length());
            if (colon < 0) return "";
            // skip whitespace after colon
            int start = colon + 1;
            while (start < json.length() && Character.isWhitespace(json.charAt(start))) start++;
            if (start >= json.length()) return "";
            char first = json.charAt(start);
            if (first == '"') {
                // string value — read until closing quote (handle simple escapes)
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
                // non-string (number/bool/null) — read until , or } or ]
                int end = start;
                while (end < json.length()
                        && json.charAt(end) != ',' && json.charAt(end) != '}'
                        && json.charAt(end) != ']') {
                    end++;
                }
                return json.substring(start, end).trim();
            }
        }
    }

    // -- SHA-256 of a string ------------------------------------------------------
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
}