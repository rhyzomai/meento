import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.*;
import java.util.regex.*;
import java.util.stream.Collectors;

/**
 * WebCrawler - A Java 8 web crawler with zero external dependencies.
 * Collects file URLs, extracts metadata, evaluates top keywords,
 * tracks source URLs, optionally downloads files, and saves structural metadata as JSON files.
 */
public class WebCrawler {

    private static final String OUTPUT_LINKS_FILE = "links.txt";
    private static final String LINK_INFO_DIR = "link_info";
    private static final String FILES_DIR = "files";
    private static final String PUBLIC_KEY_FILE = "public_key.txt";
    private static final String NODE_INFO_FILE = "node_info.txt";

    private static final Pattern FILE_URL_PATTERN = Pattern.compile(".*\\/[^/?#]+\\.[^/?#.]+$");
    private static final Pattern LINK_PATTERN = Pattern.compile("(?:href|src)=[\"']([^\"'#>]+)[\"']", Pattern.CASE_INSENSITIVE);
    private static final int TIMEOUT_MS = 8_000;
    private static final String USER_AGENT = "Mozilla/5.0 (compatible; JavaWebCrawler/1.0)";

    // Set of common JS/HTML keywords to ignore during description keyword counting
    private static final Set<String> IGNORED_KEYWORDS = new HashSet<>(Arrays.asList(
        "html", "head", "body", "div", "span", "p", "a", "img", "src", "href", "script", 
        "var", "let", "const", "function", "return", "if", "else", "for", "while", "do", 
        "switch", "case", "break", "continue", "true", "false", "null", "undefined", 
        "class", "id", "style", "meta", "link", "object", "embed", "iframe", "canvas", 
        "svg", "window", "document", "console", "log", "import", "export", "from", "eval"
    ));

    private final Set<String> visited = new LinkedHashSet<>();
    private String publicKey = "";
    private String nodeInfo = "";
    private boolean downloadFiles = false;

    public static void main(String[] args) {
        new WebCrawler().run();
    }

    private void run() {
        Scanner scanner = new Scanner(System.in);

        System.out.println("==============================================");
        System.out.println("          Java Web Crawler (JSON Export)");
        System.out.println("==============================================");

        // Load public key and node info details early
        this.publicKey = readExternalFile(PUBLIC_KEY_FILE, 300);
        this.nodeInfo = readExternalFile(NODE_INFO_FILE, 300);

        // Ensure export folders exist
        try {
            Files.createDirectories(Paths.get(LINK_INFO_DIR));
            Files.createDirectories(Paths.get(FILES_DIR));
        } catch (IOException e) {
            System.out.println("[!] Failed to create output directories.");
            return;
        }

        // --- Ask to download files ---
        while (true) {
            System.out.print("Do you want to download all files found? (y/n): ");
            String ans = scanner.nextLine().trim().toLowerCase(Locale.ROOT);
            if (ans.equals("y") || ans.equals("yes")) {
                this.downloadFiles = true;
                break;
            } else if (ans.equals("n") || ans.equals("no")) {
                this.downloadFiles = false;
                break;
            } else {
                System.out.println("  [!] Please enter 'y' or 'n'.");
            }
        }

        // --- Read start URL ---
        String startUrl;
        while (true) {
            System.out.print("Enter start URL (e.g. https://example.com): ");
            startUrl = scanner.nextLine().trim();
            if (startUrl.isEmpty()) {
                System.out.println("  [!] URL cannot be empty.");
                continue;
            }
            if (!startUrl.startsWith("http://") && !startUrl.startsWith("https://")) {
                System.out.println("  [!] URL must start with http:// or https://.");
                continue;
            }
            break;
        }

        // --- Read depth ---
        int depth = 0;
        while (true) {
            System.out.print("Enter crawl depth (0 = start page only): ");
            String depthInput = scanner.nextLine().trim();
            try {
                depth = Integer.parseInt(depthInput);
                if (depth < 0) {
                    System.out.println("  [!] Depth must be 0 or greater.");
                    continue;
                }
                break;
            } catch (NumberFormatException e) {
                System.out.println("  [!] Please enter a valid integer.");
            }
        }

        System.out.println("\nStarting crawl: " + startUrl);
        System.out.println("----------------------------------------------");

        crawl(startUrl, depth);

        System.out.println("----------------------------------------------");
        System.out.println("Crawl complete.");
    }

