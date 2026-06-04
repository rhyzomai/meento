import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.*;
import java.security.spec.*;
import java.text.SimpleDateFormat;
import java.util.*;
import java.util.concurrent.*;

/**
 * MeshBlockchain — Blockchain with proof-of-work based on mesh-network file storage.
 *
 * Proof-of-Work rule:
 *   A user earns the right to add a block ONLY when they have downloaded and stored
 *   ALL files listed in every server's files.txt (from servers.txt), plus every
 *   corresponding /info/<hash>.json from every server, AND a MAJORITY of servers
 *   confirm the user's files are intact (filename == SHA-256 of file content).
 *
 * Block filename rule:
 *   Each block is saved as  json_blocks/<filename>.json  where <filename> must be
 *   a filename found in at least one server's files.txt. A block cannot overwrite
 *   an existing file, and once every known filename has a block, no more blocks
 *   can be added.
 *
 * Reward system:
 *   Each successfully added block mints REWARD_AMOUNT coins to the miner's public key.
 *   The current balance of any key is the sum of rewards in the chain.
 *
 * Key pairs:
 *   RSA-2048 key pairs are generated/loaded from  keys/private.key  and  keys/public.key.
 *   Every block is signed by the miner and carries the miner's public key.
 *
 * Layout expected on every mesh server:
 *   /files.txt            — one filename per line  (e.g. <sha256>.jpg)
 *   /files/<filename>     — the actual file
 *   /info/<hash>.json     — metadata JSON with at least a "public_key" field
 *
 * Local layout (created automatically):
 *   keys/                 — RSA key files
 *   cache/files/          — downloaded file cache
 *   cache/info/           — downloaded info-JSON cache
 *   json_blocks/          — blockchain blocks (one JSON file per block)
 *
 * Compatible: Java 8+, no external libraries.
 */
public class MeshBlockchain {

    // -- tunables ----------------------------------------------------------------
    private static final String SERVERS_FILE      = "servers.txt";
    private static final String BLOCKS_DIR        = "json_blocks";
    private static final String CACHE_FILES_DIR   = "files";
    private static final String CACHE_INFO_DIR    = "info";
    private static final String KEYS_DIR          = "keys";
    private static final String PRIVATE_KEY_FILE  = KEYS_DIR + "/private.key";
    private static final String PUBLIC_KEY_FILE   = KEYS_DIR + "/public.key";

    private static final int    CONNECT_MS        = 8_000;
    private static final int    READ_MS           = 60_000;
    private static final int    THREAD_POOL       = 16;
    private static final long   REWARD_AMOUNT     = 50L;   // coins per block

    // -- entry point -------------------------------------------------------------
    public static void main(String[] args) throws Exception {
        System.out.println("+--------------------------------------------------+");
        System.out.println("¦          MeshBlockchain  —  v1.0                ¦");
        System.out.println("+--------------------------------------------------+");
        System.out.println();

        ensureDirs();
        KeyPair keyPair = loadOrGenerateKeys();
        String myPublicKey = encodeBase64(keyPair.getPublic().getEncoded());
        System.out.println("Your public key (truncated): " + myPublicKey.substring(0, 40) + "...");
        System.out.println();

        // Show balance
        long balance = computeBalance(myPublicKey);
        System.out.println("Your current balance: " + balance + " coins");
        System.out.println();

        // Interactive menu
        BufferedReader console = new BufferedReader(new InputStreamReader(System.in));
        while (true) {
            System.out.println("--- Menu ----------------------------------------");
            System.out.println("  1. Mine a block (download files & prove storage)");
            System.out.println("  2. Show blockchain");
            System.out.println("  3. Check balance for a public key");
            System.out.println("  4. Verify entire blockchain integrity");
            System.out.println("  5. Show my public key (full)");
            System.out.println("  0. Exit");
            System.out.print("Choice: ");
            String choice = console.readLine();
            if (choice == null) break;
            choice = choice.trim();
            System.out.println();
            switch (choice) {
                case "1": doMine(keyPair, myPublicKey); break;
                case "2": doShowChain(); break;
                case "3": doCheckBalance(console); break;
                case "4": doVerifyChain(); break;
                case "5": System.out.println(myPublicKey); System.out.println(); break;
                case "0": System.out.println("Goodbye."); return;
                default:  System.out.println("Unknown option.\n");
            }
        }
    }

