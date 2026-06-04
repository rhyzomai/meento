<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  MESH NODE — file replication over HTTP/HTTPS
//  Single-file PHP 7+ application
// ═══════════════════════════════════════════════════════════════════════════════

//Remove this line to avoid creating duplicate files in the 'users' directory.
require __DIR__ . '/user_files.php';

define('FILES_DIR',     __DIR__ . '/files');
define('INFO_DIR',      __DIR__ . '/info');
define('FILES_INDEX',   __DIR__ . '/files.txt');
define('SERVERS_FILE',  __DIR__ . '/servers.txt');
define('PUB_KEY_FILE',  __DIR__ . '/public_key.txt');
define('NODE_INFO_FILE',__DIR__ . '/node_info.txt');
define('MAX_COMMENT',   1 * 1024);   // 1 KB
define('BLOCKED_EXT',   ['php','php3','php4','php5','php7','phtml','phar']);

// ── shared log writer ─────────────────────────────────────────────────────────

/**
 * Append the info record for $hash into the root data.json array (no erase),
 * and ensure the basename is in files.txt.
 * Entirely silent — never leaks output or exceptions.
 */
function appendFilesAndDataJson(string $hash): void
{
    ob_start();
    $prev = error_reporting(0);
    try {
        $infoPath = INFO_DIR . '/' . $hash . '.json';
        $raw      = @file_get_contents($infoPath);
        if ($raw === false) return;

        $info = json_decode($raw, true);
        if (!is_array($info)) return;

        // -- files.txt: ensure the filename line is present --
        $ext      = $info['extension'] ?? '';
        $baseName = $ext !== '' ? "{$hash}.{$ext}" : $hash;
        appendIndex($baseName);   // already deduplicates

        // -- data.json: append entry to JSON array --
        $entry    = array_merge(['hash' => $hash], $info);
        $dataJson = __DIR__ . '/data.json';

        if (!file_exists($dataJson)) {
            file_put_contents($dataJson, "[\n]", LOCK_EX);
        }

        $fp = @fopen($dataJson, 'c+');
        if ($fp === false) return;

        if (!flock($fp, LOCK_EX)) { fclose($fp); return; }

        $content    = rtrim((string) stream_get_contents($fp));
        $closingPos = strrpos($content, ']');
        $encoded    = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $indented   = implode("\n", array_map(
            static fn(string $l) => '    ' . $l,
            explode("\n", $encoded)
        ));

        if ($closingPos === false) {
            $newContent = "[\n" . $indented . "\n]";
        } elseif (trim(substr($content, 1, $closingPos - 1)) === '') {
            $newContent = "[\n" . $indented . "\n]";
        } else {
            $newContent = rtrim(substr($content, 0, $closingPos)) . ",\n" . $indented . "\n]";
        }

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $newContent);
        flock($fp, LOCK_UN);
        fclose($fp);
    } catch (\Throwable $e) {
        // silently discard
    } finally {
        error_reporting($prev);
        ob_end_clean();
    }
}

