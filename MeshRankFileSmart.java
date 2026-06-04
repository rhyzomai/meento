import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.*;
import java.util.concurrent.*;

/**
 * MeshRankFileSmart — ranks files by how many distinct IPs host them.
 *
 * Layout expected on every server:
 *   /files.txt          — one filename per line  (e.g. <sha256>.jpg)
 *   /files/<filename>   — the actual file
 *   /info/<hash>.json   — metadata JSON with at least a "public_key" field
 *
 * Rules:
 *   1. If ANY file listed in files.txt does not match its hash ? server ineligible.
 *   2. Duplicate IPs are NOT eliminated, but each unique IP counts only ONCE
 *      per file (so 5 servers sharing an IP contribute 1 vote, not 5).
 *
 * Ranking:
 *   Each file's score = number of DISTINCT IPs hosting it (descending).
 *   Alongside each file, the majority public_key is shown:
 *     - Each distinct IP casts one vote for the public_key found in /info/<hash>.json.
 *     - The key that appears in strictly more than half the votes wins.
 *     - If there is no majority, the public_key is shown as "(no consensus)".
 *
 * Output: rank_files_smart.txt (UTF-8), descending by distinct-IP count.
 */
public class MeshRankFileSmart {

    // -- tunables ----------------------------------------------------------------
    private static final int    CONNECT_TIMEOUT_MS  = 8_000;
    private static final int    READ_TIMEOUT_MS     = 30_000;
    private static final int    THREAD_POOL_SIZE    = 16;
    private static final int    MAX_RANK            = 100;
    private static final String SERVERS_FILE        = "servers.txt";
    private static final String OUTPUT_FILE         = "rank_files_smart.txt";

    // -- entry point -------------------------------------------------------------
    public static void main(String[] args) throws Exception {
        System.out.println("=== MeshRankFileSmart starting ===");
        System.out.println("Reading " + SERVERS_FILE + " ...");

        Map<String, String> serverMap = readServerList(SERVERS_FILE);
        if (serverMap.isEmpty()) {
            System.out.println("[WARN] " + SERVERS_FILE + " is empty or not found. Nothing to do.");
            return;
        }
        System.out.println("Found " + serverMap.size() + " server(s). Probing ...\n");

        // -- parallel probe -------------------------------------------------------
        ExecutorService pool = Executors.newFixedThreadPool(
                Math.min(THREAD_POOL_SIZE, Math.max(1, serverMap.size())));

        List<Future<ServerResult>> futures = new ArrayList<>();
        for (Map.Entry<String, String> entry : serverMap.entrySet()) {
            futures.add(pool.submit(new ServerProbe(entry.getValue(), entry.getKey())));
        }
        pool.shutdown();
        pool.awaitTermination(10, TimeUnit.MINUTES);

        // -- collect eligible results ---------------------------------------------
        List<ServerResult> eligible = new ArrayList<>();
        for (Future<ServerResult> f : futures) {
            try {
                ServerResult r = f.get();
                if (r.eligible) eligible.add(r);
            } catch (Exception e) { /* logged inside probe */ }
        }
        System.out.println("\nEligible servers: " + eligible.size());

        // -------------------------------------------------------------------------
        // File ranking
        //
        // For each file hash we need:
        //   - the set of distinct IPs that host it  (score = size of that set)
        //   - one public_key vote per distinct IP    (majority vote wins)
        //
        // Strategy:
        //   Walk every eligible server. For each file hash it carries:
        //     - if we have NOT yet seen this IP for this hash:
        //         record the IP and its public_key vote.
        //
        // Data structure:
        //   fileHash -> Map<ipHash, publicKey>   (one entry per distinct IP)
        // -------------------------------------------------------------------------

        // fileHash -> ( ipHash -> publicKey )
        Map<String, Map<String, String>> fileData = new LinkedHashMap<>();

        for (ServerResult r : eligible) {
            if (r.ipHash == null) continue;
            for (Map.Entry<String, String> e : r.filePublicKeys.entrySet()) {
                String hash      = e.getKey();
                String publicKey = e.getValue();
                // computeIfAbsent gives us the per-file ip->key map
                Map<String, String> ipVotes = fileData.computeIfAbsent(
                        hash, k -> new LinkedHashMap<>());
                // only the first server seen for a given IP casts the vote
                ipVotes.putIfAbsent(r.ipHash, publicKey);
            }
        }

        // -- build FileEntry list -------------------------------------------------
        List<FileEntry> entries = new ArrayList<>();
        for (Map.Entry<String, Map<String, String>> e : fileData.entrySet()) {
            String hash              = e.getKey();
            Map<String, String> ipVotes = e.getValue();
            int distinctIPs          = ipVotes.size();
            String majorityKey       = majorityVote(new ArrayList<>(ipVotes.values()));
            entries.add(new FileEntry(hash, distinctIPs, majorityKey));
        }

        // -- sort descending by distinct-IP count ---------------------------------
        entries.sort((a, b) -> Integer.compare(b.distinctIPs, a.distinctIPs));

        // -- trim to MAX_RANK -----------------------------------------------------
        if (entries.size() > MAX_RANK) entries = entries.subList(0, MAX_RANK);

        // -- write output ---------------------------------------------------------
        writeRankFile(entries, eligible.size());

        System.out.println("=== Done. Results written to " + OUTPUT_FILE + " ===");
        System.out.println("Press ENTER to exit ...");
        new BufferedReader(new InputStreamReader(System.in)).readLine();
    }