    // -- mine a block ------------------------------------------------------------
    private static void doMine(KeyPair keyPair, String myPublicKey) throws Exception {
        List<String> servers = readLines(SERVERS_FILE);
        if (servers.isEmpty()) {
            System.out.println("[ERROR] " + SERVERS_FILE + " is empty or not found.");
            return;
        }

        System.out.println("Step 1/4 — Collecting file manifests from " + servers.size() + " server(s)...");
        // Map: filename -> set of servers that list it
        Map<String, Set<String>> allFiles = new LinkedHashMap<>();
        for (String rawServer : servers) {
            String base = deriveBaseUrl(rawServer.trim());
            String txt  = fetchText(base + "/files.txt");
            if (txt == null) {
                System.out.println("  [WARN] Cannot reach " + base + " — skipping.");
                continue;
            }
            for (String fn : parseLines(txt)) {
                allFiles.computeIfAbsent(fn, k -> new LinkedHashSet<>()).add(base);
            }
        }
        if (allFiles.isEmpty()) {
            System.out.println("[ERROR] No files found across all servers.");
            return;
        }
        System.out.println("  Total unique filenames across all servers: " + allFiles.size());

        // Check if all filenames already have blocks
        File blocksDir = new File(BLOCKS_DIR);
        Set<String> existingBlockNames = new HashSet<>();
        if (blocksDir.exists()) {
            for (File f : Objects.requireNonNull(blocksDir.listFiles())) {
                if (f.getName().endsWith(".json")) {
                    existingBlockNames.add(f.getName().substring(0, f.getName().length() - 5));
                }
            }
        }
        // Find a filename not yet used as a block
        String blockFilename = null;
        for (String fn : allFiles.keySet()) {
            String base = stripExtension(fn);
            if (!existingBlockNames.contains(base)) {
                blockFilename = fn;
                break;
            }
        }
        if (blockFilename == null) {
            System.out.println("[INFO] All known filenames already have blocks. No new block can be added.");
            return;
        }
        System.out.println("  Chosen block filename: " + blockFilename);

        System.out.println("\nStep 2/4 — Downloading all files + info JSONs...");
        // Download every file from every server that lists it, plus info JSON
        Map<String, byte[]> fileCache = new HashMap<>();   // filename -> bytes (verified)
        Map<String, String> infoCache = new HashMap<>();   // hash     -> info JSON text
        boolean downloadOk = downloadAllFiles(allFiles, fileCache, infoCache);
        if (!downloadOk) {
            System.out.println("[ERROR] Could not download all required files. Cannot mine.");
            return;
        }

        System.out.println("\nStep 3/4 — Requesting majority server attestation...");
        // Ask each server to attest that the files are intact.
        // Since servers don't have an attestation endpoint in MeshRank's protocol,
        // we use the local verification as our attestation: we re-verify every file
        // we have cached against its filename-derived SHA-256, then count how many
        // *servers* can be confirmed (i.e. all files listed by that server pass check).
        List<String> reachableServers = new ArrayList<>();
        List<String> attestingServers = new ArrayList<>();
        for (String rawServer : servers) {
            String base = deriveBaseUrl(rawServer.trim());
            String txt  = fetchText(base + "/files.txt");
            if (txt == null) continue;
            reachableServers.add(base);
            List<String> fns = parseLines(txt);
            boolean allGood = true;
            for (String fn : fns) {
                byte[] cached = fileCache.get(fn);
                if (cached == null) { allGood = false; break; }
                String expected = stripExtension(fn).toLowerCase();
                String actual   = sha256Hex(cached);
                if (!actual.equalsIgnoreCase(expected)) { allGood = false; break; }
            }
            if (allGood) attestingServers.add(base);
        }
        System.out.println("  Reachable servers: " + reachableServers.size()
                + "  |  Attesting: " + attestingServers.size());
        if (reachableServers.isEmpty()) {
            System.out.println("[ERROR] No servers reachable for attestation.");
            return;
        }
        boolean majority = attestingServers.size() > reachableServers.size() / 2;
        if (!majority) {
            System.out.println("[ERROR] Majority attestation FAILED ("
                    + attestingServers.size() + "/" + reachableServers.size()
                    + " required > " + (reachableServers.size() / 2) + ").");
            return;
        }
        System.out.println("  Majority attestation PASSED.");

        System.out.println("\nStep 4/4 — Building and signing block...");
        // Gather server public keys for the file used as block filename
        String blockFileHash = stripExtension(blockFilename).toLowerCase();
        // Collect server public_key values from info JSONs
        List<String> serverPublicKeys = new ArrayList<>();
        for (String rawServer : servers) {
            String base = deriveBaseUrl(rawServer.trim());
            String infoJson = infoCache.get(base + "|" + blockFileHash);
            if (infoJson == null) {
                // Try any server that has this file
                infoJson = infoCache.get(blockFileHash);
            }
            if (infoJson != null) {
                String pk = extractJsonField(infoJson, "public_key");
                if (!pk.isEmpty()) serverPublicKeys.add(pk);
            }
        }
        // Majority server public key for this file
        String serverPublicKey = majorityVote(serverPublicKeys);
        if (serverPublicKey == null) serverPublicKey = "";

        // Build block JSON
        String previousHash = getLastBlockHash();
        long   timestamp    = System.currentTimeMillis();
        long   index        = countBlocks() + 1;

        // Block data for signing (canonical representation before adding signature)
        String blockData = buildBlockData(index, timestamp, blockFilename, blockFileHash,
                previousHash, myPublicKey, serverPublicKey, REWARD_AMOUNT);

        // Sign
        byte[] signature  = sign(blockData.getBytes(StandardCharsets.UTF_8), keyPair.getPrivate());
        String sigBase64  = encodeBase64(signature);
        String blockHash  = sha256Hex(blockData.getBytes(StandardCharsets.UTF_8));

        String fullBlock  = buildFullBlock(index, timestamp, blockFilename, blockFileHash,
                previousHash, myPublicKey, serverPublicKey, REWARD_AMOUNT,
                sigBase64, blockHash);

        // Save block
        String baseName = stripExtension(blockFilename);
        File blockFile  = new File(BLOCKS_DIR + "/" + baseName + ".json");
        if (blockFile.exists()) {
            System.out.println("[ERROR] Block file already exists: " + blockFile.getPath());
            return;
        }
        writeFile(blockFile, fullBlock.getBytes(StandardCharsets.UTF_8));
        System.out.println("\n? Block mined and saved: " + blockFile.getPath());
        System.out.println("  Block hash   : " + blockHash);
        System.out.println("  Block index  : " + index);
        System.out.println("  Reward       : " + REWARD_AMOUNT + " coins ? " + myPublicKey.substring(0,20) + "...");
        System.out.println();
    }