// ── bootstrap ─────────────────────────────────────────────────────────────────
foreach ([FILES_DIR, INFO_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}
if (!file_exists(FILES_INDEX)) file_put_contents(FILES_INDEX, '');

// ── helpers ───────────────────────────────────────────────────────────────────

function blockedExt(string $ext): bool {
    return in_array(strtolower($ext), BLOCKED_EXT, true);
}

function safeExt(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return preg_replace('/[^a-z0-9]/', '', $ext);
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Returns true if a filename (basename) is protected and must never be written. */
function isProtectedFile(string $filename): bool {
    $base = strtolower(basename($filename));
    return in_array($base, ['files.txt', 'data.json'], true);
}

function appendIndex(string $entry): void {
    // Safety: never write to protected files
    //if (isProtectedFile(FILES_INDEX)) return;

    $lines = file_exists(FILES_INDEX)
        ? array_map('trim', file(FILES_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        : [];
    if (!in_array($entry, $lines, true)) {
        file_put_contents(FILES_INDEX, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function loadServers(): array {
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

/** Read a local text file; return trimmed content or empty string if absent. */
function readOptionalFile(string $path): string {
    return file_exists($path) ? trim((string) file_get_contents($path)) : '';
}

/**
 * Store a file locally and write its structured info JSON.
 *
 * The info JSON always contains exactly these fields:
 *   original_filename, size, extension, public_key, node_info, description
 *
 * - $publicKey / $nodeInfo  : from own txt files (on upload) or forwarded by peer
 * - $description            : optional user comment; stored as empty string when absent
 *
 * Never overwrites an existing file or info record.
 *
 * Returns ['ok'=>true,  'hash'=>..., 'filename'=>..., 'existed'=>bool]
 *      or ['ok'=>false, 'error'=>...].
 */
function storeLocally(
    string $tmpPath,
    string $originalName,
    string $description,
    string $publicKey,
    string $nodeInfo
): array {
    $ext      = safeExt($originalName);
    if (blockedExt($ext)) return ['ok' => false, 'error' => 'PHP files are not accepted.'];

    $hash     = hash_file('sha256', $tmpPath);
    $baseName = $ext !== '' ? "{$hash}.{$ext}" : $hash;
    $destFile = FILES_DIR . '/' . $baseName;
    $destInfo = INFO_DIR  . '/' . $hash . '.json';

    $existed = file_exists($destFile);

    // ── save file only if new ──
    if (!$existed) {
        if (!copy($tmpPath, $destFile)) {
            return ['ok' => false, 'error' => 'Could not write file to disk.'];
        }
        appendIndex($baseName);
    }

    // ── save info JSON only if new — never overwrite ──
    if (!file_exists($destInfo)) {
        $info = [
            'original_filename' => $originalName,
            'size'              => (int) filesize($destFile),
            'extension'         => $ext,
            'public_key'        => $publicKey,
            'node_info'         => $nodeInfo,
            'description'       => $description,  // empty string if not supplied
        ];
        file_put_contents(
            $destInfo,
            json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    return ['ok' => true, 'hash' => $hash, 'filename' => $baseName, 'existed' => $existed];
}

/**
 * Push a file + metadata fields to one remote peer via multipart/form-data POST.
 * Uses only built-in PHP stream functions — no cURL, no external libraries.
 */
function pushToServer(
    string $server,
    string $filePath,
    string $filename,
    string $description,
    string $publicKey,
    string $nodeInfo
): array {
    $boundary = '----MeshBoundary' . bin2hex(random_bytes(12));
    $body = '';

    // ── file field ──
    $mime  = @mime_content_type($filePath) ?: 'application/octet-stream';
    $body .= "--{$boundary}\r\n";
    $body .= 'Content-Disposition: form-data; name="file"; filename="' . addslashes($filename) . "\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= file_get_contents($filePath) . "\r\n";

    // ── text fields ──
    $fields = [
        'description' => $description,
        'public_key'  => $publicKey,
        'node_info'   => $nodeInfo,
        'peer_push'   => '1',
    ];
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= $value . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    $url = $server . '/' . basename(__FILE__);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                "Content-Length: " . strlen($body),
                "X-Mesh-Node: 1",
            ]),
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
        'ssl'  => [
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
//  RECEIVE ENDPOINT  —  POST from a peer node  (peer_push = 1)
// ═══════════════════════════════════════════════════════════════════════════════
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_FILES['file'], $_POST['peer_push']) &&
    $_POST['peer_push'] === '1'
) {
    $upload = $_FILES['file'];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['ok' => false, 'error' => 'Upload error ' . $upload['error']], 400);
    }

    if (blockedExt(safeExt($upload['name']))) {
        jsonOut(['ok' => false, 'error' => 'PHP files are not accepted.'], 400);
    }

    $description = isset($_POST['description']) ? substr(trim($_POST['description']), 0, MAX_COMMENT) : '';
    $publicKey   = isset($_POST['public_key'])   ? trim($_POST['public_key'])  : '';
    $nodeInfo    = isset($_POST['node_info'])     ? trim($_POST['node_info'])   : '';

    $result = storeLocally($upload['tmp_name'], $upload['name'], $description, $publicKey, $nodeInfo);

    // If this file is new on this node, write files.txt + data.json (same as the upload endpoint)
    if ($result['ok'] && !$result['existed']) {
        appendFilesAndDataJson($result['hash']);
    }

    jsonOut($result, $result['ok'] ? 200 : 500);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  UPLOAD ENDPOINT  —  POST from the browser  (stores locally + propagates)
// ═══════════════════════════════════════════════════════════════════════════════
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_FILES['file']) &&
    !isset($_POST['peer_push'])
) {
    $upload = $_FILES['file'];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['ok' => false, 'error' => 'Upload error ' . $upload['error']], 400);
    }

    if (blockedExt(safeExt($upload['name']))) {
        jsonOut(['ok' => false, 'error' => 'PHP files are not accepted.'], 400);
    }

    $description = isset($_POST['comment']) ? substr(trim($_POST['comment']), 0, MAX_COMMENT) : '';

    // Read own public_key.txt / node_info.txt
    $publicKey = readOptionalFile(PUB_KEY_FILE);
    // If file is absent or empty, accept a value submitted by the browser (max 512 chars)
    if ($publicKey === '' && isset($_POST['public_key'])) {
        $publicKey = substr(trim($_POST['public_key']), 0, 512);
    }
    $nodeInfo  = readOptionalFile(NODE_INFO_FILE);

    // 1. Store locally
    $local = storeLocally($upload['tmp_name'], $upload['name'], $description, $publicKey, $nodeInfo);

    if (!$local['ok']) jsonOut($local, 500);

    // If file already existed, return success with the link — do not re-propagate or re-log
    if ($local['existed']) {
        jsonOut([
            'ok'       => true,
            'hash'     => $local['hash'],
            'filename' => $local['filename'],
            'existed'  => true,
            'public_key' => $publicKey !== '' ? '✓ included' : '— not found',
            'node_info'  => $nodeInfo  !== '' ? '✓ included' : '— not found',
            'peers'      => [],
        ]);
    }

    // Write files.txt + data.json for genuinely new files
    appendFilesAndDataJson($local['hash']);

    // 2. Respond to the browser FIRST (link appears immediately), then push to peers
    $servers    = loadServers();
    $storedPath = FILES_DIR . '/' . $local['filename'];

    $responsePayload = json_encode([
        'ok'          => true,
        'hash'        => $local['hash'],
        'filename'    => $local['filename'],
        'existed'     => $local['existed'],
        'public_key'  => $publicKey !== '' ? '✓ included' : '— not found',
        'node_info'   => $nodeInfo  !== '' ? '✓ included' : '— not found',
        'peers_total' => count($servers),
        'peers'       => [],   // peer results not sent — browser ignores them anyway
    ], JSON_UNESCAPED_UNICODE);

    http_response_code(200);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($responsePayload));
    header('Connection: close');
    echo $responsePayload;

    // Flush output to browser so the XHR resolves now
    if (ob_get_level()) ob_end_flush();
    flush();

    // Push to peers in the background (browser already got its response)
    ignore_user_abort(true);
    foreach ($servers as $server) {
        pushToServer($server, $storedPath, $upload['name'], $description, $publicKey, $nodeInfo);
    }
    exit;
}

// ── lightweight JSON endpoints for the UI ─────────────────────────────────────
if (isset($_GET['node_status'])) {
    $pkContent = readOptionalFile(PUB_KEY_FILE);
    jsonOut([
        'public_key' => $pkContent !== '',
        'node_info'  => file_exists(NODE_INFO_FILE) && readOptionalFile(NODE_INFO_FILE) !== '',
        'peers'      => count(loadServers()),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  HTML FRONT-END (minimalist)
// ═══════════════════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mesh Node</title>
<style>
/* –– minimalist reset –– */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}

/* –– container –– */
.page{max-width:36rem;width:100%}

/* –– header –– */
header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}
h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.sub{font-size:0.8rem;color:#777;margin-top:0.2rem}
.search-link{font-size:0.85rem;color:#0044cc;text-decoration:none;white-space:nowrap}
.search-link:hover{text-decoration:underline}

/* –– nav menu –– */
.head-nav {display:flex;gap:1rem;align-items:center;}
.head-nav a {font-size:0.85rem;color:#0044cc;text-decoration:none;font-weight:500;}
.head-nav a:hover {text-decoration:underline;}

/* –– node status bar –– */
#node-bar{display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.75rem;font-size:0.8rem;color:#555}
.nb-pill{display:flex;align-items:center;gap:0.35rem;padding:0.25rem 0.6rem;background:#f5f5f5;border-radius:4px;border:1px solid #e0e0e0}
.nb-dot{width:7px;height:7px;border-radius:50%;background:#aaa}
.nb-dot.ok{background:#1a8e3f}
.nb-dot.blue{background:#0044cc}
.nb-val{font-weight:500}

/* –– drop zone –– */
#drop-zone{border:2px dashed #ccc;border-radius:6px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s}
#drop-zone:hover,#drop-zone:focus{background:#fafafa;border-color:#999}
#drop-zone.over{border-color:#0044cc;background:#f0f4ff}
.dz-icon{margin-bottom:0.75rem;font-size:1.8rem;color:#777}
.dz-title{font-size:1rem;font-weight:600;margin-bottom:0.3rem}
.dz-sub{font-size:0.8rem;color:#666;line-height:1.5}
.dz-sub b{color:#0044cc;font-weight:600}
#file-input{display:none}

/* –– upload panel –– */
#panel{display:none;margin-top:1.25rem;border:1px solid #ddd;border-radius:6px;overflow:hidden}
.panel-top{display:flex;align-items:center;gap:0.8rem;padding:0.8rem 1rem;border-bottom:1px solid #eee}
.f-icon{width:2rem;height:2rem;background:#f0f4ff;border-radius:4px;display:grid;place-items:center;flex-shrink:0;font-size:1rem;color:#0044cc}
#f-name{font-size:0.9rem;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#f-size{font-size:0.8rem;color:#777;flex-shrink:0}
.x-btn{background:none;border:none;cursor:pointer;font-size:1.2rem;color:#999;padding:0 0.2rem;transition:color 0.15s}
.x-btn:hover{color:#d00}

/* –– metadata pills –– */
.meta-pills{padding:0.5rem 1rem;display:flex;gap:0.5rem;flex-wrap:wrap;border-bottom:1px solid #eee;background:#fafafa}
.mpill{font-size:0.75rem;padding:0.2rem 0.6rem;border-radius:4px;border:1px solid #ddd;color:#555;background:#fff;display:flex;align-items:center;gap:0.3rem}
.mpill.has{border-color:#1a8e3f;color:#1a8e3f;background:#f0fff0}
.mpill svg{width:0.9rem;height:0.9rem;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}

/* –– description –– */
.panel-mid{padding:1rem}
.fl{display:block;font-size:0.75rem;color:#777;margin-bottom:0.5rem}
textarea#comment-box{width:100%;min-height:5rem;padding:0.6rem 0.8rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;resize:vertical;outline:none;transition:border-color 0.2s}
textarea#comment-box:focus{border-color:#0044cc}
.cmeta{display:flex;justify-content:space-between;margin-top:0.4rem;font-size:0.75rem;color:#999}
.ccount.over{color:#d00}

/* –– panel footer –– */
.panel-bot{padding:0.8rem 1rem;border-top:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:0.8rem}
.peer-info{font-size:0.8rem;color:#555}
.peer-info b{font-weight:600}
.btn-send{padding:0.5rem 1.2rem;background:#0044cc;color:#fff;border:none;border-radius:4px;font-weight:600;font-size:0.85rem;cursor:pointer;transition:opacity 0.2s;display:flex;align-items:center;gap:0.4rem}
.btn-send:hover{opacity:0.9}
.btn-send.busy{opacity:0.5;pointer-events:none}
.btn-send svg{width:1rem;height:1rem;stroke:#fff;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* –– progress bar –– */
#prog-wrap{height:3px;background:#eee;border-radius:3px;overflow:hidden;display:none;margin-top:1rem}
#prog-bar{height:100%;width:0%;background:#0044cc;transition:width 0.1s linear}

/* –– status message –– */
#status{display:none;margin-top:1rem;padding:0.8rem 1rem;border-radius:4px;font-size:0.9rem;line-height:1.5;animation:fadeUp 0.25s ease}
#status.ok{background:#f0fff0;border:1px solid #c0e0c0;color:#1a8e3f}
#status.fail{background:#fff0f0;border:1px solid #e0c0c0;color:#d00}
.hash-line{margin-top:0.6rem}
.file-link{display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;color:#0044cc;border:1px solid #0044cc;border-radius:4px;transition:background 0.15s}
.file-link:hover{background:#f0f4ff}
.file-link svg{width:0.9rem;height:0.9rem;stroke:currentColor;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* –– info box –– */
.json-preview{margin-top:2rem;border:1px solid #ddd;border-radius:4px;overflow:hidden}
.json-preview-head{padding:0.5rem 1rem;border-bottom:1px solid #eee;font-size:0.75rem;color:#777;background:#fafafa;display:flex;align-items:center;gap:0.4rem}
.json-preview-head svg{width:0.9rem;height:0.9rem;stroke:#777;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
pre#json-schema{padding:1rem;font-family:"SF Mono","Fira Code","Fira Mono","Roboto Mono",monospace;font-size:0.8rem;line-height:1.6;color:#333;overflow-x:auto;margin:0}
.jk{color:#0044cc} .js{color:#1a8e3f} .jn{color:#b04000} .jb{color:#d00}

/* –– footer –– */
footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}

@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="page">

<header>
  <div>
    <h1>Mesh <em>Node</em></h1>
    <p class="sub">Peer replication · SHA-256 storage</p>
  </div>
  <nav class="head-nav">
    <a href="index.php">Upload</a>
    <a href="view.php">Search</a>
    <a href="user_files.php">Users</a>
    <a href="servers.php">Servers</a>
    <a href="panel.php">Panel</a>
    <a href="sync.php">Sync</a>
    <a href="dashboard.php">Dashboard</a>
    <a href="indexer.php">Indexer</a> 
    <a href="feed_full.php">Feed</a>
    <a href="index_full.php">Account</a>
  </nav>
</header>

<!-- Node status bar -->
<div id="node-bar">
  <div class="nb-pill">
    <span class="nb-dot blue"></span>
    peers <span class="nb-val" id="nb-peers">…</span>
  </div>
  <div class="nb-pill">
    <span class="nb-dot" id="nb-pk-dot"></span>
    public_key.txt <span class="nb-val" id="nb-pk">…</span>
  </div>
  <div class="nb-pill">
    <span class="nb-dot" id="nb-ni-dot"></span>
    node_info.txt <span class="nb-val" id="nb-ni">…</span>
  </div>
</div>

<!-- Drop zone -->
<div id="drop-zone" tabindex="0" role="button" aria-label="Select or drop a file">
  <div class="dz-icon">☁️</div>
  <p class="dz-title">Drop a file or click to select</p>
  <p class="dz-sub">Stored as <b>sha256.ext</b> · pushed to all peers · <b>.php</b> blocked</p>
</div>
<input type="file" id="file-input">

<!-- Upload panel (shown after file selection) -->
<div id="panel">
  <div class="panel-top">
    <div class="f-icon">📄</div>
    <span id="f-name">—</span>
    <span id="f-size"></span>
    <button class="x-btn" id="x-btn" aria-label="Remove file">✕</button>
  </div>

  <div class="meta-pills">
    <div class="mpill" id="mpill-pk">
      <svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
      public_key
    </div>
    <input type="text" id="pk-inline" maxlength="512" placeholder="public key…" style="display:none;flex:1;min-width:0;padding:0.2rem 0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.78rem;outline:none;color:#333;background:#fff;transition:border-color 0.2s" onfocus="this.style.borderColor='#0044cc'" onblur="this.style.borderColor='#ccc'">
    <div class="mpill" id="mpill-ni">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      node_info
    </div>
    <div class="mpill has">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      filename · size · extension
    </div>
  </div>

  <div class="panel-mid">
    <label class="fl" for="comment-box">Description <span style="color:#aaa">(optional · max 1 KB)</span></label>
    <textarea id="comment-box" placeholder="Add an optional description for this file…"></textarea>
    <div class="cmeta">
      <span>Stored as “description” field</span>
      <span class="ccount" id="ccount">0 / 1 000</span>
    </div>
  </div>

  <div class="panel-bot">
    <span class="peer-info">Sending to <b id="peer-count">…</b> peer(s)</span>
    <button class="btn-send" id="send-btn">
      <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      Send to network
    </button>
  </div>
</div>

<div id="prog-wrap"><div id="prog-bar"></div></div>
<div id="status"></div>

<!-- JSON schema preview -->
<div class="json-preview">
  <div class="json-preview-head">
    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    info/&lt;hash&gt;.json — stored structure
  </div>
  <pre id="json-schema">{
  <span class="jk">"original_filename"</span>: <span class="js">"photo.jpg"</span>,
  <span class="jk">"size"</span>:              <span class="jn">204800</span>,
  <span class="jk">"extension"</span>:        <span class="js">"jpg"</span>,
  <span class="jk">"public_key"</span>:       <span class="js">"&lt;contents of public_key.txt or empty string&gt;"</span>,
  <span class="jk">"node_info"</span>:        <span class="js">"&lt;contents of node_info.txt or empty string&gt;"</span>,
  <span class="jk">"description"</span>:      <span class="js">"&lt;user comment or empty string&gt;"</span>
}</pre>
</div>

</div><!-- .page -->

<footer class="page">
  <p>Mesh Node · files stored as sha256.ext · info JSON has 6 fixed fields</p>
  <p>PHP <?php echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION; ?></p>
</footer>

<script>
(function(){
'use strict';

const dz       = document.getElementById('drop-zone');
const fi       = document.getElementById('file-input');
const panel    = document.getElementById('panel');
const fName    = document.getElementById('f-name');
const fSize    = document.getElementById('f-size');
const xBtn     = document.getElementById('x-btn');
const commentB = document.getElementById('comment-box');
const ccount   = document.getElementById('ccount');
const sendBtn  = document.getElementById('send-btn');
const peerCnt  = document.getElementById('peer-count');
const status   = document.getElementById('status');
const progWrap = document.getElementById('prog-wrap');
const progBar  = document.getElementById('prog-bar');
const mpillPk  = document.getElementById('mpill-pk');
const mpillNi  = document.getElementById('mpill-ni');
const pkInline = document.getElementById('pk-inline');

const MAX     = 1000;  // bytes (1 KB) – comment limit matches server MAX_COMMENT
const BLOCKED = ['php','php3','php4','php5','php7','phtml','phar'];
let file = null;
let ns   = { public_key: false, node_info: false, peers: 0 };

// ── fetch node status ──────────────────────────────────────────────────────────
fetch(window.location.href + '?node_status=1')
  .then(r => r.json())
  .then(d => {
    ns = d;
    document.getElementById('nb-peers').textContent = d.peers;
    peerCnt.textContent = d.peers;

    const dot = (id, ok) => {
      const el = document.getElementById(id);
      if(el) el.className = 'nb-dot ' + (ok ? 'ok' : '');
    };
    dot('nb-pk-dot', d.public_key);
    dot('nb-ni-dot', d.node_info);
    document.getElementById('nb-pk').textContent = d.public_key ? '✓ found' : '— missing';
    document.getElementById('nb-ni').textContent = d.node_info  ? '✓ found' : '— missing';
    updatePills();
  })
  .catch(() => {
    ['nb-peers','nb-pk','nb-ni'].forEach(id => {
      const el = document.getElementById(id); if(el) el.textContent='?';
    });
    peerCnt.textContent = '?';
  });

function updatePills(){
  const hasPk = ns.public_key;
  mpillPk.style.display = hasPk ? '' : 'none';
  pkInline.style.display = hasPk ? 'none' : '';
  mpillNi.className = 'mpill ' + (ns.node_info  ? 'has' : '');
}

function fmt(b){
  if(b<1024) return b+' B';
  if(b<1048576) return (b/1024).toFixed(1)+' KB';
  return (b/1048576).toFixed(2)+' MB';
}

function extOf(n){ return n.split('.').pop().toLowerCase(); }

function setFile(f){
  if(!f) return;
  if(BLOCKED.includes(extOf(f.name))){ show('fail','PHP files are not accepted.'); return; }
  file = f;
  fName.textContent = f.name;
  fSize.textContent = fmt(f.size);
  panel.style.display = 'block';
  commentB.value = '';
  updateCount();
  updatePills();
  hide();
}

function clear(){
  file = null; fi.value = '';
  panel.style.display = 'none';
  commentB.value = '';
  pkInline.value = '';
  //hide();
}

dz.addEventListener('click', () => fi.click());
dz.addEventListener('keydown', e => { if(e.key==='Enter'||e.key===' ') fi.click(); });
fi.addEventListener('change', () => { if(fi.files[0]) setFile(fi.files[0]); });
xBtn.addEventListener('click', clear);
//xBtn.addEventListener('click', () => { clear(); hide(); });

['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('over'); }));
dz.addEventListener('drop', e => { if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });

function updateCount(){
  const len = new TextEncoder().encode(commentB.value).length;
  ccount.textContent = len.toLocaleString()+' / '+MAX.toLocaleString();
  ccount.classList.toggle('over', len > MAX);
}
commentB.addEventListener('input', updateCount);

function show(type, html){ status.className=type; status.innerHTML=html; status.style.display='block'; }
function hide(){ status.style.display='none'; }

sendBtn.addEventListener('click', () => {
  if(!file) return;
  if(new TextEncoder().encode(commentB.value).length > MAX){
    show('fail','Description exceeds the 1 KB limit.'); return;
  }

  const fd = new FormData();
  fd.append('file', file);
  fd.append('comment', commentB.value);
  if (!ns.public_key && pkInline.value.trim() !== '') {
    fd.append('public_key', pkInline.value.trim().slice(0, 512));
  }

  const xhr = new XMLHttpRequest();
  sendBtn.classList.add('busy');
  sendBtn.lastChild.textContent = ' Transmitting…';
  progWrap.style.display = 'block';
  progBar.style.width = '0%';
  hide();

  xhr.upload.addEventListener('progress', e => {
    if(e.lengthComputable) progBar.style.width = Math.round(e.loaded/e.total*100)+'%';
  });

  xhr.addEventListener('load', () => {
    sendBtn.classList.remove('busy');
    sendBtn.lastChild.textContent = ' Send to network';
    progBar.style.width = '100%';
    setTimeout(() => { progWrap.style.display='none'; progBar.style.width='0%'; }, 700);

    let res;
    try {
      const raw = xhr.responseText.replace(/^[\s\S]*?(\{)/, '$1');
      res = JSON.parse(raw);
    } catch(e){
      const m = xhr.responseText.match(/"filename"\s*:\s*"([^"]+)"/);
      if(m){
        showLink(m[1], false);
        clear(); return;
      }
      show('fail','Unexpected server response.'); return;
    }
    if(!res.ok){ show('fail','✗ '+(res.error||'Unknown error')); return; }

    showLink(res.filename, !!res.existed);
    clear();
  });

  xhr.addEventListener('error', () => {
    sendBtn.classList.remove('busy');
    sendBtn.lastChild.textContent = ' Send to network';
    show('fail','✗ Network error.'); progWrap.style.display='none';
  });

  xhr.open('POST', window.location.href);
  xhr.send(fd);
});

function showLink(filename, existed){
  const fileUrl = 'files/' + esc(filename);
  const note    = existed ? ' <span style="opacity:0.6">(already stored)</span>' : '';
  show('ok',
    '✓ File stored' + note +
    '<div class="hash-line">' +
      '<a class="file-link" href="' + fileUrl + '" target="_blank" rel="noopener noreferrer">' +
        '<svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
        'Open file in new tab' +
      '</a>' +
    '</div>'
  );
}

function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

})();
</script>
</body>
</html>