    private void crawl(String url, int remainingDepth) {
        String normalisedUrl = normalise(url);
        if (normalisedUrl == null || visited.contains(normalisedUrl)) {
            return;
        }
        visited.add(normalisedUrl);

        System.out.println("[Depth " + remainingDepth + "] Fetching: " + url);

        String html = fetchPage(url);
        if (html == null) {
            return;
        }

        // Generate the description containing top 10 repeated page words
        String descriptionKeywords = extractTopWords(html);

        Set<String> links = extractLinks(html, url);
        for (String link : links) {
            if (hasFileExtension(link)) {
                processFileUrl(link, descriptionKeywords);
            }

            if (remainingDepth > 0 && !hasFileExtension(link)) {
                crawl(link, remainingDepth - 1);
            }
        }
    }

    private void processFileUrl(String fileUrl, String description) {
        // Resolve extension and base filename metadata
        String filename = "";
        String extension = "";
        try {
            String pathStr = new URL(fileUrl).getPath();
            int lastSlash = pathStr.lastIndexOf('/');
            if (lastSlash >= 0) {
                filename = pathStr.substring(lastSlash + 1);
                int lastDot = filename.lastIndexOf('.');
                if (lastDot >= 0) {
                    extension = filename.substring(lastDot + 1);
                }
            }
        } catch (MalformedURLException e) {
            return;
        }

        String finalHash;
        long size = 0;

        if (downloadFiles) {
            // Download file and grab its physical content SHA-256 hash
            DownloadResult result = downloadAndHashFile(fileUrl, extension);
            if (result == null) {
                return; // Download aborted or encountered transport errors
            }
            finalHash = result.hash;
            size = result.size;
        } else {
            // Fallback to URL SHA-256 hash if download mode is disabled
            finalHash = computeSha256(fileUrl);
            Path jsonPath = Paths.get(LINK_INFO_DIR, finalHash + ".json");
            if (Files.exists(jsonPath)) {
                return;
            }
            size = getRemoteFileSize(fileUrl);
        }

        Path jsonPath = Paths.get(LINK_INFO_DIR, finalHash + ".json");

        // Skip writing entirely if metadata matching this hash already exists
        if (Files.exists(jsonPath)) {
            return;
        }

        System.out.println("  [+] Recording metadata for: " + fileUrl);
        String dateStr = Instant.now().toString();

        // Assemble JSON structure manually maintaining strict constraint sizes and including full URL
        String json = buildMetadataJson(filename, size, extension, this.publicKey, this.nodeInfo, description, dateStr, fileUrl);

        try {
            // Write JSON File
            Files.write(jsonPath, json.getBytes(StandardCharsets.UTF_8));
            
            // Log target file full protocol address into links.txt
            Files.write(Paths.get(OUTPUT_LINKS_FILE), (fileUrl + "\n").getBytes(StandardCharsets.UTF_8),
                    StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        } catch (IOException e) {
            System.out.println("  [!] Error writing tracking file: " + e.getMessage());
        }
    }

    private static class DownloadResult {
        String hash;
        long size;
        DownloadResult(String hash, long size) {
            this.hash = hash;
            this.size = size;
        }
    }

    private DownloadResult downloadAndHashFile(String urlString, String extension) {
        Path tempFile = null;
        try {
            URL u = new URL(urlString);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(TIMEOUT_MS);
            conn.setReadTimeout(TIMEOUT_MS);
            conn.setRequestProperty("User-Agent", USER_AGENT);

            int status = conn.getResponseCode();
            if (status < 200 || status >= 300) {
                conn.disconnect();
                return null;
            }

            // Stream to a temp file first to find the SHA-256 before establishing final destination
            tempFile = Files.createTempFile("crawler_down_", ".tmp");
            MessageDigest digest = MessageDigest.getInstance("SHA-256");

            long totalBytes = 0;
            try (InputStream is = conn.getInputStream();
                 OutputStream os = Files.newOutputStream(tempFile)) {
                byte[] buffer = new byte[4096];
                int bytesRead;
                while ((bytesRead = is.read(buffer)) != -1) {
                    digest.update(buffer, 0, bytesRead);
                    os.write(buffer, 0, bytesRead);
                    totalBytes += bytesRead;
                }
            } finally {
                conn.disconnect();
            }

            // Parse hex digest byte payload
            byte[] hashBytes = digest.digest();
            StringBuilder sb = new StringBuilder();
            for (byte b : hashBytes) {
                String hex = Integer.toHexString(0xff & b);
                if (hex.length() == 1) sb.append('0');
                sb.append(hex);
            }
            String fileHash = sb.toString();

            String destFileName = fileHash + (extension.isEmpty() ? "" : "." + extension);
            Path destPath = Paths.get(FILES_DIR, destFileName);

            if (Files.exists(destPath)) {
                // Do not overwrite existing binary files
                Files.deleteIfExists(tempFile);
            } else {
                // Move temp copy to the persistent files folder
                Files.move(tempFile, destPath, StandardCopyOption.REPLACE_EXISTING);
                System.out.println("    [?] Downloaded file to: " + destPath);
            }

            return new DownloadResult(fileHash, totalBytes);

        } catch (Exception e) {
            System.out.println("    [!] Failed to pull file stream " + urlString + ": " + e.getMessage());
            if (tempFile != null) {
                try {
                    Files.deleteIfExists(tempFile);
                } catch (IOException ignored) {}
            }
            return null;
        }
    }

    private String buildMetadataJson(String filename, long size, String extension, String pubKey, String ndInfo, String desc, String date, String fullUrl) {
        String jsonTemplate = "{\n" +
                "    \"original_filename\": \"%s\",\n" +
                "    \"size\": %d,\n" +
                "    \"extension\": \"%s\",\n" +
                "    \"public_key\": \"%s\",\n" +
                "    \"node_info\": \"%s\",\n" +
                "    \"description\": \"%s\",\n" +
                "    \"date\": \"%s\",\n" +
                "    \"url\": \"%s\"\n" +
                "}";

        String compiled = String.format(jsonTemplate, escapeJson(filename), size, escapeJson(extension),
                escapeJson(pubKey), escapeJson(ndInfo), escapeJson(desc), escapeJson(date), escapeJson(fullUrl));

        // Enforce the strict overall JSON string size limitation of 1000 characters
        if (compiled.length() > 1000) {
            int overage = compiled.length() - 1000;
            if (desc.length() > overage) {
                desc = desc.substring(0, desc.length() - overage);
            } else {
                desc = "";
            }
            compiled = String.format(jsonTemplate, escapeJson(filename), size, escapeJson(extension),
                    escapeJson(pubKey), escapeJson(ndInfo), escapeJson(desc), escapeJson(date), escapeJson(fullUrl));
        }
        return compiled;
    }

    private String extractTopWords(String html) {
        // Strip out code blocks/tags elements to isolate simple presentation text
        String textOnly = html.replaceAll("<script[^>]*?>[\\s\\S]*?<\\/script>", " ")
                              .replaceAll("<style[^>]*?>[\\s\\S]*?<\\/style>", " ")
                              .replaceAll("<[^>]*>", " ");

        // Split text on whitespace arrays
        String[] tokens = textOnly.toLowerCase(Locale.ROOT).split("\\s+");
        Map<String, Integer> wordCounts = new HashMap<>();

        for (String token : tokens) {
            // Match pure letters/digits only (removes punctuation or composite terms completely)
            if (token.matches("^[a-z0-9]+$") && !IGNORED_KEYWORDS.contains(token) && token.length() > 1) {
                wordCounts.put(token, wordCounts.getOrDefault(token, 0) + 1);
            }
        }

        // Sort by occurrence counts descending and filter top 10 entries
        return wordCounts.entrySet().stream()
                .sorted((e1, e2) -> e2.getValue().compareTo(e1.getValue()))
                .limit(10)
                .map(Map.Entry::getKey)
                .collect(Collectors.joining(" "));
    }

    private long getRemoteFileSize(String urlString) {
        try {
            URL u = new URL(urlString);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setRequestMethod("HEAD");
            conn.setConnectTimeout(3000);
            conn.setReadTimeout(3000);
            conn.setRequestProperty("User-Agent", USER_AGENT);
            int status = conn.getResponseCode();
            if (status >= 200 && status < 300) {
                return conn.getContentLengthLong();
            }
        } catch (Exception e) {
            // Default fallthrough on missing size headers
        }
        return 0;
    }

    private String fetchPage(String url) {
        try {
            URL u = new URL(url);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(TIMEOUT_MS);
            conn.setReadTimeout(TIMEOUT_MS);
            conn.setInstanceFollowRedirects(true);
            conn.setRequestProperty("User-Agent", USER_AGENT);
            conn.setRequestProperty("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8");

            int status = conn.getResponseCode();
            if (status < 200 || status >= 400) {
                conn.disconnect();
                return null;
            }

            String contentType = conn.getContentType();
            if (contentType != null && !contentType.contains("text/html") && !contentType.contains("application/xhtml")) {
                conn.disconnect();
                return null;
            }

            String charset = StandardCharsets.UTF_8.name();
            if (contentType != null) {
                Pattern p = Pattern.compile("charset=([^;\\s]+)", Pattern.CASE_INSENSITIVE);
                Matcher m = p.matcher(contentType);
                if (m.find()) {
                    charset = m.group(1).replace("\"", "").trim();
                }
            }

            try (InputStream is = conn.getInputStream();
                 BufferedReader reader = new BufferedReader(new InputStreamReader(is, charset))) {
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    sb.append(line).append('\n');
                }
                return sb.toString();
            } finally {
                conn.disconnect();
            }
        } catch (Exception e) {
            // Keep going quietly if transport anomalies occur
        }
        return null;
    }