    // -- majority vote: returns the key held by strictly more than half the votes -
    // -- returns "(no consensus)" on tie, empty input, or no majority ------------
    private static String majorityVote(List<String> votes) {
        if (votes == null || votes.isEmpty()) return "(no consensus)";
        Map<String, Integer> counts = new HashMap<>();
        for (String v : votes) counts.merge(v, 1, Integer::sum);
        int threshold = votes.size() / 2; // must be strictly greater than half
        String best = null;
        int bestCount = 0;
        for (Map.Entry<String, Integer> e : counts.entrySet()) {
            if (e.getValue() > bestCount) {
                bestCount = e.getValue();
                best = e.getKey();
            }
        }
        return (bestCount > threshold && best != null && !best.isEmpty())
                ? best
                : "(no consensus)";
    }

    // -- write rank_files_smart.txt ----------------------------------------------
    private static void writeRankFile(List<FileEntry> entries, int totalEligible)
            throws IOException {
        try (PrintWriter pw = new PrintWriter(
                new BufferedWriter(new OutputStreamWriter(
                        new FileOutputStream(OUTPUT_FILE), StandardCharsets.UTF_8)))) {

            pw.println("============================================================");
            pw.println("  MESH FILE SMART RANK — " + new Date());
            pw.println("============================================================");
            pw.println();
            pw.println("  Scoring : number of distinct IPs hosting the file.");
            pw.println("  Key     : majority public_key across those IPs (one vote per IP).");
            pw.println("  Total eligible servers : " + totalEligible);
            pw.println();
            pw.printf("  %-4s  %-64s  %-6s  %s%n", "Rank", "File Hash", "IPs", "Public Key");
            pw.println("  " + "-".repeat(140));

            if (entries.isEmpty()) {
                pw.println("  (no files found)");
            } else {
                int rank = 1;
                for (FileEntry fe : entries) {
                    pw.printf("  %-4d  %-64s  %-6d  %s%n",
                            rank++, fe.hash, fe.distinctIPs, fe.majorityPublicKey);
                }
            }

            pw.println();
            pw.println("============================================================");
        }
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

    // -- derive the directory base URL -------------------------------------------
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
    //  File entry (one row in the output)
    // --------------------------------------------------------------------------
    static class FileEntry {
        final String hash;
        final int    distinctIPs;
        final String majorityPublicKey;

        FileEntry(String hash, int distinctIPs, String majorityPublicKey) {
            this.hash             = hash;
            this.distinctIPs      = distinctIPs;
            this.majorityPublicKey = majorityPublicKey;
        }
    }

    // --------------------------------------------------------------------------
    //  Per-server result holder
    // --------------------------------------------------------------------------
    static class ServerResult {
        final String url;
        final String baseUrl;
        boolean eligible = true;
        String  ipHash   = null;                                     // SHA-256 of resolved IP
        Map<String, String> filePublicKeys = new LinkedHashMap<>();  // fileHash -> public_key

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
                // -- resolve IP ---------------------------------------------------
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
                    System.out.println("[OK]    " + originalUrl + " — files.txt is empty");
                    return result;
                }

                // -- verify each file and fetch its public_key --------------------
                for (String filename : filenames) {
                    String expectedHash = stripExtension(filename).toLowerCase();
                    String algo         = detectAlgo(expectedHash);
                    if (algo == null) continue; // skip non-hash filenames

                    // integrity check
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
                                + " actual="   + actualHash.substring(0, 12)   + "...)");
                        result.eligible = false;
                        return result;
                    }

                    // fetch /info/<hash>.json for public_key
                    String publicKey = "";
                    String infoJson  = fetchText(baseUrl + "/info/" + expectedHash + ".json");
                    if (infoJson != null) {
                        publicKey = extractJsonField(infoJson, "public_key");
                    }

                    result.filePublicKeys.put(expectedHash, publicKey);
                }

                System.out.println("[OK]    " + originalUrl
                        + " — " + result.filePublicKeys.size() + " valid file(s)");

            } catch (Exception ex) {
                System.out.println("[ERROR] " + originalUrl + " — " + ex.getMessage());
                result.eligible = false;
            }

            return result;
        }

        // -- helpers --------------------------------------------------------------

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
                byte[] digest    = md.digest(data);
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
                return (bytes == null) ? null : new String(bytes, StandardCharsets.UTF_8);
            } catch (Exception e) { return null; }
        }

        private static byte[] fetchBytes(String rawUrl) {
            try {
                URL u = new URL(rawUrl);
                HttpURLConnection conn = (HttpURLConnection) u.openConnection();
                conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
                conn.setReadTimeout(READ_TIMEOUT_MS);
                conn.setRequestProperty("User-Agent", "MeshRankFileSmart/1.0");
                conn.setInstanceFollowRedirects(true);
                if (conn.getResponseCode() != 200) return null;
                try (InputStream is = conn.getInputStream()) { return readAllBytes(is); }
            } catch (Exception e) { return null; }
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
                        && json.charAt(end) != ']') end++;
                return json.substring(start, end).trim();
            }
        }
    }
}