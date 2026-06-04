<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  MESH NODE — sync.php
//  Reads every file in /files and every .json in /info,
//  then pushes them all to every server listed in servers.txt.
//
//  Run from CLI:     php sync.php
//  Run from browser: http://yournode/sync.php
// ═══════════════════════════════════════════════════════════════════════════════

define('FILES_DIR',    __DIR__ . '/files');
define('INFO_DIR',     __DIR__ . '/info');
define('SERVERS_FILE', __DIR__ . '/servers.txt');
define('PUB_KEY_FILE', __DIR__ . '/public_key.txt');
define('NODE_INFO_FILE',__DIR__ . '/node_info.txt');

$isCli = (php_sapi_name() === 'cli');

// ── output helpers ────────────────────────────────────────────────────────────

function out(string $msg, string $type = 'info'): void
{
    global $isCli;
    if ($isCli) {
        $prefix = ['info' => '  ', 'ok' => '✓ ', 'fail' => '✗ ', 'head' => "\n► "];
        echo ($prefix[$type] ?? '  ') . $msg . "\n";
    } else {
        $colors = ['ok' => '#1a8e3f', 'fail' => '#cc0000', 'head' => '#0044cc', 'info' => '#555'];
        $color  = $colors[$type] ?? '#333';
        $bold   = in_array($type, ['head'], true) ? 'font-weight:700;font-size:1.05em;' : '';
        echo "<div style='color:{$color};{$bold}font-family:monospace;margin:2px 0'>"
           . htmlspecialchars($msg) . "</div>\n";
        if (ob_get_level()) { ob_flush(); flush(); }
    }
}

// ── readers ───────────────────────────────────────────────────────────────────

function readOptionalFile(string $path): string
{
    return file_exists($path) ? trim((string) file_get_contents($path)) : '';
}

function loadServers(): array
{
    if (!file_exists(SERVERS_FILE)) return [];
    $lines = file(SERVERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('#^https?://#i', $line)) $line = 'http://' . $line;
        $out[] = rtrim($line, '/');
    }
    return array_unique($out);
}

/**
 * List every file in /files (non-recursively).
 * Returns an array of absolute paths.
 */
function listFiles(): array
{
    if (!is_dir(FILES_DIR)) return [];
    $result = [];
    foreach (scandir(FILES_DIR) as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = FILES_DIR . '/' . $name;
        if (is_file($full)) $result[] = $full;
    }
    return $result;
}

/**
 * List every .json file in /info.
 * Returns an array of absolute paths.
 */
function listInfoJson(): array
{
    if (!is_dir(INFO_DIR)) return [];
    $result = [];
    foreach (scandir(INFO_DIR) as $name) {
        if (substr(strtolower($name), -5) !== '.json') continue;
        $full = INFO_DIR . '/' . $name;
        if (is_file($full)) $result[] = $full;
    }
    return $result;
}

// ── push helpers ──────────────────────────────────────────────────────────────

/**
 * Push one regular file (from /files) to a remote peer.
 * Reads the matching info JSON (if present) to forward metadata.
 */
function pushFileToServer(
    string $server,
    string $filePath,
    string $publicKey,
    string $nodeInfo
): array {
    $filename = basename($filePath);
    $hash     = pathinfo($filename, PATHINFO_FILENAME);   // sha256 part before .ext

    // Try to load metadata from the matching info/<hash>.json
    $infoPath = INFO_DIR . '/' . $hash . '.json';
    $meta     = [];
    if (file_exists($infoPath)) {
        $decoded = json_decode((string) file_get_contents($infoPath), true);
        if (is_array($decoded)) $meta = $decoded;
    }

    $originalName = $meta['original_filename'] ?? $filename;
    $description  = $meta['description']        ?? '';
    $peerPk       = $meta['public_key']          ?? $publicKey;
    $peerNi       = $meta['node_info']           ?? $nodeInfo;

    return pushMultipart($server, $filePath, $originalName, $description, $peerPk, $peerNi);
}

/**
 * Push one info JSON file (from /info) to a remote peer via the
 * ?receive_info=1 endpoint.  The receiving node may use it to rebuild
 * its own data.json / files.txt without the binary.
 */
function pushInfoToServer(string $server, string $jsonPath): array
{
    $url = $server . '/' . basename(__FILE__, '.php') . '/receive_info';

    // We send the JSON content as the POST body.
    $payload = (string) file_get_contents($jsonPath);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'X-Mesh-Sync: 1',
            ]),
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    // The remote may not implement this endpoint — that is fine; we just report it.
    if ($raw === false) {
        return ['ok' => false, 'server' => $server, 'error' => 'Connection failed or endpoint absent.'];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded)
        ? array_merge($decoded, ['server' => $server])
        : ['ok' => true, 'server' => $server, 'raw' => substr($raw, 0, 80)];
}