    private Set<String> extractLinks(String html, String baseUrl) {
        Set<String> links = new LinkedHashSet<>();
        Matcher m = LINK_PATTERN.matcher(html);
        while (m.find()) {
            String raw = m.group(1).trim();
            if (raw.isEmpty()) continue;

            String resolved = resolveUrl(baseUrl, raw);
            if (resolved != null) {
                links.add(resolved);
            }
        }
        return links;
    }

    private String resolveUrl(String base, String raw) {
        try {
            String lower = raw.toLowerCase(Locale.ROOT);
            if (lower.startsWith("mailto:") || lower.startsWith("javascript:") || lower.startsWith("data:") || lower.startsWith("tel:")) {
                return null;
            }
            URL baseUrl = new URL(base);
            URL resolved = new URL(baseUrl, raw);
            String scheme = resolved.getProtocol();
            if (!"http".equals(scheme) && !"https".equals(scheme)) {
                return null;
            }
            String result = resolved.toExternalForm();
            int hashIdx = result.indexOf('#');
            if (hashIdx >= 0) {
                result = result.substring(0, hashIdx);
            }
            return result;
        } catch (MalformedURLException e) {
            return null;
        }
    }

    private boolean hasFileExtension(String url) {
        try {
            String path = new URL(url).getPath();
            return FILE_URL_PATTERN.matcher(path).matches();
        } catch (MalformedURLException e) {
            return false;
        }
    }