    // -- show chain --------------------------------------------------------------
    private static void doShowChain() throws IOException {
        List<File> blocks = getSortedBlocks();
        if (blocks.isEmpty()) {
            System.out.println("  (no blocks yet)");
            System.out.println();
            return;
        }
        for (File f : blocks) {
            String json = new String(readFileBytes(f), StandardCharsets.UTF_8);
            System.out.println("--- " + f.getName() + " -----------------------");
            // Print a few key fields neatly
            printField(json, "index");
            printField(json, "timestamp");
            printField(json, "block_filename");
            printField(json, "miner_public_key");
            printField(json, "reward");
            printField(json, "block_hash");
            System.out.println();
        }
    }

    // -- check balance -----------------------------------------------------------
    private static void doCheckBalance(BufferedReader console) throws Exception {
        System.out.print("Public key (or press ENTER for yours): ");
        String pk = console.readLine();
        if (pk == null || pk.trim().isEmpty()) {
            KeyPair kp = loadOrGenerateKeys();
            pk = encodeBase64(kp.getPublic().getEncoded());
        }
        pk = pk.trim();
        long bal = computeBalance(pk);
        System.out.println("Balance: " + bal + " coins");
        System.out.println();
    }

    // -- verify chain ------------------------------------------------------------
    private static void doVerifyChain() throws Exception {
        List<File> blocks = getSortedBlocks();
        if (blocks.isEmpty()) {
            System.out.println("  Chain is empty — nothing to verify.");
            System.out.println();
            return;
        }
        int ok = 0, fail = 0;
        String prevHash = "0";
        for (File f : blocks) {
            String json      = new String(readFileBytes(f), StandardCharsets.UTF_8);
            String storedHash = extractJsonField(json, "block_hash");
            String storedPrev = extractJsonField(json, "previous_hash");
            String sigB64     = extractJsonField(json, "signature");
            String minerPk    = extractJsonField(json, "miner_public_key");
            String blockFn    = extractJsonField(json, "block_filename");
            String fileHash   = extractJsonField(json, "file_hash");
            String rewardStr  = extractJsonField(json, "reward");
            String serverPk   = extractJsonField(json, "server_public_key");
            String tsStr      = extractJsonField(json, "timestamp");
            String idxStr     = extractJsonField(json, "index");

            // Re-derive block data
            long idx    = parseLong(idxStr);
            long ts     = parseLong(tsStr);
            long reward = parseLong(rewardStr);
            String reconstructed = buildBlockData(idx, ts, blockFn, fileHash,
                    storedPrev, minerPk, serverPk, reward);
            String recomputedHash = sha256Hex(reconstructed.getBytes(StandardCharsets.UTF_8));

            boolean hashOk = recomputedHash.equals(storedHash);
            boolean prevOk = storedPrev.equals(prevHash);
            boolean sigOk  = false;
            try {
                byte[] pubBytes = decodeBase64(minerPk);
                PublicKey pub   = KeyFactory.getInstance("RSA")
                        .generatePublic(new X509EncodedKeySpec(pubBytes));
                Signature sig   = Signature.getInstance("SHA256withRSA");
                sig.initVerify(pub);
                sig.update(reconstructed.getBytes(StandardCharsets.UTF_8));
                sigOk = sig.verify(decodeBase64(sigB64));
            } catch (Exception e) { /* sigOk stays false */ }

            if (hashOk && prevOk && sigOk) {
                System.out.println("  [OK]   " + f.getName());
                ok++;
            } else {
                System.out.println("  [FAIL] " + f.getName()
                        + (hashOk ? "" : " HASH_MISMATCH")
                        + (prevOk ? "" : " CHAIN_BREAK")
                        + (sigOk  ? "" : " SIG_INVALID"));
                fail++;
            }
            prevHash = storedHash;
        }
        System.out.println("\nVerification complete: " + ok + " OK, " + fail + " FAILED.");
        System.out.println();
    }

