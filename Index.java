import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.MessageDigest;
import java.time.OffsetDateTime;
import java.time.format.DateTimeFormatter;
import java.util.*;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.Executors;
import com.sun.net.httpserver.*;

public class Index {

    // --- Configuration ---
    private static final String DIR_FILES = "files";
    private static final String DIR_INFO = "info";
    private static final String FILE_INDEX = "files.txt";
    private static final String FILE_DATA_JSON = "data.json";
    private static final String FILE_SERVERS = "servers.txt";
    private static final String FILE_PUB_KEY = "public_key.txt";
    private static final String FILE_NODE_INFO = "node_info.txt";
    
    private static final long MAX_FILE_SIZE = 1073741824L; // 1 GB
    private static final int MAX_TEXT_LEN = 512;
    private static final List<String> BLOCKED_EXT = Arrays.asList("php", "php3", "php4", "php5", "php7", "phtml", "phar", "jsp", "class", "jar");
    private static final int PORT = 8080;

    public static void main(String[] args) throws Exception {
        bootstrap();
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", new MainHandler());
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("Mesh Node (Java 8) started on port " + PORT);
    }

    private static void bootstrap() throws IOException {
        Files.createDirectories(Paths.get(DIR_FILES));
        Files.createDirectories(Paths.get(DIR_INFO));
        File index = new File(FILE_INDEX);
        if (!index.exists()) index.createNewFile();
    }

    // --- Main HTTP Handler ---
    static class MainHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            String method = exchange.getRequestMethod();
            String path = exchange.getRequestURI().getPath();
            String query = exchange.getRequestURI().getQuery();