    private String normalise(String url) {
        if (url == null || url.isEmpty()) return null;
        try {
            URL u = new URL(url);
            String path = u.getPath();
            if (path.endsWith("/")) {
                path = path.substring(0, path.length() - 1);
            }
            String query = u.getQuery() == null ? "" : "?" + u.getQuery();
            return u.getProtocol().toLowerCase(Locale.ROOT) + "://" + u.getHost().toLowerCase(Locale.ROOT)
                    + (u.getPort() == -1 ? "" : ":" + u.getPort()) + path + query;
        } catch (MalformedURLException e) {
            return null;
        }
    }

    private String computeSha256(String input) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(input.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            for (byte b : hash) {
                String hex = Integer.toHexString(0xff & b);
                if (hex.length() == 1) sb.append('0');
                sb.append(hex);
            }
            return sb.toString();
        } catch (Exception e) {
            throw new RuntimeException(e);
        }
    }

    private String readExternalFile(String filename, int limit) {
        File file = new File(filename);
        if (!file.exists()) return "";
        try {
            byte[] encoded = Files.readAllBytes(file.toPath());
            String content = new String(encoded, StandardCharsets.UTF_8).trim();
            if (content.length() > limit) {
                return content.substring(0, limit);
            }
            return content;
        } catch (IOException e) {
            return "";
        }
    }

    private String escapeJson(String str) {
        if (str == null) return "";
        return str.replace("\\", "\\\\")
                  .replace("\"", "\\\"")
                  .replace("\n", "\\n")
                  .replace("\r", "\\r")
                  .replace("\t", "\\t");
    }
}