import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpHandler;
import com.sun.net.httpserver.HttpServer;

import javax.net.ssl.*;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import java.util.*;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

// -------------------------------------------------------------------------------
//  MESH NODE — file replication over HTTP/HTTPS (Java 8 Port)
//  Single-file application, no external dependencies
// -------------------------------------------------------------------------------
public class Index {

    private static final String FILES_DIR = "./files";
    private static final String INFO_DIR = "./info";
    private static final String FILES_INDEX = "./files.txt";
    private static final String SERVERS_FILE = "./servers.txt";
    private static final String PUB_KEY_FILE = "./public_key.txt";
    private static final String NODE_INFO_FILE = "./node_info.txt";
    private static final String DATA_JSON_FILE = "./data.json";
    private static final int MAX_COMMENT = 1024;
    private static final List<String> BLOCKED_EXTS = Arrays.asList("php", "php3", "php4", "php5", "php7", "phtml", "phar");

    private static final Object FILES_TXT_LOCK = new Object();
    private static final Object DATA_JSON_LOCK = new Object();

    public static void main(String[] args) throws Exception {
        initDirs();
        disableSslVerification();

        HttpServer server = HttpServer.create(new InetSocketAddress(8080), 0);
        server.createContext("/", new RootHandler());
        server.createContext("/files/", new FilesHandler());
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();

        System.out.println("Mesh Node (Java 8) running on http://localhost:8080");
    }

    // -- Init directories & files --------------------------------------------------
    private static void initDirs() throws IOException {
        Files.createDirectories(Paths.get(FILES_DIR));
        Files.createDirectories(Paths.get(INFO_DIR));
        Path indexFile = Paths.get(FILES_INDEX);
        if (!Files.exists(indexFile)) {
            Files.write(indexFile, new byte[0]);
        }
    }

    private static void disableSslVerification() {
        try {
            TrustManager[] trustAll = new TrustManager[]{new X509TrustManager() {
                public X509Certificate[] getAcceptedIssuers() { return null; }
                public void checkClientTrusted(X509Certificate[] certs, String authType) { }
                public void checkServerTrusted(X509Certificate[] certs, String authType) { }
            }};
            SSLContext sc = SSLContext.getInstance("SSL");
            sc.init(null, trustAll, new SecureRandom());
            HttpsURLConnection.setDefaultSSLSocketFactory(sc.getSocketFactory());
            HttpsURLConnection.setDefaultHostnameVerifier((hostname, session) -> true);
        } catch (Exception ignored) {}
    }

    // -- Helpers -------------------------------------------------------------------
    private static String safeExt(String filename) {
        if (filename == null || !filename.contains(".")) return "";
        String ext = filename.substring(filename.lastIndexOf('.') + 1).toLowerCase();
        return ext.replaceAll("[^a-z0-9]", "");
    }

    private static String readOptionalFile(String pathStr) {
        try {
            Path p = Paths.get(pathStr);
            if (Files.exists(p)) {
                return new String(Files.readAllBytes(p), StandardCharsets.UTF_8).trim();
            }
        } catch (IOException ignored) {}
        return "";
    }

    private static List<String> loadServers() {
        List<String> servers = new ArrayList<>();
        try {
            List<String> lines = Files.readAllLines(Paths.get(SERVERS_FILE), StandardCharsets.UTF_8);
            Set<String> seen = new HashSet<>();
            for (String line : lines) {
                line = line.trim();
                if (line.isEmpty()) continue;
                if (!line.toLowerCase().startsWith("http://") && !line.toLowerCase().startsWith("https://")) {
                    line = "http://" + line;
                }
                while (line.endsWith("/")) line = line.substring(0, line.length() - 1);
                if (seen.add(line)) {
                    servers.add(line);
                }
            }
        } catch (IOException ignored) {}
        return servers;
    }

    private static String escapeJson(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\")
                .replace("\"", "\\\"")
                .replace("\b", "\\b")
                .replace("\f", "\\f")
                .replace("\n", "\\n")
                .replace("\r", "\\r")
                .replace("\t", "\\t");
    }

    private static void sendJson(HttpExchange exchange, int code, String json) throws IOException {
        exchange.getResponseHeaders().set("Content-Type", "application/json");
        exchange.getResponseHeaders().set("Access-Control-Allow-Origin", "*");
        byte[] bytes = json.getBytes(StandardCharsets.UTF_8);
        exchange.sendResponseHeaders(code, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) {
            os.write(bytes);
        }
    }