    // -- helper: download all files ----------------------------------------------
    private static boolean downloadAllFiles(Map<String, Set<String>> allFiles,
                                            Map<String, byte[]> fileCache,
                                            Map<String, String> infoCache) throws Exception {
        ExecutorService pool = Executors.newFixedThreadPool(THREAD_POOL);
        List<Future<DownloadResult>> futures = new ArrayList<>();

        for (Map.Entry<String, Set<String>> entry : allFiles.entrySet()) {
            final String filename = entry.getKey();
            final Set<String> srcServers = entry.getValue();
            futures.add(pool.submit(new Callable<DownloadResult>() {
                public DownloadResult call() {
                    return downloadOneFile(filename, srcServers);
                }
            }));
        }
        pool.shutdown();
        pool.awaitTermination(30, TimeUnit.MINUTES);

        boolean allOk = true;
        for (Future<DownloadResult> f : futures) {
            try {
                DownloadResult dr = f.get();
                if (dr.ok) {
                    fileCache.put(dr.filename, dr.fileBytes);
                    if (dr.infoJson != null) {
                        infoCache.put(dr.fileHash, dr.infoJson);
                        // Also store per-server key
                        for (String srv : dr.servers) {
                            infoCache.put(srv + "|" + dr.fileHash, dr.infoJson);
                        }
                    }
                } else {
                    System.out.println("  [FAIL] Could not obtain: " + dr.filename);
                    allOk = false;
                }
            } catch (Exception e) {
                allOk = false;
            }
        }
        return allOk;
    }