/**
 * Low-level multipart POST — identical in structure to the original pushToServer().
 */
function pushMultipart(
    string $server,
    string $filePath,
    string $filename,
    string $description,
    string $publicKey,
    string $nodeInfo
): array {
    $boundary = '----MeshBoundary' . bin2hex(random_bytes(12));
    $body = '';

    $mime  = @mime_content_type($filePath) ?: 'application/octet-stream';
    $body .= "--{$boundary}\r\n";
    $body .= 'Content-Disposition: form-data; name="file"; filename="' . addslashes($filename) . "\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= file_get_contents($filePath) . "\r\n";

    foreach ([
        'description' => $description,
        'public_key'  => $publicKey,
        'node_info'   => $nodeInfo,
        'peer_push'   => '1',
    ] as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= $value . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    $url = $server . '/index.php';

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                "Content-Length: " . strlen($body),
                "X-Mesh-Node: 1",
            ]),
            'content'       => $body,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'server' => $server, 'error' => 'Connection failed.'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'server' => $server, 'error' => 'Bad response: ' . substr($raw, 0, 120)];
    }
    return array_merge($decoded, ['server' => $server]);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════════════════════════════════════════════

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='utf-8'>"
       . "<title>Mesh Node · Sync</title>"
       . "<style>body{font-family:system-ui,sans-serif;background:#fff;padding:2rem 2rem}"
       . "h1{font-size:1.3rem;margin-bottom:1rem}div{line-height:1.8}</style>"
       . "</head><body><h1>⚡ Mesh Node — Sync</h1>\n";
    if (ob_get_level()) { ob_flush(); flush(); }
}

// ── collect servers ────────────────────────────────────────────────────────────
$servers = loadServers();
out('Servers found: ' . count($servers), 'head');
foreach ($servers as $s) out($s);

if (empty($servers)) {
    out('No servers in servers.txt — nothing to do.', 'fail');
    if (!$isCli) echo "</body></html>";
    exit(0);
}

// ── collect files ──────────────────────────────────────────────────────────────
$files    = listFiles();
$infoJson = listInfoJson();

out('Files in /files: ' . count($files), 'head');
foreach ($files as $f) out(basename($f));

out('JSON files in /info: ' . count($infoJson), 'head');
foreach ($infoJson as $j) out(basename($j));

if (empty($files) && empty($infoJson)) {
    out('Nothing to sync.', 'info');
    if (!$isCli) echo "</body></html>";
    exit(0);
}

// ── own metadata ──────────────────────────────────────────────────────────────
$publicKey = readOptionalFile(PUB_KEY_FILE);
$nodeInfo  = readOptionalFile(NODE_INFO_FILE);

// ── push files ────────────────────────────────────────────────────────────────
if (!empty($files)) {
    out('Pushing ' . count($files) . ' file(s) to ' . count($servers) . ' server(s)…', 'head');

    foreach ($files as $filePath) {
        $name = basename($filePath);
        out("── {$name}", 'info');

        foreach ($servers as $server) {
            $result = pushFileToServer($server, $filePath, $publicKey, $nodeInfo);
            if (!empty($result['ok'])) {
                $note = !empty($result['existed']) ? ' (already there)' : ' (stored)';
                out("  {$server}{$note}", 'ok');
            } else {
                $err = $result['error'] ?? 'Unknown error';
                out("  {$server} — {$err}", 'fail');
            }
        }
    }
}

// ── push info JSONs ───────────────────────────────────────────────────────────
if (!empty($infoJson)) {
    out('Pushing ' . count($infoJson) . ' info JSON(s) to ' . count($servers) . ' server(s)…', 'head');

    foreach ($infoJson as $jsonPath) {
        $name = basename($jsonPath);
        out("── {$name}", 'info');

        foreach ($servers as $server) {
            $result = pushInfoToServer($server, $jsonPath);
            if (!empty($result['ok'])) {
                out("  {$server} — delivered", 'ok');
            } else {
                $err = $result['error'] ?? ($result['raw'] ?? 'Unknown error');
                out("  {$server} — {$err}", 'fail');
            }
        }
    }
}

// ── done ──────────────────────────────────────────────────────────────────────
out('Sync complete.', 'head');

if (!$isCli) echo "</body></html>";
exit(0);