    private static void appendIndex(String entry) {
        synchronized (FILES_TXT_LOCK) {
            try {
                Path p = Paths.get(FILES_INDEX);
                List<String> lines = Files.exists(p) ? Files.readAllLines(p) : new ArrayList<>();
                for (String line : lines) {
                    if (line.trim().equals(entry)) return;
                }
                Files.write(p, (entry + "\n").getBytes(StandardCharsets.UTF_8), StandardOpenOption.CREATE, StandardOpenOption.APPEND);
            } catch (IOException ignored) {}
        }
    }

    private static void appendDataJson(Map<String, Object> info) {
        synchronized (DATA_JSON_LOCK) {
            try {
                Path p = Paths.get(DATA_JSON_FILE);
                String content = Files.exists(p) ? new String(Files.readAllBytes(p), StandardCharsets.UTF_8).trim() : "";
                if (content.isEmpty() || !content.startsWith("[")) {
                    content = "[\n]";
                }
                
                StringBuilder newEntry = new StringBuilder();
                newEntry.append("  {\n");
                boolean first = true;
                for (Map.Entry<String, Object> e : info.entrySet()) {
                    if (!first) newEntry.append(",\n");
                    newEntry.append("    \"").append(e.getKey()).append("\": ");
                    if (e.getValue() instanceof Number || e.getValue() instanceof Boolean) {
                        newEntry.append(e.getValue());
                    } else {
                        newEntry.append("\"").append(escapeJson(String.valueOf(e.getValue()))).append("\"");
                    }
                    first = false;
                }
                newEntry.append("\n  }");

                String out;
                if (content.length() <= 3) {
                    out = "[\n" + newEntry + "\n]";
                } else {
                    int lastBracket = content.lastIndexOf(']');
                    out = content.substring(0, lastBracket).trim() + ",\n" + newEntry + "\n]";
                }
                Files.write(p, out.getBytes(StandardCharsets.UTF_8));
            } catch (IOException ignored) {}
        }
    }