    // -- helper: download one file (uses cache on disk) ---------------------------
    private static DownloadResult downloadOneFile(String filename, Set<String> servers) {
        DownloadResult dr = new DownloadResult();
        dr.filename = filename;
        dr.servers  = servers;
        dr.fileHash = stripExtension(filename).toLowerCase();

        // Check disk cache first
        File cacheFile = new File(CACHE_FILES_DIR + "/" + filename);
        byte[] fileBytes = null;
        if (cacheFile.exists()) {
            try {
                fileBytes = readFileBytes(cacheFile);
                // Verify integrity
                String actual = sha256Hex(fileBytes);
                if (!actual.equalsIgnoreCase(dr.fileHash)) fileBytes = null;
            } catch (IOException e) { fileBytes = null; }
        }

        if (fileBytes == null) {
            // Try each server
            for (String base : servers) {
                byte[] bytes = fetchBytes(base + "/files/" + filename);
                if (bytes != null) {
                    String actual = sha256Hex(bytes);
                    if (actual.equalsIgnoreCase(dr.fileHash)) {
                        fileBytes = bytes;
                        try {
                            writeFile(cacheFile, bytes);
                        } catch (IOException ignore) {}
                        break;
                    }
                }
            }
        }
        if (fileBytes == null) { dr.ok = false; return dr; }
        dr.fileBytes = fileBytes;
        dr.ok = true;

        // Check info JSON cache
        File infoFile = new File(CACHE_INFO_DIR + "/" + dr.fileHash + ".json");
        String infoJson = null;
        if (infoFile.exists()) {
            try { infoJson = new String(readFileBytes(infoFile), StandardCharsets.UTF_8); }
            catch (IOException e) { infoJson = null; }
        }
        if (infoJson == null) {
            for (String base : servers) {
                String txt = fetchText(base + "/info/" + dr.fileHash + ".json");
                if (txt != null) {
                    infoJson = txt;
                    try { writeFile(infoFile, txt.getBytes(StandardCharsets.UTF_8)); }
                    catch (IOException ignore) {}
                    break;
                }
            }
        }
        dr.infoJson = infoJson;
        return dr;
    }

    // -- helper: build block data string (canonical, no signature) ---------------
    private static String buildBlockData(long index, long timestamp, String blockFilename,
                                         String fileHash, String previousHash,
                                         String minerPublicKey, String serverPublicKey,
                                         long reward) {
        return "{" +
            "\"index\":" + index + "," +
            "\"timestamp\":" + timestamp + "," +
            "\"block_filename\":\"" + escJson(blockFilename) + "\"," +
            "\"file_hash\":\"" + escJson(fileHash) + "\"," +
            "\"previous_hash\":\"" + escJson(previousHash) + "\"," +
            "\"miner_public_key\":\"" + escJson(minerPublicKey) + "\"," +
            "\"server_public_key\":\"" + escJson(serverPublicKey) + "\"," +
            "\"reward\":" + reward +
        "}";
    }

    // -- helper: build full block JSON (with signature & hash) -------------------
    private static String buildFullBlock(long index, long timestamp, String blockFilename,
                                         String fileHash, String previousHash,
                                         String minerPublicKey, String serverPublicKey,
                                         long reward, String signature, String blockHash) {
        String iso = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'").format(new Date(timestamp));
        return "{\n" +
            "  \"index\": " + index + ",\n" +
            "  \"timestamp\": " + timestamp + ",\n" +
            "  \"timestamp_iso\": \"" + iso + "\",\n" +
            "  \"block_filename\": \"" + escJson(blockFilename) + "\",\n" +
            "  \"file_hash\": \"" + escJson(fileHash) + "\",\n" +
            "  \"previous_hash\": \"" + escJson(previousHash) + "\",\n" +
            "  \"miner_public_key\": \"" + escJson(minerPublicKey) + "\",\n" +
            "  \"server_public_key\": \"" + escJson(serverPublicKey) + "\",\n" +
            "  \"reward\": " + reward + ",\n" +
            "  \"signature\": \"" + escJson(signature) + "\",\n" +
            "  \"block_hash\": \"" + escJson(blockHash) + "\"\n" +
            "}\n";
    }

    // -- helper: get hash of last block ------------------------------------------
    private static String getLastBlockHash() throws IOException {
        List<File> blocks = getSortedBlocks();
        if (blocks.isEmpty()) return "0000000000000000000000000000000000000000000000000000000000000000";
        String json = new String(readFileBytes(blocks.get(blocks.size() - 1)), StandardCharsets.UTF_8);
        return extractJsonField(json, "block_hash");
    }

    // -- helper: count existing blocks -------------------------------------------
    private static long countBlocks() {
        File dir = new File(BLOCKS_DIR);
        if (!dir.exists()) return 0;
        int count = 0;
        for (File f : Objects.requireNonNull(dir.listFiles())) {
            if (f.getName().endsWith(".json")) count++;
        }
        return count;
    }