            try {
                // 1. Status JSON
                if ("GET".equals(method) && query != null && query.contains("node_status=1")) {
                    handleStatusRequest(exchange);
                    return;
                }

                // 2. Serve HTML
                if ("GET".equals(method) && ("/".equals(path) || "/index.php".equals(path) || path.isEmpty())) {
                    sendHtml(exchange);
                    return;
                }

                // 3. Serve Files
                if ("GET".equals(method) && path.startsWith("/files/")) {
                    serveFile(exchange, path.substring(7));
                    return;
                }

                // 4. Handle Upload
                if ("POST".equals(method)) {
                    handleUpload(exchange);
                    return;
                }

                sendError(exchange, 404, "Not Found");
            } catch (Exception e) {
                e.printStackTrace();
                sendError(exchange, 500, "Internal Server Error");
            }
        }
    }

    // --- Core Logic ---
    private static void handleStatusRequest(HttpExchange exchange) throws IOException {
        boolean pk = new File(FILE_PUB_KEY).exists() && new File(FILE_PUB_KEY).length() > 0;
        boolean ni = new File(FILE_NODE_INFO).exists() && new File(FILE_NODE_INFO).length() > 0;
        int peers = loadServers().size();
        String json = String.format("{\"public_key\":%b,\"node_info\":%b,\"peers\":%d}", pk, ni, peers);
        sendJsonResponse(exchange, 200, json);
    }

    private static void serveFile(HttpExchange exchange, String filename) throws IOException {
        File file = new File(DIR_FILES, filename);
        if (!file.exists() || file.isDirectory()) {
            sendError(exchange, 404, "File not found");
            return;
        }
        exchange.getResponseHeaders().set("Content-Type", "application/octet-stream");
        exchange.sendResponseHeaders(200, file.length());
        try (OutputStream os = exchange.getResponseBody()) {
            Files.copy(file.toPath(), os);
        }
    }

    private static void handleUpload(HttpExchange exchange) throws Exception {
        MultipartUpload upload = MultipartUpload.parse(exchange);

        if (upload.tempFile == null) {
            sendJsonResponse(exchange, 400, "{\"ok\":false,\"error\":\"No file uploaded.\"}");
            return;
        }

        if (upload.tempFile.length() > MAX_FILE_SIZE) {
            upload.tempFile.delete();
            sendJsonResponse(exchange, 400, "{\"ok\":false,\"error\":\"File exceeds the 1GB size limit.\"}");
            return;
        }

        String ext = safeExt(upload.filename);
        if (BLOCKED_EXT.contains(ext)) {
            upload.tempFile.delete();
            sendJsonResponse(exchange, 400, "{\"ok\":false,\"error\":\"Executable files are strictly blocked.\"}");
            return;
        }

        String hash = getHash(upload.tempFile);
        String baseName = ext.isEmpty() ? hash : hash + "." + ext;
        File destFile = new File(DIR_FILES, baseName);
        File destInfo = new File(DIR_INFO, hash + ".json");
        boolean existed = destFile.exists();

        if (!existed) {
            Files.move(upload.tempFile.toPath(), destFile.toPath(), StandardCopyOption.REPLACE_EXISTING);
            appendIndex(baseName);
        } else {
            upload.tempFile.delete();
        }

        String serverPubKey = sanitize(readOptionalFile(FILE_PUB_KEY));
        String nodeInfo = upload.nodeInfo.isEmpty() ? sanitize(readOptionalFile(FILE_NODE_INFO)) : sanitize(upload.nodeInfo);
        
        String metaJson = String.format("{\n" +
                "  \"filename\": \"%s\",\n  \"size\": %d,\n  \"extension\": \"%s\",\n" +
                "  \"public_key\": \"%s\",\n  \"server_public_key\": \"%s\",\n" +
                "  \"node_info\": \"%s\",\n  \"description\": \"%s\",\n  \"date\": \"%s\"\n}",
                escapeJson(sanitize(upload.filename)), destFile.length(), escapeJson(ext),
                escapeJson(sanitize(upload.publicKey)), escapeJson(serverPubKey),
                escapeJson(nodeInfo), escapeJson(sanitize(upload.description)),
                OffsetDateTime.now().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME));

        if (!destInfo.exists()) {
            Files.write(destInfo.toPath(), metaJson.getBytes(StandardCharsets.UTF_8));
        }

        if (!existed) {
            appendDataJson(hash, metaJson);
        }

        boolean isPeerPush = "1".equals(upload.peerPush);
        if (isPeerPush) {
            sendJsonResponse(exchange, 200, String.format("{\"ok\":true,\"hash\":\"%s\",\"filename\":\"%s\",\"existed\":%b}", hash, baseName, existed));
            return;
        }

        List<String> servers = loadServers();
        String res = String.format("{\"ok\":true,\"hash\":\"%s\",\"filename\":\"%s\",\"existed\":%b,\"peers_total\":%d,\"peers\":[]}", 
                hash, baseName, existed, servers.size());
        
        sendJsonResponse(exchange, 200, res);

        if (!existed) {
            CompletableFuture.runAsync(() -> {
                for (String server : servers) {
                    pushToServer(server, destFile, upload.filename, upload.description, upload.publicKey, nodeInfo);
                }
            });
        }
    }

    // --- Peer Push Logic ---
    private static void pushToServer(String serverStr, File file, String filename, String desc, String pubKey, String nodeInfo) {
        try {
            String boundary = "----MeshBoundary" + UUID.randomUUID().toString().replace("-", "");
            URL url = new URL(serverStr.endsWith("/") ? serverStr + "index.php" : serverStr + "/index.php");
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setDoOutput(true);
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
            conn.setRequestProperty("X-Mesh-Node", "1");
            conn.setConnectTimeout(20000);
            conn.setReadTimeout(20000);

            try (OutputStream out = conn.getOutputStream();
                 PrintWriter writer = new PrintWriter(new OutputStreamWriter(out, StandardCharsets.UTF_8), true)) {

                writeField(writer, boundary, "description", desc);
                writeField(writer, boundary, "public_key", pubKey);
                writeField(writer, boundary, "node_info", nodeInfo);
                writeField(writer, boundary, "peer_push", "1");

                writer.append("--").append(boundary).append("\r\n");
                writer.append("Content-Disposition: form-data; name=\"file\"; filename=\"").append(escapeJson(filename)).append("\"\r\n");
                writer.append("Content-Type: application/octet-stream\r\n\r\n").flush();

                Files.copy(file.toPath(), out);
                out.flush();
                writer.append("\r\n--").append(boundary).append("--\r\n").flush();
            }
            conn.getResponseCode();
        } catch (Exception ignored) { }
    }

    private static void writeField(PrintWriter writer, String boundary, String name, String val) {
        if (val == null || val.isEmpty()) return;
        writer.append("--").append(boundary).append("\r\n");
        writer.append("Content-Disposition: form-data; name=\"").append(name).append("\"\r\n\r\n");
        writer.append(val).append("\r\n");
    }

    // --- Utilities ---
    private static void sendJsonResponse(HttpExchange exchange, int code, String json) throws IOException {
        byte[] bytes = json.getBytes(StandardCharsets.UTF_8);
        exchange.getResponseHeaders().set("Content-Type", "application/json; charset=UTF-8");
        exchange.sendResponseHeaders(code, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) { os.write(bytes); }
    }

    private static void sendHtml(HttpExchange exchange) throws IOException {
        byte[] bytes = HTML_TEMPLATE.getBytes(StandardCharsets.UTF_8);
        exchange.getResponseHeaders().set("Content-Type", "text/html; charset=UTF-8");
        exchange.sendResponseHeaders(200, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) { os.write(bytes); }
    }

    private static void sendError(HttpExchange exchange, int code, String msg) throws IOException {
        String json = "{\"ok\":false,\"error\":\"" + msg + "\"}";
        sendJsonResponse(exchange, code, json);
    }

    private static synchronized void appendIndex(String entry) throws IOException {
        File f = new File(FILE_INDEX);
        List<String> lines = f.exists() ? Files.readAllLines(f.toPath()) : new ArrayList<>();
        if (!lines.contains(entry)) {
            Files.write(f.toPath(), (entry + "\n").getBytes(StandardCharsets.UTF_8), StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        }
    }

    private static synchronized void appendDataJson(String hash, String metaJson) throws IOException {
        File dataFile = new File(FILE_DATA_JSON);
        String data = dataFile.exists() ? new String(Files.readAllBytes(dataFile.toPath()), StandardCharsets.UTF_8).trim() : "[]";
        if (data.isEmpty()) data = "[]";

        String entry = "{\n  \"hash\": \"" + hash + "\",\n" + metaJson.substring(1);
        String indented = entry.replaceAll("(?m)^", "    ");

        int lastBracket = data.lastIndexOf(']');
        if (lastBracket != -1) {
            String prefix = data.substring(0, lastBracket).trim();
            String newContent = prefix.equals("[") ? "[\n" + indented + "\n]" : prefix + ",\n" + indented + "\n]";
            Files.write(dataFile.toPath(), newContent.getBytes(StandardCharsets.UTF_8));
        }
    }

    private static List<String> loadServers() {
        File f = new File(FILE_SERVERS);
        List<String> list = new ArrayList<>();
        if (!f.exists()) return list;
        try {
            for (String line : Files.readAllLines(f.toPath())) {
                line = line.trim();
                if (!line.isEmpty()) {
                    if (!line.toLowerCase().startsWith("http")) line = "http://" + line;
                    list.add(line.replaceAll("/+$", ""));
                }
            }
        } catch (IOException ignored) {}
        return new ArrayList<>(new HashSet<>(list));
    }

    private static String getHash(File file) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        try (InputStream is = new FileInputStream(file)) {
            byte[] buf = new byte[8192];
            int read;
            while ((read = is.read(buf)) != -1) md.update(buf, 0, read);
        }
        StringBuilder sb = new StringBuilder();
        for (byte b : md.digest()) sb.append(String.format("%02x", b));
        return sb.toString();
    }

    private static String safeExt(String filename) {
        int dot = filename.lastIndexOf('.');
        if (dot == -1) return "";
        return filename.substring(dot + 1).toLowerCase().replaceAll("[^a-z0-9]", "");
    }

    private static String sanitize(String input) {
        if (input == null) return "";
        String t = input.trim();
        return t.length() > MAX_TEXT_LEN ? t.substring(0, MAX_TEXT_LEN) : t;
    }

    private static String escapeJson(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n").replace("\r", "");
    }

    private static String readOptionalFile(String path) {
        File f = new File(path);
        if (!f.exists()) return "";
        try { return new String(Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8).trim(); } 
        catch (IOException e) { return ""; }
    }

    // --- Manual stream parser for large multipart uploads ---
    static class MultipartUpload {
        String filename = "";
        String description = "";
        String publicKey = "";
        String nodeInfo = "";
        String peerPush = "0";
        File tempFile = null;

        static MultipartUpload parse(HttpExchange exchange) throws Exception {
            MultipartUpload req = new MultipartUpload();
            String cType = exchange.getRequestHeaders().getFirst("Content-Type");
            if (cType == null || !cType.contains("boundary=")) return req;

            String bStr = cType.split("boundary=")[1];
            byte[] bndBody = ("\r\n--" + bStr).getBytes(StandardCharsets.ISO_8859_1);
            byte[] bndStart = ("--" + bStr).getBytes(StandardCharsets.ISO_8859_1);

            InputStream in = new BufferedInputStream(exchange.getRequestBody());
            skipUntil(in, bndStart);
            readLine(in);

            while (true) {
                String disposition = null;
                while (true) {
                    String line = readLine(in);
                    if (line == null || line.equals("--")) return req;
                    if (line.isEmpty()) break;
                    if (line.toLowerCase().startsWith("content-disposition:")) disposition = line;
                }
                if (disposition == null) break;

                String name = extractParam(disposition, "name");
                String fname = extractParam(disposition, "filename");

                if (fname != null) {
                    req.filename = fname;
                    req.tempFile = File.createTempFile("mesh_", ".tmp");
                    try (OutputStream out = new BufferedOutputStream(new FileOutputStream(req.tempFile))) {
                        copyUntil(in, out, bndBody);
                    }
                } else {
                    ByteArrayOutputStream out = new ByteArrayOutputStream();
                    copyUntil(in, out, bndBody);
                    String val = new String(out.toByteArray(), StandardCharsets.UTF_8);
                    if ("description".equals(name)) req.description = val;
                    if ("public_key".equals(name)) req.publicKey = val;
                    if ("node_info".equals(name)) req.nodeInfo = val;
                    if ("peer_push".equals(name)) req.peerPush = val;
                }
                readLine(in);
            }
            return req;
        }

        private static String extractParam(String header, String param) {
            String match = param + "=\"";
            int start = header.indexOf(match);
            if (start == -1) return null;
            start += match.length();
            int end = header.indexOf("\"", start);
            return end == -1 ? null : header.substring(start, end);
        }

        private static void skipUntil(InputStream in, byte[] sequence) throws IOException {
             copyUntil(in, new ByteArrayOutputStream(), sequence);
        }

        private static String readLine(InputStream in) throws IOException {
            ByteArrayOutputStream bos = new ByteArrayOutputStream();
            int b;
            while ((b = in.read()) != -1) {
                if (b == '\r') {
                    int next = in.read();
                    if (next == '\n') return new String(bos.toByteArray(), StandardCharsets.ISO_8859_1);
                    bos.write(b);
                    if (next != -1) bos.write(next);
                } else {
                    bos.write(b);
                }
            }
            return bos.size() > 0 ? new String(bos.toByteArray(), StandardCharsets.ISO_8859_1) : null;
        }

        private static void copyUntil(InputStream in, OutputStream out, byte[] boundary) throws IOException {
            int bLen = boundary.length;
            int[] b = new int[bLen];
            int bIdx = 0;

            for (int i = 0; i < bLen; i++) {
                int val = in.read();
                if (val == -1) {
                    for (int j = 0; j < i; j++) out.write(b[j]);
                    return;
                }
                b[i] = val;
            }

            while (true) {
                boolean match = true;
                for (int i = 0; i < bLen; i++) {
                    if ((byte) b[(bIdx + i) % bLen] != boundary[i]) { match = false; break; }
                }
                if (match) return;
                out.write(b[bIdx]);
                int next = in.read();
                if (next == -1) break;
                b[bIdx] = next;
                bIdx = (bIdx + 1) % bLen;
            }
            for (int i = 0; i < bLen; i++) out.write(b[(bIdx + i) % bLen]);
        }
    }

    // --- HTML Frontend Template ---
    private static final String HTML_TEMPLATE = "<!DOCTYPE html>\n" +
            "<html lang=\"en\">\n" +
            "<head>\n" +
            "<meta charset=\"UTF-8\">\n" +
            "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n" +
            "<meta name=\"description\" content=\"Mesh Node — distributed file replication\">\n" +
            "<meta name=\"theme-color\" content=\"#4f46e5\">\n" +
            "<title>Mesh Node — Distributed File Replication</title>\n" +
            "<style>\n" +
            ":root {\n" +
            "  --bg:              #f6f7fb;\n" +
            "  --bg-elev:         #ffffff;\n" +
            "  --bg-subtle:       #f1f2f6;\n" +
            "  --border:          #e5e7ec;\n" +
            "  --border-strong:   #d2d6dd;\n" +
            "  --text:            #0a0c10;\n" +
            "  --text-2:          #4a5060;\n" +
            "  --text-3:          #8b92a3;\n" +
            "  --accent:          #4f46e5;\n" +
            "  --accent-2:        #7c3aed;\n" +
            "  --accent-soft:     #eef0ff;\n" +
            "  --accent-hover:    #4338ca;\n" +
            "  --success:         #059669;\n" +
            "  --success-soft:    #ecfdf5;\n" +
            "  --success-border:  #a7f3d0;\n" +
            "  --danger:          #dc2626;\n" +
            "  --danger-soft:     #fef2f2;\n" +
            "  --danger-border:   #fecaca;\n" +
            "  --warning:         #d97706;\n" +
            "  --radius-sm:       6px;\n" +
            "  --radius:          10px;\n" +
            "  --radius-lg:       14px;\n" +
            "  --shadow-xs:       0 1px 2px rgba(15,17,23,.04);\n" +
            "  --shadow-sm:       0 1px 3px rgba(15,17,23,.05), 0 1px 2px rgba(15,17,23,.03);\n" +
            "  --shadow:          0 4px 14px rgba(15,17,23,.06), 0 1px 3px rgba(15,17,23,.04);\n" +
            "  --font:            'Inter', -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif;\n" +
            "  --mono:            'JetBrains Mono', ui-monospace, \"SF Mono\", monospace;\n" +
            "}\n" +
            "*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }\n" +
            "html, body { min-height: 100%; }\n" +
            "body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 14px; line-height: 1.5; display: flex; flex-direction: column; align-items: center; padding: 32px 24px 48px; }\n" +
            ".shell { width: 100%; max-width: 760px; }\n" +
            "header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }\n" +
            ".brand { display: flex; align-items: center; gap: 12px; }\n" +
            ".brand-mark { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 15px; }\n" +
            ".brand-text h1 { font-size: 15px; font-weight: 600; } .brand-text p { font-size: 12px; color: var(--text-3); }\n" +
            ".head-nav { display: flex; gap: 2px; flex-wrap: wrap; }\n" +
            ".head-nav a { font-size: 12.5px; color: var(--text-2); text-decoration: none; padding: 6px 10px; border-radius: var(--radius-sm); font-weight: 500; }\n" +
            ".head-nav a:hover { background: var(--bg-subtle); color: var(--text); }\n" +
            ".head-nav a.active { background: var(--accent-soft); color: var(--accent); }\n" +
            ".status-bar { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }\n" +
            ".sb-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-xs); }\n" +
            ".nb-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); }\n" +
            ".nb-dot.ok { background: var(--success); box-shadow: 0 0 0 3px rgba(5,150,105,.12); }\n" +
            ".nb-dot.blue { background: var(--accent); box-shadow: 0 0 0 3px rgba(79,70,229,.15); }\n" +
            ".sb-label { font-size: 10.5px; color: var(--text-3); text-transform: uppercase; font-weight: 600; }\n" +
            ".sb-value { font-size: 13px; color: var(--text); font-weight: 600; margin-top: 1px; }\n" +
            ".drop { background: var(--bg-elev); border: 1.5px dashed var(--border-strong); border-radius: var(--radius-lg); padding: 48px 24px; text-align: center; cursor: pointer; overflow: hidden; }\n" +
            ".drop:hover, .drop.over { border-color: var(--accent); }\n" +
            ".drop-icon { width: 56px; height: 56px; margin: 0 auto 16px; background: var(--accent-soft); color: var(--accent); border-radius: 14px; display: grid; place-items: center; }\n" +
            ".drop-icon svg { width: 26px; height: 26px; }\n" +
            ".drop-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; }\n" +
            ".drop-sub { font-size: 13px; color: var(--text-2); }\n" +
            "#panel { display: none; margin-top: 16px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: var(--radius-lg); }\n" +
            ".panel-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }\n" +
            ".file-chip { width: 40px; height: 40px; background: var(--accent-soft); color: var(--accent); border-radius: 9px; display: grid; place-items: center; }\n" +
            ".file-chip svg { width: 20px; height: 20px; }\n" +
            "#f-name { flex: 1; font-weight: 600; overflow: hidden; text-overflow: ellipsis; }\n" +
            ".x-btn { background: transparent; border: 0; cursor: pointer; color: var(--text-3); padding: 6px; }\n" +
            ".x-btn svg { width: 16px; height: 16px; }\n" +
            ".panel-meta { padding: 10px 16px; background: var(--bg-subtle); border-bottom: 1px solid var(--border); display: flex; gap: 8px; }\n" +
            ".input-inline { flex: 1; padding: 6px 12px; border: 1px solid var(--border); border-radius: 999px; font-size: 12px; outline: none; }\n" +
            ".mpill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--bg-elev); }\n" +
            ".mpill.has { background: var(--success-soft); color: var(--success); }\n" +
            ".mpill svg { width: 12px; height: 12px; }\n" +
            ".panel-body { padding: 16px; }\n" +
            ".field-label { display: block; font-size: 11px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }\n" +
            "textarea#comment-box { width: 100%; min-height: 96px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; outline: none; }\n" +
            ".panel-foot { padding: 14px 16px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; background: var(--bg-subtle); }\n" +
            ".btn-send { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--accent); color: #fff; border: 0; border-radius: 8px; cursor: pointer; }\n" +
            ".btn-send.busy { opacity: .65; pointer-events: none; }\n" +
            ".btn-send svg { width: 14px; height: 14px; stroke: currentColor; }\n" +
            "#prog-wrap { height: 4px; background: var(--border); border-radius: 999px; display: none; margin-top: 16px; }\n" +
            "#prog-bar { height: 100%; width: 0%; background: var(--accent); transition: width .15s; }\n" +
            "#status { display: none; margin-top: 16px; padding: 12px 16px; border-radius: var(--radius); font-size: 13px; }\n" +
            "#status.ok { background: var(--success-soft); color: var(--success); border: 1px solid var(--success-border); }\n" +
            "#status.fail { background: var(--danger-soft); color: var(--danger); border: 1px solid var(--danger-border); }\n" +
            ".file-link { color: var(--accent); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; }\n" +
            "footer { margin-top: 32px; font-size: 12px; color: var(--text-3); text-align: center; }\n" +
            "</style>\n" +
            "</head>\n" +
            "<body>\n" +
            "<div class=\"shell\">\n" +
            "<header>\n" +
            "  <div class=\"brand\">\n" +
            "    <div class=\"brand-mark\">M</div>\n" +
            "    <div class=\"brand-text\"><h1>Mesh Node</h1><p>Distributed file replication</p></div>\n" +
            "  </div>\n" +
            "  <nav class=\"head-nav\"><a href=\"/\" class=\"active\">Upload</a></nav>\n" +
            "</header>\n" +
            "<div class=\"status-bar\">\n" +
            "  <div class=\"sb-card\"><span class=\"nb-dot blue\"></span><div><div class=\"sb-label\">Peers</div><div class=\"sb-value\" id=\"nb-peers\">…</div></div></div>\n" +
            "  <div class=\"sb-card\"><span class=\"nb-dot\" id=\"nb-pk-dot\"></span><div><div class=\"sb-label\">Public Key</div><div class=\"sb-value\" id=\"nb-pk\">…</div></div></div>\n" +
            "  <div class=\"sb-card\"><span class=\"nb-dot\" id=\"nb-ni-dot\"></span><div><div class=\"sb-label\">Node Info</div><div class=\"sb-value\" id=\"nb-ni\">…</div></div></div>\n" +
            "</div>\n" +
            "<div id=\"drop-zone\" class=\"drop\" tabindex=\"0\">\n" +
            "  <div class=\"drop-icon\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\"><path d=\"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4\"/><polyline points=\"17 8 12 3 7 8\"/><line x1=\"12\" y1=\"3\" x2=\"12\" y2=\"15\"/></svg></div>\n" +
            "  <p class=\"drop-title\">Drop a file or click to select</p>\n" +
            "  <p class=\"drop-sub\"><b>1 GB</b> maximum · Replicated to peers</p>\n" +
            "</div>\n" +
            "<input type=\"file\" id=\"file-input\" style=\"display:none\">\n" +
            "<div id=\"panel\">\n" +
            "  <div class=\"panel-head\">\n" +
            "    <div class=\"file-chip\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg></div>\n" +
            "    <span id=\"f-name\">—</span> <span id=\"f-size\"></span>\n" +
            "    <button class=\"x-btn\" id=\"x-btn\"><svg viewBox=\"0 0 24 24\" stroke=\"currentColor\"><line x1=\"18\" y1=\"6\" x2=\"6\" y2=\"18\"/><line x1=\"6\" y1=\"6\" x2=\"18\" y2=\"18\"/></svg></button>\n" +
            "  </div>\n" +
            "  <div class=\"panel-meta\">\n" +
            "    <input type=\"text\" class=\"input-inline\" id=\"pk-inline\" maxlength=\"512\" placeholder=\"Your public key (optional)…\">\n" +
            "    <div class=\"mpill\" id=\"mpill-ni\">node_info</div>\n" +
            "  </div>\n" +
            "  <div class=\"panel-body\">\n" +
            "    <label class=\"field-label\">Description</label>\n" +
            "    <textarea id=\"comment-box\" placeholder=\"Add an optional description...\"></textarea>\n" +
            "  </div>\n" +
            "  <div class=\"panel-foot\">\n" +
            "    <span>Sending to <b id=\"peer-count\">…</b> peer(s)</span>\n" +
            "    <button class=\"btn-send\" id=\"send-btn\">Send to network</button>\n" +
            "  </div>\n" +
            "</div>\n" +
            "<div id=\"prog-wrap\"><div id=\"prog-bar\"></div></div>\n" +
            "<div id=\"status\"></div>\n" +
            "</div>\n" +
            "<footer><p>Mesh Node · Java 8 Port · Object Oriented · 1 GB limit</p></footer>\n" +
            "<script>\n" +
            "const dz = document.getElementById('drop-zone'), fi = document.getElementById('file-input');\n" +
            "const panel = document.getElementById('panel'), fName = document.getElementById('f-name');\n" +
            "const fSize = document.getElementById('f-size'), xBtn = document.getElementById('x-btn');\n" +
            "const commentB = document.getElementById('comment-box'), sendBtn = document.getElementById('send-btn');\n" +
            "const peerCnt = document.getElementById('peer-count'), status = document.getElementById('status');\n" +
            "const progWrap = document.getElementById('prog-wrap'), progBar = document.getElementById('prog-bar');\n" +
            "const pkInline = document.getElementById('pk-inline');\n" +
            "let file = null;\n" +
            "fetch('/?node_status=1').then(r=>r.json()).then(d=>{\n" +
            "  document.getElementById('nb-peers').textContent = d.peers; peerCnt.textContent = d.peers;\n" +
            "  if(d.public_key) document.getElementById('nb-pk-dot').classList.add('ok');\n" +
            "  if(d.node_info) { document.getElementById('nb-ni-dot').classList.add('ok'); document.getElementById('mpill-ni').classList.add('has'); }\n" +
            "  document.getElementById('nb-pk').textContent = d.public_key?'Found':'Missing';\n" +
            "  document.getElementById('nb-ni').textContent = d.node_info?'Found':'Missing';\n" +
            "});\n" +
            "function fmt(b){ return b<1048576?(b/1024).toFixed(1)+' KB':(b/1048576).toFixed(2)+' MB'; }\n" +
            "function setFile(f){ if(f.size>1073741824){ show('fail','File exceeds 1GB.'); return; } file=f; fName.textContent=f.name; fSize.textContent=fmt(f.size); panel.style.display='block'; hide(); }\n" +
            "function clear(){ file=null; fi.value=''; panel.style.display='none'; }\n" +
            "dz.onclick = () => fi.click();\n" +
            "fi.onchange = () => { if(fi.files[0]) setFile(fi.files[0]); };\n" +
            "xBtn.onclick = clear;\n" +
            "dz.ondragover = e => { e.preventDefault(); dz.classList.add('over'); };\n" +
            "dz.ondragleave = e => { e.preventDefault(); dz.classList.remove('over'); };\n" +
            "dz.ondrop = e => { e.preventDefault(); dz.classList.remove('over'); if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); };\n" +
            "function show(t,h){ status.className=t; status.innerHTML=h; status.style.display='block'; }\n" +
            "function hide(){ status.style.display='none'; }\n" +
            "sendBtn.onclick = () => {\n" +
            "  if(!file) return;\n" +
            "  const fd = new FormData(); fd.append('file', file); fd.append('description', commentB.value); fd.append('public_key', pkInline.value);\n" +
            "  const xhr = new XMLHttpRequest(); sendBtn.classList.add('busy'); sendBtn.textContent='Transmitting…';\n" +
            "  progWrap.style.display='block'; progBar.style.width='0%'; hide();\n" +
            "  xhr.upload.onprogress = e => { if(e.lengthComputable) progBar.style.width = Math.round(e.loaded/e.total*100)+'%'; };\n" +
            "  xhr.onload = () => {\n" +
            "    sendBtn.classList.remove('busy'); sendBtn.textContent='Send to network';\n" +
            "    progBar.style.width='100%'; setTimeout(()=>progWrap.style.display='none', 700);\n" +
            "    try { var res = JSON.parse(xhr.responseText); } catch(e){ show('fail','Unexpected server response.'); return; }\n" +
            "    if(!res.ok) { show('fail',res.error); return; }\n" +
            "    show('ok', '? File stored '+(res.existed?'(already existed)':'')+'<br><a class=\"file-link\" target=\"_blank\" href=\"/files/'+res.filename+'\">Open File</a>'); clear();\n" +
            "  };\n" +
            "  xhr.onerror = () => { sendBtn.classList.remove('busy'); show('fail','Network Error.'); };\n" +
            "  xhr.open('POST', '/'); xhr.send(fd);\n" +
            "};\n" +
            "</script>\n" +
            "</body>\n" +
            "</html>";
}