    // -- HTTP Handlers -------------------------------------------------------------
    static class FilesHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if (!"GET".equals(exchange.getRequestMethod())) {
                exchange.sendResponseHeaders(405, -1);
                return;
            }
            String path = exchange.getRequestURI().getPath().substring("/files/".length());
            Path file = Paths.get(FILES_DIR, path).normalize();
            if (!file.startsWith(Paths.get(FILES_DIR).normalize()) || !Files.exists(file) || Files.isDirectory(file)) {
                exchange.sendResponseHeaders(404, -1);
                return;
            }
            byte[] bytes = Files.readAllBytes(file);
            exchange.sendResponseHeaders(200, bytes.length);
            try (OutputStream os = exchange.getResponseBody()) {
                os.write(bytes);
            }
        }
    }

    static class RootHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            try {
                if ("GET".equals(exchange.getRequestMethod())) {
                    String query = exchange.getRequestURI().getQuery();
                    if (query != null && query.contains("node_status=1")) {
                        handleStatus(exchange);
                    } else {
                        handleHtml(exchange);
                    }
                } else if ("POST".equals(exchange.getRequestMethod())) {
                    handleUpload(exchange);
                } else if ("OPTIONS".equals(exchange.getRequestMethod())) {
                    exchange.getResponseHeaders().set("Access-Control-Allow-Origin", "*");
                    exchange.sendResponseHeaders(204, -1);
                } else {
                    exchange.sendResponseHeaders(405, -1);
                }
            } catch (Exception e) {
                e.printStackTrace();
                sendJson(exchange, 500, "{\"ok\":false,\"error\":\"" + escapeJson(e.getMessage()) + "\"}");
            }
        }

        private void handleStatus(HttpExchange exchange) throws IOException {
            boolean hasPk = !readOptionalFile(PUB_KEY_FILE).isEmpty();
            boolean hasNi = !readOptionalFile(NODE_INFO_FILE).isEmpty();
            int peers = loadServers().size();
            String json = String.format("{\"public_key\":%b,\"node_info\":%b,\"peers\":%d}", hasPk, hasNi, peers);
            sendJson(exchange, 200, json);
        }

        private void handleHtml(HttpExchange exchange) throws IOException {
            byte[] bytes = HTML_TEMPLATE.getBytes(StandardCharsets.UTF_8);
            exchange.getResponseHeaders().set("Content-Type", "text/html; charset=utf-8");
            exchange.sendResponseHeaders(200, bytes.length);
            try (OutputStream os = exchange.getResponseBody()) {
                os.write(bytes);
            }
        }

        private void handleUpload(HttpExchange exchange) throws Exception {
            String contentType = exchange.getRequestHeaders().getFirst("Content-Type");
            if (contentType == null || !contentType.contains("multipart/form-data")) {
                sendJson(exchange, 400, "{\"ok\":false,\"error\":\"Invalid content type\"}");
                return;
            }

            String boundary = contentType.substring(contentType.indexOf("boundary=") + 9);
            MultipartData data = parseMultipart(exchange.getRequestBody(), boundary);

            if (data.fileBytes == null) {
                sendJson(exchange, 400, "{\"ok\":false,\"error\":\"Missing file payload\"}");
                return;
            }

            String originalName = data.fileName;
            String ext = safeExt(originalName);
            if (BLOCKED_EXTS.contains(ext)) {
                sendJson(exchange, 400, "{\"ok\":false,\"error\":\"PHP files are not accepted.\"}");
                return;
            }

            boolean isPeerPush = "1".equals(data.fields.get("peer_push"));
            String descField = isPeerPush ? "description" : "comment";
            String description = data.fields.containsKey(descField) ? data.fields.get(descField) : "";
            if (description.length() > MAX_COMMENT) description = description.substring(0, MAX_COMMENT);

            String pubKey = isPeerPush ? data.fields.getOrDefault("public_key", "").trim() : readOptionalFile(PUB_KEY_FILE);
            if (!isPeerPush && pubKey.isEmpty() && data.fields.containsKey("public_key")) {
                pubKey = data.fields.get("public_key").trim();
                if (pubKey.length() > 512) pubKey = pubKey.substring(0, 512);
            }

            String nodeInfo = isPeerPush ? data.fields.getOrDefault("node_info", "").trim() : readOptionalFile(NODE_INFO_FILE);

            // Hash
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hashBytes = digest.digest(data.fileBytes);
            StringBuilder sb = new StringBuilder();
            for (byte b : hashBytes) sb.append(String.format("%02x", b));
            String hash = sb.toString();

            String baseName = ext.isEmpty() ? hash : hash + "." + ext;
            Path destFile = Paths.get(FILES_DIR, baseName);
            Path destInfo = Paths.get(INFO_DIR, hash + ".json");

            boolean existed = Files.exists(destFile);
            if (!existed) {
                Files.write(destFile, data.fileBytes);
                appendIndex(baseName);
            }

            if (!Files.exists(destInfo)) {
                Map<String, Object> info = new LinkedHashMap<>();
                info.put("hash", hash);
                info.put("original_filename", originalName);
                info.put("size", data.fileBytes.length);
                info.put("extension", ext);
                info.put("public_key", pubKey);
                info.put("node_info", nodeInfo);
                info.put("description", description);

                // Write info json
                StringBuilder infoStr = new StringBuilder("{\n");
                boolean first = true;
                for (Map.Entry<String, Object> e : info.entrySet()) {
                    if(e.getKey().equals("hash")) continue; // Don't write hash to individual files per original logic
                    if (!first) infoStr.append(",\n");
                    infoStr.append("  \"").append(e.getKey()).append("\": ");
                    if (e.getValue() instanceof Number) infoStr.append(e.getValue());
                    else infoStr.append("\"").append(escapeJson(String.valueOf(e.getValue()))).append("\"");
                    first = false;
                }
                infoStr.append("\n}");
                Files.write(destInfo, infoStr.toString().getBytes(StandardCharsets.UTF_8));
                appendDataJson(info);
            }

            if (isPeerPush) {
                sendJson(exchange, 200, "{\"ok\":true,\"hash\":\"" + hash + "\",\"filename\":\"" + baseName + "\",\"existed\":" + existed + "}");
                return;
            }

            List<String> servers = loadServers();
            String pkMsg = pubKey.isEmpty() ? "— not found" : "? included";
            String niMsg = nodeInfo.isEmpty() ? "— not found" : "? included";

            String resp = String.format("{\"ok\":true,\"hash\":\"%s\",\"filename\":\"%s\",\"existed\":%b,\"public_key\":\"%s\",\"node_info\":\"%s\",\"peers_total\":%d,\"peers\":[]}",
                    hash, baseName, existed, pkMsg, niMsg, servers.size());

            sendJson(exchange, 200, resp);

            if (!existed && !servers.isEmpty()) {
                final String fExt = ext;
                final String fDesc = description;
                final String fPk = pubKey;
                final String fNi = nodeInfo;
                new Thread(() -> {
                    for (String server : servers) {
                        pushToServer(server, destFile, originalName, fDesc, fPk, fNi);
                    }
                }).start();
            }
        }
    }

    // -- Peer Replication ----------------------------------------------------------
    private static void pushToServer(String serverUrl, Path file, String originalName, String desc, String pk, String ni) {
        try {
            String boundary = "----WebKitFormBoundary" + System.currentTimeMillis();
            URL url = new URL(serverUrl + "/");
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setDoOutput(true);
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
            conn.setRequestProperty("X-Mesh-Node", "1");
            conn.setConnectTimeout(20000);
            conn.setReadTimeout(20000);

            try (OutputStream os = conn.getOutputStream()) {
                writeFormField(os, boundary, "description", desc);
                writeFormField(os, boundary, "public_key", pk);
                writeFormField(os, boundary, "node_info", ni);
                writeFormField(os, boundary, "peer_push", "1");

                os.write(("--" + boundary + "\r\n").getBytes(StandardCharsets.UTF_8));
                os.write(("Content-Disposition: form-data; name=\"file\"; filename=\"" + originalName + "\"\r\n").getBytes(StandardCharsets.UTF_8));
                os.write(("Content-Type: application/octet-stream\r\n\r\n").getBytes(StandardCharsets.UTF_8));
                Files.copy(file, os);
                os.write(("\r\n--" + boundary + "--\r\n").getBytes(StandardCharsets.UTF_8));
                os.flush();
            }
            conn.getResponseCode();
        } catch (Exception ignored) {}
    }

    private static void writeFormField(OutputStream os, String boundary, String name, String value) throws IOException {
        os.write(("--" + boundary + "\r\n").getBytes(StandardCharsets.UTF_8));
        os.write(("Content-Disposition: form-data; name=\"" + name + "\"\r\n\r\n").getBytes(StandardCharsets.UTF_8));
        os.write((value + "\r\n").getBytes(StandardCharsets.UTF_8));
    }

    // -- Simple Multipart Parser (Zero Dependencies) -------------------------------
    static class MultipartData {
        Map<String, String> fields = new HashMap<>();
        byte[] fileBytes;
        String fileName;
    }

    private static MultipartData parseMultipart(InputStream is, String boundaryStr) throws IOException {
        MultipartData data = new MultipartData();
        ByteArrayOutputStream buffer = new ByteArrayOutputStream();
        byte[] chunk = new byte[8192];
        int read;
        while ((read = is.read(chunk)) != -1) buffer.write(chunk, 0, read);
        byte[] body = buffer.toByteArray();

        byte[] boundary = ("--" + boundaryStr).getBytes(StandardCharsets.US_ASCII);
        int pos = 0;
        while (pos < body.length) {
            int start = indexOf(body, boundary, pos);
            if (start == -1) break;
            start += boundary.length;
            if (start + 1 < body.length && body[start] == '-' && body[start + 1] == '-') break; // End boundary
            start += 2; // Skip \r\n

            int end = indexOf(body, boundary, start);
            if (end == -1) break;
            int partEnd = end - 2; // Subtract \r\n

            int headerEnd = indexOf(body, new byte[]{'\r', '\n', '\r', '\n'}, start);
            if (headerEnd != -1 && headerEnd < partEnd) {
                String headers = new String(body, start, headerEnd - start, StandardCharsets.ISO_8859_1);
                int contentStart = headerEnd + 4;
                int contentLength = partEnd - contentStart;

                Matcher nameMatcher = Pattern.compile("name=\"([^\"]+)\"").matcher(headers);
                Matcher fileMatcher = Pattern.compile("filename=\"([^\"]*)\"").matcher(headers);

                if (nameMatcher.find()) {
                    String name = nameMatcher.group(1);
                    if (fileMatcher.find()) {
                        data.fileName = fileMatcher.group(1);
                        data.fileBytes = new byte[contentLength];
                        System.arraycopy(body, contentStart, data.fileBytes, 0, contentLength);
                    } else {
                        data.fields.put(name, new String(body, contentStart, contentLength, StandardCharsets.UTF_8));
                    }
                }
            }
            pos = end;
        }
        return data;
    }

    private static int indexOf(byte[] data, byte[] pattern, int start) {
        for (int i = start; i <= data.length - pattern.length; i++) {
            boolean match = true;
            for (int j = 0; j < pattern.length; j++) {
                if (data[i + j] != pattern[j]) { match = false; break; }
            }
            if (match) return i;
        }
        return -1;
    }

    // -- HTML Frontend String Literal ----------------------------------------------
    private static final String HTML_TEMPLATE = "<!DOCTYPE html>\n" +
            "<html lang=\"en\">\n" +
            "<head>\n" +
            "<meta charset=\"UTF-8\">\n" +
            "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n" +
            "<title>Mesh Node (Java)</title>\n" +
            "<style>\n" +
            "*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}\n" +
            "html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif}\n" +
            "body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}\n" +
            ".page{max-width:36rem;width:100%}\n" +
            "header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}\n" +
            "h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}\n" +
            "h1 em{font-weight:400;color:#555;font-style:normal}\n" +
            ".sub{font-size:0.8rem;color:#777;margin-top:0.2rem}\n" +
            ".search-link{font-size:0.85rem;color:#0044cc;text-decoration:none;white-space:nowrap}\n" +
            ".search-link:hover{text-decoration:underline}\n" +
            ".head-nav {display:flex;gap:1rem;align-items:center;}\n" +
            ".head-nav a {font-size:0.85rem;color:#0044cc;text-decoration:none;font-weight:500;}\n" +
            ".head-nav a:hover {text-decoration:underline;}\n" +
            "#node-bar{display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.75rem;font-size:0.8rem;color:#555}\n" +
            ".nb-pill{display:flex;align-items:center;gap:0.35rem;padding:0.25rem 0.6rem;background:#f5f5f5;border-radius:4px;border:1px solid #e0e0e0}\n" +
            ".nb-dot{width:7px;height:7px;border-radius:50%;background:#aaa}\n" +
            ".nb-dot.ok{background:#1a8e3f}\n" +
            ".nb-dot.blue{background:#0044cc}\n" +
            ".nb-val{font-weight:500}\n" +
            "#drop-zone{border:2px dashed #ccc;border-radius:6px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s}\n" +
            "#drop-zone:hover,#drop-zone:focus{background:#fafafa;border-color:#999}\n" +
            "#drop-zone.over{border-color:#0044cc;background:#f0f4ff}\n" +
            ".dz-icon{margin-bottom:0.75rem;font-size:1.8rem;color:#777}\n" +
            ".dz-title{font-size:1rem;font-weight:600;margin-bottom:0.3rem}\n" +
            ".dz-sub{font-size:0.8rem;color:#666;line-height:1.5}\n" +
            ".dz-sub b{color:#0044cc;font-weight:600}\n" +
            "#file-input{display:none}\n" +
            "#panel{display:none;margin-top:1.25rem;border:1px solid #ddd;border-radius:6px;overflow:hidden}\n" +
            ".panel-top{display:flex;align-items:center;gap:0.8rem;padding:0.8rem 1rem;border-bottom:1px solid #eee}\n" +
            ".f-icon{width:2rem;height:2rem;background:#f0f4ff;border-radius:4px;display:grid;place-items:center;flex-shrink:0;font-size:1rem;color:#0044cc}\n" +
            "#f-name{font-size:0.9rem;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}\n" +
            "#f-size{font-size:0.8rem;color:#777;flex-shrink:0}\n" +
            ".x-btn{background:none;border:none;cursor:pointer;font-size:1.2rem;color:#999;padding:0 0.2rem;transition:color 0.15s}\n" +
            ".x-btn:hover{color:#d00}\n" +
            ".meta-pills{padding:0.5rem 1rem;display:flex;gap:0.5rem;flex-wrap:wrap;border-bottom:1px solid #eee;background:#fafafa}\n" +
            ".mpill{font-size:0.75rem;padding:0.2rem 0.6rem;border-radius:4px;border:1px solid #ddd;color:#555;background:#fff;display:flex;align-items:center;gap:0.3rem}\n" +
            ".mpill.has{border-color:#1a8e3f;color:#1a8e3f;background:#f0fff0}\n" +
            ".mpill svg{width:0.9rem;height:0.9rem;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}\n" +
            ".panel-mid{padding:1rem}\n" +
            ".fl{display:block;font-size:0.75rem;color:#777;margin-bottom:0.5rem}\n" +
            "textarea#comment-box{width:100%;min-height:5rem;padding:0.6rem 0.8rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;resize:vertical;outline:none;transition:border-color 0.2s}\n" +
            "textarea#comment-box:focus{border-color:#0044cc}\n" +
            ".cmeta{display:flex;justify-content:space-between;margin-top:0.4rem;font-size:0.75rem;color:#999}\n" +
            ".ccount.over{color:#d00}\n" +
            ".panel-bot{padding:0.8rem 1rem;border-top:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:0.8rem}\n" +
            ".peer-info{font-size:0.8rem;color:#555}\n" +
            ".peer-info b{font-weight:600}\n" +
            ".btn-send{padding:0.5rem 1.2rem;background:#0044cc;color:#fff;border:none;border-radius:4px;font-weight:600;font-size:0.85rem;cursor:pointer;transition:opacity 0.2s;display:flex;align-items:center;gap:0.4rem}\n" +
            ".btn-send:hover{opacity:0.9}\n" +
            ".btn-send.busy{opacity:0.5;pointer-events:none}\n" +
            ".btn-send svg{width:1rem;height:1rem;stroke:#fff;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}\n" +
            "#prog-wrap{height:3px;background:#eee;border-radius:3px;overflow:hidden;display:none;margin-top:1rem}\n" +
            "#prog-bar{height:100%;width:0%;background:#0044cc;transition:width 0.1s linear}\n" +
            "#status{display:none;margin-top:1rem;padding:0.8rem 1rem;border-radius:4px;font-size:0.9rem;line-height:1.5;animation:fadeUp 0.25s ease}\n" +
            "#status.ok{background:#f0fff0;border:1px solid #c0e0c0;color:#1a8e3f}\n" +
            "#status.fail{background:#fff0f0;border:1px solid #e0c0c0;color:#d00}\n" +
            ".hash-line{margin-top:0.6rem}\n" +
            ".file-link{display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;color:#0044cc;border:1px solid #0044cc;border-radius:4px;transition:background 0.15s}\n" +
            ".file-link:hover{background:#f0f4ff}\n" +
            ".file-link svg{width:0.9rem;height:0.9rem;stroke:currentColor;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}\n" +
            ".json-preview{margin-top:2rem;border:1px solid #ddd;border-radius:4px;overflow:hidden}\n" +
            ".json-preview-head{padding:0.5rem 1rem;border-bottom:1px solid #eee;font-size:0.75rem;color:#777;background:#fafafa;display:flex;align-items:center;gap:0.4rem}\n" +
            ".json-preview-head svg{width:0.9rem;height:0.9rem;stroke:#777;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}\n" +
            "pre#json-schema{padding:1rem;font-family:\"SF Mono\",\"Fira Code\",\"Fira Mono\",\"Roboto Mono\",monospace;font-size:0.8rem;line-height:1.6;color:#333;overflow-x:auto;margin:0}\n" +
            ".jk{color:#0044cc} .js{color:#1a8e3f} .jn{color:#b04000} .jb{color:#d00}\n" +
            "footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}\n" +
            "@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}\n" +
            "</style>\n" +
            "</head>\n" +
            "<body>\n" +
            "<div class=\"page\">\n" +
            "<header>\n" +
            "  <div>\n" +
            "    <h1>Mesh <em>Node</em></h1>\n" +
            "    <p class=\"sub\">Peer replication · SHA-256 storage</p>\n" +
            "  </div>\n" +
            "  <nav class=\"head-nav\">\n" +
            "    <a href=\"/\">Upload</a>\n" +
            "    <a href=\"/view\">Search</a>\n" +
            "    <a href=\"/servers\">Servers</a>\n" +
            "  </nav>\n" +
            "</header>\n" +
            "<div id=\"node-bar\">\n" +
            "  <div class=\"nb-pill\"><span class=\"nb-dot blue\"></span> peers <span class=\"nb-val\" id=\"nb-peers\">…</span></div>\n" +
            "  <div class=\"nb-pill\"><span class=\"nb-dot\" id=\"nb-pk-dot\"></span> public_key.txt <span class=\"nb-val\" id=\"nb-pk\">…</span></div>\n" +
            "  <div class=\"nb-pill\"><span class=\"nb-dot\" id=\"nb-ni-dot\"></span> node_info.txt <span class=\"nb-val\" id=\"nb-ni\">…</span></div>\n" +
            "</div>\n" +
            "<div id=\"drop-zone\" tabindex=\"0\" role=\"button\" aria-label=\"Select or drop a file\">\n" +
            "  <div class=\"dz-icon\">??</div>\n" +
            "  <p class=\"dz-title\">Drop a file or click to select</p>\n" +
            "  <p class=\"dz-sub\">Stored as <b>sha256.ext</b> · pushed to all peers · <b>.php</b> blocked</p>\n" +
            "</div>\n" +
            "<input type=\"file\" id=\"file-input\">\n" +
            "<div id=\"panel\">\n" +
            "  <div class=\"panel-top\">\n" +
            "    <div class=\"f-icon\">??</div>\n" +
            "    <span id=\"f-name\">—</span>\n" +
            "    <span id=\"f-size\"></span>\n" +
            "    <button class=\"x-btn\" id=\"x-btn\" aria-label=\"Remove file\">?</button>\n" +
            "  </div>\n" +
            "  <div class=\"meta-pills\">\n" +
            "    <div class=\"mpill\" id=\"mpill-pk\"><svg viewBox=\"0 0 24 24\"><path d=\"M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4\"/></svg> public_key</div>\n" +
            "    <input type=\"text\" id=\"pk-inline\" maxlength=\"512\" placeholder=\"public key…\" style=\"display:none;flex:1;min-width:0;padding:0.2rem 0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.78rem;outline:none;color:#333;background:#fff;transition:border-color 0.2s\" onfocus=\"this.style.borderColor='#0044cc'\" onblur=\"this.style.borderColor='#ccc'\">\n" +
            "    <div class=\"mpill\" id=\"mpill-ni\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"12\" y1=\"8\" x2=\"12\" y2=\"12\"/><line x1=\"12\" y1=\"16\" x2=\"12.01\" y2=\"16\"/></svg> node_info</div>\n" +
            "    <div class=\"mpill has\"><svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg> filename · size · extension</div>\n" +
            "  </div>\n" +
            "  <div class=\"panel-mid\">\n" +
            "    <label class=\"fl\" for=\"comment-box\">Description <span style=\"color:#aaa\">(optional · max 1 KB)</span></label>\n" +
            "    <textarea id=\"comment-box\" placeholder=\"Add an optional description for this file…\"></textarea>\n" +
            "    <div class=\"cmeta\">\n" +
            "      <span>Stored as “description” field</span>\n" +
            "      <span class=\"ccount\" id=\"ccount\">0 / 1?000</span>\n" +
            "    </div>\n" +
            "  </div>\n" +
            "  <div class=\"panel-bot\">\n" +
            "    <span class=\"peer-info\">Sending to <b id=\"peer-count\">…</b> peer(s)</span>\n" +
            "    <button class=\"btn-send\" id=\"send-btn\">\n" +
            "      <svg viewBox=\"0 0 24 24\"><line x1=\"22\" y1=\"2\" x2=\"11\" y2=\"13\"/><polygon points=\"22 2 15 22 11 13 2 9 22 2\"/></svg> Send to network\n" +
            "    </button>\n" +
            "  </div>\n" +
            "</div>\n" +
            "<div id=\"prog-wrap\"><div id=\"prog-bar\"></div></div>\n" +
            "<div id=\"status\"></div>\n" +
            "<div class=\"json-preview\">\n" +
            "  <div class=\"json-preview-head\"><svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg> info/&lt;hash&gt;.json — stored structure</div>\n" +
            "  <pre id=\"json-schema\">{\n" +
            "  <span class=\"jk\">\"original_filename\"</span>: <span class=\"js\">\"photo.jpg\"</span>,\n" +
            "  <span class=\"jk\">\"size\"</span>:              <span class=\"jn\">204800</span>,\n" +
            "  <span class=\"jk\">\"extension\"</span>:        <span class=\"js\">\"jpg\"</span>,\n" +
            "  <span class=\"jk\">\"public_key\"</span>:       <span class=\"js\">\"&lt;contents of public_key.txt or empty string&gt;\"</span>,\n" +
            "  <span class=\"jk\">\"node_info\"</span>:        <span class=\"js\">\"&lt;contents of node_info.txt or empty string&gt;\"</span>,\n" +
            "  <span class=\"jk\">\"description\"</span>:      <span class=\"js\">\"&lt;user comment or empty string&gt;\"</span>\n" +
            "}</pre>\n" +
            "</div>\n" +
            "</div>\n" +
            "<footer class=\"page\">\n" +
            "  <p>Mesh Node · files stored as sha256.ext · info JSON has 6 fixed fields</p>\n" +
            "  <p>Java 8 (Native Standard Library)</p>\n" +
            "</footer>\n" +
            "<script>\n" +
            "(function(){\n" +
            "'use strict';\n" +
            "const dz=document.getElementById('drop-zone'),fi=document.getElementById('file-input'),panel=document.getElementById('panel'),fName=document.getElementById('f-name'),fSize=document.getElementById('f-size'),xBtn=document.getElementById('x-btn'),commentB=document.getElementById('comment-box'),ccount=document.getElementById('ccount'),sendBtn=document.getElementById('send-btn'),peerCnt=document.getElementById('peer-count'),status=document.getElementById('status'),progWrap=document.getElementById('prog-wrap'),progBar=document.getElementById('prog-bar'),mpillPk=document.getElementById('mpill-pk'),mpillNi=document.getElementById('mpill-ni'),pkInline=document.getElementById('pk-inline');\n" +
            "const MAX=1000,BLOCKED=['php','php3','php4','php5','php7','phtml','phar'];\n" +
            "let file=null,ns={public_key:false,node_info:false,peers:0};\n" +
            "fetch(window.location.href.split('?')[0]+'?node_status=1').then(r=>r.json()).then(d=>{\n" +
            "  ns=d; document.getElementById('nb-peers').textContent=d.peers; peerCnt.textContent=d.peers;\n" +
            "  const dot=(id,ok)=>{const el=document.getElementById(id);if(el)el.className='nb-dot '+(ok?'ok':'');};\n" +
            "  dot('nb-pk-dot',d.public_key); dot('nb-ni-dot',d.node_info);\n" +
            "  document.getElementById('nb-pk').textContent=d.public_key?'? found':'— missing';\n" +
            "  document.getElementById('nb-ni').textContent=d.node_info?'? found':'— missing'; updatePills();\n" +
            "}).catch(()=>{['nb-peers','nb-pk','nb-ni'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent='?';});peerCnt.textContent='?';});\n" +
            "function updatePills(){mpillPk.style.display=ns.public_key?'':'none';pkInline.style.display=ns.public_key?'none':'';mpillNi.className='mpill '+(ns.node_info?'has':'');}\n" +
            "function fmt(b){if(b<1024)return b+' B';if(b<1048576)return (b/1024).toFixed(1)+' KB';return (b/1048576).toFixed(2)+' MB';}\n" +
            "function extOf(n){return n.split('.').pop().toLowerCase();}\n" +
            "function setFile(f){if(!f)return;if(BLOCKED.includes(extOf(f.name))){show('fail','PHP files are not accepted.');return;}file=f;fName.textContent=f.name;fSize.textContent=fmt(f.size);panel.style.display='block';commentB.value='';updateCount();updatePills();hide();}\n" +
            "function clear(){file=null;fi.value='';panel.style.display='none';commentB.value='';pkInline.value='';}\n" +
            "dz.addEventListener('click',()=>fi.click()); dz.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' ')fi.click();});\n" +
            "fi.addEventListener('change',()=>{if(fi.files[0])setFile(fi.files[0]);}); xBtn.addEventListener('click',clear);\n" +
            "['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('over');}));\n" +
            "['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('over');}));\n" +
            "dz.addEventListener('drop',e=>{if(e.dataTransfer.files[0])setFile(e.dataTransfer.files[0]);});\n" +
            "function updateCount(){const len=new TextEncoder().encode(commentB.value).length;ccount.textContent=len.toLocaleString()+' / '+MAX.toLocaleString();ccount.classList.toggle('over',len>MAX);}\n" +
            "commentB.addEventListener('input',updateCount);\n" +
            "function show(type,html){status.className=type;status.innerHTML=html;status.style.display='block';} function hide(){status.style.display='none';}\n" +
            "sendBtn.addEventListener('click',()=>{\n" +
            "  if(!file)return; if(new TextEncoder().encode(commentB.value).length>MAX){show('fail','Description exceeds the 1 KB limit.');return;}\n" +
            "  const fd=new FormData(); fd.append('file',file); fd.append('comment',commentB.value);\n" +
            "  if(!ns.public_key&&pkInline.value.trim()!=='')fd.append('public_key',pkInline.value.trim().slice(0,512));\n" +
            "  const xhr=new XMLHttpRequest(); sendBtn.classList.add('busy'); sendBtn.lastChild.textContent=' Transmitting…';\n" +
            "  progWrap.style.display='block'; progBar.style.width='0%'; hide();\n" +
            "  xhr.upload.addEventListener('progress',e=>{if(e.lengthComputable)progBar.style.width=Math.round(e.loaded/e.total*100)+'%';});\n" +
            "  xhr.addEventListener('load',()=>{\n" +
            "    sendBtn.classList.remove('busy'); sendBtn.lastChild.textContent=' Send to network';\n" +
            "    progBar.style.width='100%'; setTimeout(()=>{progWrap.style.display='none';progBar.style.width='0%';},700);\n" +
            "    let res; try{const raw=xhr.responseText.replace(/^[\\s\\S]*?(\\{)/,'$1');res=JSON.parse(raw);}catch(e){\n" +
            "      const m=xhr.responseText.match(/\"filename\"\\s*:\\s*\"([^\"]+)\"/); if(m){showLink(m[1],false);clear();return;}\n" +
            "      show('fail','Unexpected server response.');return;\n" +
            "    }\n" +
            "    if(!res.ok){show('fail','? '+(res.error||'Unknown error'));return;} showLink(res.filename,!!res.existed); clear();\n" +
            "  });\n" +
            "  xhr.addEventListener('error',()=>{sendBtn.classList.remove('busy');sendBtn.lastChild.textContent=' Send to network';show('fail','? Network error.');progWrap.style.display='none';});\n" +
            "  xhr.open('POST',window.location.href.split('?')[0]); xhr.send(fd);\n" +
            "});\n" +
            "function showLink(filename,existed){\n" +
            "  const fileUrl='files/'+esc(filename), note=existed?' <span style=\"opacity:0.6\">(already stored)</span>':'';\n" +
            "  show('ok','? File stored'+note+'<div class=\"hash-line\"><a class=\"file-link\" href=\"'+fileUrl+'\" target=\"_blank\" rel=\"noopener noreferrer\"><svg viewBox=\"0 0 24 24\"><path d=\"M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6\"/><polyline points=\"15 3 21 3 21 9\"/><line x1=\"10\" y1=\"14\" x2=\"21\" y2=\"3\"/></svg> Open file in new tab</a></div>');\n" +
            "}\n" +
            "function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');}\n" +
            "})();\n" +
            "</script>\n" +
            "</body>\n" +
            "</html>";
}