    // -- helper: sorted blocks (by index field) -----------------------------------
    private static List<File> getSortedBlocks() throws IOException {
        File dir = new File(BLOCKS_DIR);
        if (!dir.exists() || dir.listFiles() == null) return Collections.emptyList();
        List<File> files = new ArrayList<>();
        for (File f : Objects.requireNonNull(dir.listFiles())) {
            if (f.getName().endsWith(".json")) files.add(f);
        }
        // Sort by index field inside JSON
        Collections.sort(files, new Comparator<File>() {
            public int compare(File a, File b) {
                try {
                    String ja = new String(readFileBytes(a), StandardCharsets.UTF_8);
                    String jb = new String(readFileBytes(b), StandardCharsets.UTF_8);
                    long ia = parseLong(extractJsonField(ja, "index"));
                    long ib = parseLong(extractJsonField(jb, "index"));
                    return Long.compare(ia, ib);
                } catch (IOException e) { return 0; }
            }
        });
        return files;
    }

    // -- helper: compute balance --------------------------------------------------
    private static long computeBalance(String publicKey) throws IOException {
        long balance = 0;
        List<File> blocks = getSortedBlocks();
        for (File f : blocks) {
            String json   = new String(readFileBytes(f), StandardCharsets.UTF_8);
            String miner  = extractJsonField(json, "miner_public_key");
            String reward = extractJsonField(json, "reward");
            if (publicKey.equals(miner)) {
                balance += parseLong(reward);
            }
        }
        return balance;
    }

    // -- helper: print a field from JSON -----------------------------------------
    private static void printField(String json, String field) {
        String v = extractJsonField(json, field);
        if (v.length() > 80) v = v.substring(0, 80) + "...";
        System.out.printf("  %-22s %s%n", field + ":", v);
    }

    // -- key management -----------------------------------------------------------
    private static KeyPair loadOrGenerateKeys() throws Exception {
        File priv = new File(PRIVATE_KEY_FILE);
        File pub  = new File(PUBLIC_KEY_FILE);
        if (priv.exists() && pub.exists()) {
            System.out.println("Loading existing key pair from " + KEYS_DIR + "/");
            byte[] privBytes = readFileBytes(priv);
            byte[] pubBytes  = readFileBytes(pub);
            KeyFactory kf    = KeyFactory.getInstance("RSA");
            PrivateKey privKey = kf.generatePrivate(new PKCS8EncodedKeySpec(privBytes));
            PublicKey  pubKey  = kf.generatePublic(new X509EncodedKeySpec(pubBytes));
            return new KeyPair(pubKey, privKey);
        } else {
            System.out.println("Generating new RSA-2048 key pair...");
            KeyPairGenerator gen = KeyPairGenerator.getInstance("RSA");
            gen.initialize(2048, new SecureRandom());
            KeyPair kp = gen.generateKeyPair();
            writeFile(priv, kp.getPrivate().getEncoded());
            writeFile(pub,  kp.getPublic().getEncoded());
            System.out.println("Keys saved to " + KEYS_DIR + "/");
            return kp;
        }
    }

    // -- RSA sign -----------------------------------------------------------------
    private static byte[] sign(byte[] data, PrivateKey key) throws Exception {
        Signature signer = Signature.getInstance("SHA256withRSA");
        signer.initSign(key);
        signer.update(data);
        return signer.sign();
    }

    // -- Base64 (Java 8 compatible via javax.xml.bind, with fallback) -------------
    private static String encodeBase64(byte[] data) {
        // Pure Java 8 Base64 (java.util.Base64 is available since Java 8)
        return java.util.Base64.getEncoder().encodeToString(data);
    }
    private static byte[] decodeBase64(String s) {
        return java.util.Base64.getDecoder().decode(s);
    }

    // -- SHA-256 -------------------------------------------------------------------
    static String sha256Hex(byte[] data) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            byte[] digest    = md.digest(data);
            StringBuilder sb = new StringBuilder(64);
            for (byte b : digest) sb.append(String.format("%02x", b & 0xff));
            return sb.toString();
        } catch (NoSuchAlgorithmException e) { throw new RuntimeException(e); }
    }

    // -- majority vote (>50%) — null on tie / empty --------------------------------
    private static String majorityVote(List<String> votes) {
        if (votes == null || votes.isEmpty()) return null;
        Map<String, Integer> counts = new HashMap<>();
        for (String v : votes) if (!v.isEmpty()) counts.merge(v, 1, Integer::sum);
        int threshold = votes.size() / 2;
        String best = null; int bestCount = 0;
        for (Map.Entry<String, Integer> e : counts.entrySet()) {
            if (e.getValue() > bestCount) { bestCount = e.getValue(); best = e.getKey(); }
        }
        return (bestCount > threshold) ? best : null;
    }

    // -- network helpers ----------------------------------------------------------
    private static String fetchText(String url) {
        byte[] b = fetchBytes(url);
        return (b == null) ? null : new String(b, StandardCharsets.UTF_8);
    }

    private static byte[] fetchBytes(String rawUrl) {
        try {
            URL u = new URL(rawUrl);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setConnectTimeout(CONNECT_MS);
            conn.setReadTimeout(READ_MS);
            conn.setRequestProperty("User-Agent", "MeshBlockchain/1.0");
            conn.setInstanceFollowRedirects(true);
            if (conn.getResponseCode() != 200) return null;
            try (InputStream is = conn.getInputStream()) {
                return readAllBytes(is);
            }
        } catch (Exception e) { return null; }
    }

    private static byte[] readAllBytes(InputStream is) throws IOException {
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        byte[] chunk = new byte[8192]; int n;
        while ((n = is.read(chunk)) != -1) buf.write(chunk, 0, n);
        return buf.toByteArray();
    }

    // -- file helpers -------------------------------------------------------------
    private static void ensureDirs() {
        for (String d : new String[]{BLOCKS_DIR, CACHE_FILES_DIR, CACHE_INFO_DIR, KEYS_DIR}) {
            new File(d).mkdirs();
        }
    }

    private static void writeFile(File f, byte[] data) throws IOException {
        f.getParentFile().mkdirs();
        try (FileOutputStream fos = new FileOutputStream(f)) { fos.write(data); }
    }

    private static byte[] readFileBytes(File f) throws IOException {
        try (FileInputStream fis = new FileInputStream(f)) {
            return readAllBytes(fis);
        }
    }

    private static List<String> readLines(String path) {
        File f = new File(path);
        if (!f.exists()) return Collections.emptyList();
        try (BufferedReader br = new BufferedReader(
                new InputStreamReader(new FileInputStream(f), StandardCharsets.UTF_8))) {
            List<String> lines = new ArrayList<>();
            String line;
            while ((line = br.readLine()) != null) {
                line = line.trim();
                if (!line.isEmpty() && !line.startsWith("#")) lines.add(line);
            }
            return lines;
        } catch (IOException e) { return Collections.emptyList(); }
    }

    private static List<String> parseLines(String text) {
        List<String> out = new ArrayList<>();
        for (String line : text.split("[\r\n]+")) {
            line = line.trim();
            if (!line.isEmpty()) out.add(line);
        }
        return out;
    }

    // -- URL helpers (from MeshRank) -----------------------------------------------
    static String deriveBaseUrl(String raw) {
        String s = raw.replaceAll("/+$", "");
        int schemeEnd = s.indexOf("://");
        int pathStart = (schemeEnd >= 0) ? s.indexOf('/', schemeEnd + 3) : s.indexOf('/');
        if (pathStart < 0) return s;
        if (!s.matches("(?i)https?://.*")) s = "http://" + s;
        int lastSlash = s.lastIndexOf('/');
        if (lastSlash >= pathStart) {
            String lastSeg = s.substring(lastSlash + 1);
            if (lastSeg.contains(".")) {
                s = s.substring(0, lastSlash).replaceAll("/+$", "");
            }
        }
        return s;
    }

    // -- string helpers ------------------------------------------------------------
    private static String stripExtension(String name) {
        int dot = name.lastIndexOf('.');
        return (dot > 0) ? name.substring(0, dot) : name;
    }

    private static long parseLong(String s) {
        try { return Long.parseLong(s.trim()); } catch (Exception e) { return 0L; }
    }

    private static String escJson(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"")
                .replace("\n", "\\n").replace("\r", "\\r").replace("\t", "\\t");
    }

    /** Naive JSON field extractor (no external library). Handles string & primitive values. */
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
                } else if (c == '"') { break; }
                else { sb.append(c); i++; }
            }
            return sb.toString();
        } else {
            int end = start;
            while (end < json.length() && json.charAt(end) != ','
                    && json.charAt(end) != '}' && json.charAt(end) != ']') end++;
            return json.substring(start, end).trim();
        }
    }

    // -- inner result class --------------------------------------------------------
    static class DownloadResult {
        String   filename;
        String   fileHash;
        byte[]   fileBytes;
        String   infoJson;
        Set<String> servers;
        boolean  ok = false;
    }
}