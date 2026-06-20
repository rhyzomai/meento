<?php declare(strict_types=1);

// -------------------------------------------------------------------------------
//  Meento Share — file replication over HTTP/HTTPS
//  Single-file PHP 7+ application
// -------------------------------------------------------------------------------

// Remove this line to avoid creating duplicate files in the 'users' directory.
@include_once __DIR__ . '/user_files.php';

class MeshConfig
{
    public const DIR_FILES       = __DIR__ . '/files';
    public const DIR_INFO        = __DIR__ . '/info';
    public const FILE_INDEX      = __DIR__ . '/files.txt';
    public const FILE_DATA_JSON  = __DIR__ . '/data.json';
    public const FILE_SERVERS    = __DIR__ . '/servers.txt';
    public const FILE_PUB_KEY    = __DIR__ . '/public_key.txt';
    public const FILE_NODE_INFO  = __DIR__ . '/node_info.txt';

    public const MAX_FILE_SIZE   = 1073741824; // 1 GB in bytes
    public const MAX_TEXT_LEN    = 512;        // Characters
    public const BLOCKED_EXT     = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar'];
}

class MeshNode
{
    public static function bootstrap(): void
    {
        foreach ([MeshConfig::DIR_FILES, MeshConfig::DIR_INFO] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        if (!file_exists(MeshConfig::FILE_INDEX)) {
            file_put_contents(MeshConfig::FILE_INDEX, '');
        }
    }

    public static function handleRequest(): void
    {
        if (isset($_GET['node_status'])) {
            self::handleStatusRequest();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
            $isPeerPush = isset($_POST['peer_push']) && $_POST['peer_push'] === '1';
            self::handleUpload($_FILES['file'], $_POST, $isPeerPush);
        }
    }

    private static function handleStatusRequest(): void
    {
        self::jsonOut([
            'public_key' => self::readOptionalFile(MeshConfig::FILE_PUB_KEY) !== '',
            'node_info'  => self::readOptionalFile(MeshConfig::FILE_NODE_INFO) !== '',
            'peers'      => count(self::loadServers()),
        ]);
    }

    private static function handleUpload(array $upload, array $postData, bool $isPeerPush): void
    {
        // 1. Validate Upload Errors
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $msg = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE)
                ? 'File exceeds server configuration size limits.'
                : 'Upload error code: ' . $upload['error'];
            self::jsonOut(['ok' => false, 'error' => $msg], 400);
        }

        // 2. Validate File Size limit
        if ($upload['size'] > MeshConfig::MAX_FILE_SIZE) {
            self::jsonOut(['ok' => false, 'error' => 'File exceeds the 1GB size limit.'], 400);
        }

        // 3. Validate Extension
        $ext = self::safeExt($upload['name']);
        if (in_array($ext, MeshConfig::BLOCKED_EXT, true)) {
            self::jsonOut(['ok' => false, 'error' => 'PHP files are strictly blocked.'], 400);
        }

        // 4. Sanitize Input Fields
        $description = self::sanitize($postData['description'] ?? $postData['comment'] ?? '');
        $userPubKey  = self::sanitize($postData['public_key'] ?? '');
        $nodeInfo    = self::sanitize($postData['node_info'] ?? self::readOptionalFile(MeshConfig::FILE_NODE_INFO));

        // As per spec: server_public_key is ALWAYS loaded from local file.
        $serverPubKey = self::sanitize(self::readOptionalFile(MeshConfig::FILE_PUB_KEY));

        // 5. Store Locally
        $local = self::storeLocally(
            $upload['tmp_name'],
            $upload['name'],
            $description,
            $userPubKey,
            $serverPubKey,
            $nodeInfo
        );

        if (!$local['ok']) {
            self::jsonOut($local, 500);
        }

        // 6. Handle successful local storage
        if (!$local['existed']) {
            self::appendFilesAndDataJson($local['hash']);
        }

        if ($isPeerPush) {
            // Peer push completes here.
            self::jsonOut($local, 200);
        }

        // 7. Browser Request: Respond immediately, then background propagate
        $servers = self::loadServers();
        $storedPath = MeshConfig::DIR_FILES . '/' . $local['filename'];

        $responsePayload = json_encode(array_merge($local, [
            'peers_total' => count($servers),
            'peers'       => [],
        ]), JSON_UNESCAPED_UNICODE);

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($responsePayload));
        header('Connection: close');
        echo $responsePayload;

        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();

        // 8. Propagate to peers
        if (!$local['existed']) {
            ignore_user_abort(true);
            foreach ($servers as $server) {
                self::pushToServer($server, $storedPath, $upload['name'], $description, $userPubKey, $nodeInfo);
            }
        }
        exit;
    }

    private static function storeLocally(
        string $tmpPath,
        string $originalName,
        string $description,
        string $userPubKey,
        string $serverPubKey,
        string $nodeInfo
    ): array {
        $ext = self::safeExt($originalName);
        $hash = hash_file('sha256', $tmpPath);
        $baseName = $ext !== '' ? "{$hash}.{$ext}" : $hash;

        $destFile = MeshConfig::DIR_FILES . '/' . $baseName;
        $destInfo = MeshConfig::DIR_INFO  . '/' . $hash . '.json';

        $existed = file_exists($destFile);

        if (!$existed) {
            if (!copy($tmpPath, $destFile)) {
                return ['ok' => false, 'error' => 'Could not write file to disk.'];
            }
            self::appendIndex($baseName);
        }

        if (!file_exists($destInfo)) {
            $info = [
                'filename'          => self::sanitize($originalName),
                'size'              => (int) filesize($destFile),
                'extension'         => self::sanitize($ext),
                'public_key'        => $userPubKey,
                'server_public_key' => $serverPubKey,
                'node_info'         => $nodeInfo,
                'description'       => $description,
                'date'              => date('c'), // ISO 8601 Timestamp
            ];
            file_put_contents($destInfo, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return ['ok' => true, 'hash' => $hash, 'filename' => $baseName, 'existed' => $existed];
    }

    private static function pushToServer(
        string $server,
        string $filePath,
        string $filename,
        string $description,
        string $userPubKey,
        string $nodeInfo
    ): void {
        $boundary = '----MeshBoundary' . bin2hex(random_bytes(12));
        $body = '';

        $mime  = @mime_content_type($filePath) ?: 'application/octet-stream';
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . addslashes($filename) . "\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= file_get_contents($filePath) . "\r\n";

        $fields = [
            'description' => $description,
            'public_key'  => $userPubKey,
            'node_info'   => $nodeInfo,
            'peer_push'   => '1',
        ];

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $url = rtrim($server, '/') . '/' . basename(__FILE__);
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

        @file_get_contents($url, false, $ctx);
    }

    private static function appendFilesAndDataJson(string $hash): void
    {
        ob_start();
        $prev = error_reporting(0);
        try {
            $infoPath = MeshConfig::DIR_INFO . '/' . $hash . '.json';
            $raw = @file_get_contents($infoPath);
            if ($raw === false) return;

            $info = json_decode($raw, true);
            if (!is_array($info)) return;

            $ext = $info['extension'] ?? '';
            $baseName = $ext !== '' ? "{$hash}.{$ext}" : $hash;
            self::appendIndex($baseName);

            $entry = array_merge(['hash' => $hash], $info);
            $dataJson = MeshConfig::FILE_DATA_JSON;

            if (!file_exists($dataJson)) {
                file_put_contents($dataJson, "[\n]", LOCK_EX);
            }

            $fp = @fopen($dataJson, 'c+');
            if ($fp === false || !flock($fp, LOCK_EX)) {
                if ($fp) fclose($fp);
                return;
            }

            $content = rtrim((string) stream_get_contents($fp));
            $closingPos = strrpos($content, ']');
            $encoded = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $indented = implode("\n", array_map(static function(string $l) { return '    ' . $l; }, explode("\n", $encoded)));

            if ($closingPos === false || trim(substr($content, 1, $closingPos - 1)) === '') {
                $newContent = "[\n" . $indented . "\n]";
            } else {
                $newContent = rtrim(substr($content, 0, $closingPos)) . ",\n" . $indented . "\n]";
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $newContent);
            flock($fp, LOCK_UN);
            fclose($fp);
        } finally {
            error_reporting($prev);
            ob_end_clean();
        }
    }

    // -- Helper Utilities --

    private static function sanitize(string $input): string
    {
        return substr(trim($input), 0, MeshConfig::MAX_TEXT_LEN);
    }

    private static function safeExt(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return substr(preg_replace('/[^a-z0-9]/', '', $ext), 0, MeshConfig::MAX_TEXT_LEN);
    }

    private static function readOptionalFile(string $path): string
    {
        return file_exists($path) ? trim((string) file_get_contents($path)) : '';
    }

    private static function appendIndex(string $entry): void
    {
        $lines = file_exists(MeshConfig::FILE_INDEX)
            ? array_map('trim', file(MeshConfig::FILE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];
        if (!in_array($entry, $lines, true)) {
            file_put_contents(MeshConfig::FILE_INDEX, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private static function loadServers(): array
    {
        if (!file_exists(MeshConfig::FILE_SERVERS)) return [];
        $lines = file(MeshConfig::FILE_SERVERS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                if (!preg_match('#^https?://#i', $line)) $line = 'http://' . $line;
                $out[] = rtrim($line, '/');
            }
        }
        return array_unique($out);
    }

    private static function jsonOut(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -- Application Initialization ------------------------------------------------
MeshNode::bootstrap();
MeshNode::handleRequest();

// -------------------------------------------------------------------------------
//  HTML FRONT-END
// -------------------------------------------------------------------------------
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Meento Share  — distributed file replication over HTTP/HTTPS">
<meta name="theme-color" content="#4f46e5">
<title>Meento Share - Distributed File Replication</title>
<style>
/* --- Design tokens --------------------------------------------------------- */
:root {
  --bg:              #f6f7fb;
  --bg-elev:         #ffffff;
  --bg-subtle:       #f1f2f6;
  --border:          #e5e7ec;
  --border-strong:   #d2d6dd;
  --text:            #0a0c10;
  --text-2:          #4a5060;
  --text-3:          #8b92a3;
  --accent:          #4f46e5;
  --accent-2:        #7c3aed;
  --accent-soft:     #eef0ff;
  --accent-hover:    #4338ca;
  --success:         #059669;
  --success-soft:    #ecfdf5;
  --success-border:  #a7f3d0;
  --danger:          #dc2626;
  --danger-soft:     #fef2f2;
  --danger-border:   #fecaca;
  --warning:         #d97706;
  --radius-sm:       6px;
  --radius:          10px;
  --radius-lg:       14px;
  --shadow-xs:       0 1px 2px rgba(15,17,23,.04);
  --shadow-sm:       0 1px 3px rgba(15,17,23,.05), 0 1px 2px rgba(15,17,23,.03);
  --shadow:          0 4px 14px rgba(15,17,23,.06), 0 1px 3px rgba(15,17,23,.04);
  --font:            'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
  --mono:            'JetBrains Mono', ui-monospace, "SF Mono", "Fira Code", "Roboto Mono", monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { min-height: 100%; }
body {
  background: var(--bg);
  background-image:
    radial-gradient(circle at 18% -10%, rgba(79,70,229,.07), transparent 45%),
    radial-gradient(circle at 85% 110%, rgba(124,58,237,.06), transparent 45%);
  background-attachment: fixed;
  color: var(--text);
  font-family: var(--font);
  font-size: 14px;
  line-height: 1.5;
  font-feature-settings: "cv11", "ss01", "ss03";
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px 24px 48px;
}

.shell { width: 100%; max-width: 760px; }

/* --- Header --------------------------------------------------------------- */
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 28px;
  flex-wrap: wrap;
}
.brand { display: flex; align-items: center; gap: 12px; }
.brand-mark {
  width: 38px; height: 38px;
  border-radius: 10px;
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  display: grid; place-items: center;
  color: #fff; font-weight: 700; font-size: 15px;
  box-shadow: 0 4px 10px rgba(79,70,229,.25), inset 0 1px 0 rgba(255,255,255,.2);
  position: relative;
  overflow: hidden;
}
.brand-mark::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(180deg, rgba(255,255,255,.18), transparent 55%);
  pointer-events: none;
}
.brand-text h1 { font-size: 15px; font-weight: 600; letter-spacing: -.01em; }
.brand-text p  { font-size: 12px; color: var(--text-3); margin-top: 1px; }

.head-nav { display: flex; gap: 2px; flex-wrap: wrap; }
.head-nav a {
  font-size: 12.5px; color: var(--text-2); text-decoration: none;
  padding: 6px 10px; border-radius: var(--radius-sm);
  font-weight: 500;
  transition: background .15s, color .15s;
}
.head-nav a:hover { background: var(--bg-subtle); color: var(--text); }
.head-nav a.active { background: var(--accent-soft); color: var(--accent); }

/* --- Status cards --------------------------------------------------------- */
.status-bar {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 20px;
}
.sb-card {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px;
  background: var(--bg-elev);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-xs);
}
.nb-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--text-3);
  flex-shrink: 0;
  transition: background .2s, box-shadow .2s;
}
.nb-dot.ok   { background: var(--success); box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
.nb-dot.blue { background: var(--accent);  box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
.sb-label { font-size: 10.5px; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
.sb-value { font-size: 13px; color: var(--text); font-weight: 600; margin-top: 1px; font-variant-numeric: tabular-nums; }

/* --- Drop zone ------------------------------------------------------------ */
.drop {
  position: relative;
  background: var(--bg-elev);
  border: 1.5px dashed var(--border-strong);
  border-radius: var(--radius-lg);
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  transition: border-color .2s, background .2s, transform .2s;
  overflow: hidden;
}
.drop::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(circle at 50% 0%, var(--accent-soft), transparent 65%);
  opacity: 0;
  transition: opacity .3s;
  pointer-events: none;
}
.drop:hover, .drop:focus-visible { border-color: var(--accent); outline: none; }
.drop:hover::before { opacity: .55; }
.drop.over { border-color: var(--accent); transform: scale(1.005); }
.drop.over::before { opacity: 1; }
.drop-icon {
  width: 56px; height: 56px;
  margin: 0 auto 16px;
  background: var(--accent-soft);
  color: var(--accent);
  border-radius: 14px;
  display: grid; place-items: center;
  position: relative; z-index: 1;
  transition: transform .25s;
}
.drop:hover .drop-icon, .drop.over .drop-icon { transform: translateY(-2px); }
.drop-icon svg { width: 26px; height: 26px; stroke-width: 1.8; }
.drop-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; position: relative; }
.drop-sub   { font-size: 13px; color: var(--text-2); position: relative; }
.drop-sub b { color: var(--text); font-weight: 600; }
.drop-sub .sep { color: var(--text-3); margin: 0 6px; }

/* --- Upload panel --------------------------------------------------------- */
#panel {
  display: none;
  margin-top: 16px;
  background: var(--bg-elev);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  animation: slideIn .25s cubic-bezier(.2,.8,.2,1);
}
@keyframes slideIn {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.panel-head {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.file-chip {
  width: 40px; height: 40px;
  background: var(--accent-soft); color: var(--accent);
  border-radius: 9px; display: grid; place-items: center; flex-shrink: 0;
}
.file-chip svg { width: 20px; height: 20px; stroke-width: 1.8; }
#f-name {
  flex: 1; font-weight: 600; font-size: 14px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#f-size { font-size: 12px; color: var(--text-3); font-variant-numeric: tabular-nums; flex-shrink: 0; }
.x-btn {
  background: transparent; border: 0; cursor: pointer;
  color: var(--text-3); padding: 6px; border-radius: 6px;
  display: grid; place-items: center;
  transition: background .15s, color .15s;
}
.x-btn:hover { background: var(--danger-soft); color: var(--danger); }
.x-btn svg { width: 16px; height: 16px; stroke-width: 2; }

.panel-meta {
  padding: 10px 16px;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border);
  display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
}
.mpill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; padding: 4px 10px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  background: var(--bg-elev);
  font-weight: 500;
  transition: background .15s, border-color .15s, color .15s;
}
.mpill.has { background: var(--success-soft); border-color: var(--success-border); color: var(--success); }
.mpill svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2.2; fill: none; }
.input-inline {
  flex: 1; min-width: 200px;
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: 999px;
  font: inherit; font-size: 12px;
  outline: none; color: var(--text);
  background: var(--bg-elev);
  transition: border-color .15s, box-shadow .15s;
}
.input-inline:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(79,70,229,.12);
}

.panel-body { padding: 16px; }
.field-label {
  display: block; font-size: 11px; font-weight: 600;
  color: var(--text-2); margin-bottom: 6px;
  text-transform: uppercase; letter-spacing: .06em;
}
.field-label .hint { color: var(--text-3); font-weight: 500; text-transform: none; letter-spacing: 0; margin-left: 4px; }
textarea#comment-box {
  width: 100%; min-height: 96px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font: inherit; font-size: 14px;
  resize: vertical; outline: none;
  background: var(--bg-elev);
  transition: border-color .15s, box-shadow .15s;
  color: var(--text);
  line-height: 1.5;
}
textarea#comment-box:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(79,70,229,.12);
}
.field-meta { display: flex; justify-content: space-between; margin-top: 6px; font-size: 11px; color: var(--text-3); }
.ccount { font-variant-numeric: tabular-nums; }
.ccount.over { color: var(--danger); }

.panel-foot {
  padding: 14px 16px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; background: var(--bg-subtle);
}
.peer-info { font-size: 12px; color: var(--text-2); }
.peer-info b { color: var(--text); font-weight: 600; font-variant-numeric: tabular-nums; }
.btn-send {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  background: var(--accent); color: #fff;
  border: 0; border-radius: 8px;
  font: inherit; font-weight: 600; font-size: 13px;
  cursor: pointer;
  transition: background .15s, transform .1s, box-shadow .15s;
  box-shadow: 0 1px 2px rgba(79,70,229,.3);
}
.btn-send:hover  { background: var(--accent-hover); box-shadow: 0 2px 6px rgba(79,70,229,.35); }
.btn-send:active { transform: translateY(1px); }
.btn-send.busy   { opacity: .65; pointer-events: none; }
.btn-send svg    { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; }

/* --- Progress ------------------------------------------------------------- */
#prog-wrap {
  height: 4px;
  background: var(--border);
  border-radius: 999px;
  overflow: hidden;
  display: none;
  margin-top: 16px;
}
#prog-bar {
  height: 100%; width: 0%;
  background: linear-gradient(90deg, var(--accent), var(--accent-2));
  transition: width .15s linear;
  border-radius: 999px;
}

/* --- Status messages ------------------------------------------------------ */
#status {
  display: none;
  margin-top: 16px;
  padding: 12px 16px;
  border-radius: var(--radius);
  font-size: 13px;
  line-height: 1.5;
  animation: fadeUp .25s ease;
}
#status.ok   { background: var(--success-soft); border: 1px solid var(--success-border); color: var(--success); }
#status.fail { background: var(--danger-soft);  border: 1px solid var(--danger-border);  color: var(--danger); }
.hash-line { margin-top: 10px; }
.file-link {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px;
  font-size: 12px; font-weight: 500;
  text-decoration: none;
  color: var(--accent);
  background: var(--bg-elev);
  border: 1px solid var(--accent);
  border-radius: 6px;
  transition: background .15s;
}
.file-link:hover { background: var(--accent-soft); }
.file-link svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2.2; fill: none; }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* --- Code preview --------------------------------------------------------- */
.code-card {
  margin-top: 28px;
  background: var(--bg-elev);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-xs);
}
.code-head {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  background: var(--bg-subtle);
  font-size: 12px;
  color: var(--text-2);
  font-weight: 500;
}
.code-head .tl { display: flex; gap: 6px; }
.code-head .tl span { width: 10px; height: 10px; border-radius: 50%; display: block; }
.tl-r { background: #ff5f57; }
.tl-y { background: #febc2e; }
.tl-g { background: #28c840; }
.code-head .title { margin-left: 4px; }
.code-head .path  { color: var(--text-3); font-family: var(--mono); font-size: 11px; margin-left: auto; }
pre#json-schema {
  padding: 16px;
  font-family: var(--mono);
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--text);
  overflow-x: auto;
  margin: 0;
  background: transparent;
  tab-size: 2;
}
.jk { color: #7c3aed; }
.js { color: #059669; }
.jn { color: #d97706; }
.jb { color: #dc2626; }

/* --- Footer --------------------------------------------------------------- */
footer {
  margin-top: 32px;
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 8px;
  font-size: 12px; color: var(--text-3);
  width: 100%; max-width: 760px;
}
footer .php-v { font-family: var(--mono); }

/* --- Responsive ----------------------------------------------------------- */
@media (max-width: 640px) {
  body { padding: 20px 16px 32px; }
  header { gap: 12px; }
  .head-nav { gap: 0; }
  .head-nav a { padding: 5px 8px; font-size: 12px; }
  .status-bar { grid-template-columns: 1fr; }
  .drop { padding: 36px 16px; }
  .panel-foot { flex-direction: column; align-items: stretch; }
  .btn-send { justify-content: center; }
  .input-inline { min-width: 0; width: 100%; }
}
</style>
</head>
<body>
<div class="shell">

<header>
  <div class="brand">
    <div class="brand-mark">M</div>
    <div class="brand-text">
      <h1>Meento Share </h1>
      <p>Distributed file replication</p>
    </div>
  </div>
  <nav class="head-nav">
    <a href="index.php" class="active">Index</a>
    <a href="servers.php">Servers</a>
    <a href="index.html">About</a>
    <a href="https://t.me/meentoshare">Meento</a>  </nav>
</header>

<div class="status-bar">
  <div class="sb-card">
    <span class="nb-dot blue"></span>
    <div>
      <div class="sb-label">Peers</div>
      <div class="sb-value" id="nb-peers">…</div>
    </div>
  </div>
  <div class="sb-card">
    <span class="nb-dot" id="nb-pk-dot"></span>
    <div>
      <div class="sb-label">Public Key</div>
      <div class="sb-value" id="nb-pk">…</div>
    </div>
  </div>
  <div class="sb-card">
    <span class="nb-dot" id="nb-ni-dot"></span>
    <div>
      <div class="sb-label">Node Info</div>
      <div class="sb-value" id="nb-ni">…</div>
    </div>
  </div>
</div>

<div id="drop-zone" class="drop" tabindex="0" role="button" aria-label="Select or drop a file">
  <div class="drop-icon">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="17 8 12 3 7 8"/>
      <line x1="12" y1="3" x2="12" y2="15"/>
    </svg>
  </div>
  <p class="drop-title">Drop a file or click to select</p>
  <p class="drop-sub">
    <b>1 GB</b> maximum
    <span class="sep">·</span>
    Replicated to all peers
    <span class="sep">·</span>
    <b>.php</b> blocked
  </p>
</div>
<input type="file" id="file-input">

<div id="panel">
  <div class="panel-head">
    <div class="file-chip">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
    </div>
    <span id="f-name">—</span>
    <span id="f-size"></span>
    <button class="x-btn" id="x-btn" aria-label="Remove file">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <div class="panel-meta">
    <input type="text" class="input-inline" id="pk-inline" maxlength="512" placeholder="Your public key (optional)…">
    <div class="mpill" id="mpill-ni">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      node_info
    </div>
    <div class="mpill has">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      metadata extracted
    </div>
  </div>

  <div class="panel-body">
    <label class="field-label" for="comment-box">Description <span class="hint">(optional · max 512 chars)</span></label>
    <textarea id="comment-box" placeholder="Add an optional description for this file…"></textarea>
    <div class="field-meta">
      <span>Stored as the “description” field</span>
      <span class="ccount" id="ccount">0 / 512</span>
    </div>
  </div>

  <div class="panel-foot">
    <span class="peer-info">Sending to <b id="peer-count">…</b> peer(s)</span>
    <button class="btn-send" id="send-btn">
      <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      Send to network
    </button>
  </div>
</div>

<div id="prog-wrap"><div id="prog-bar"></div></div>
<div id="status"></div>

<div class="code-card">
  <div class="code-head">
    <div class="tl">
      <span class="tl-r"></span>
      <span class="tl-y"></span>
      <span class="tl-g"></span>
    </div>
    <span class="title">JSON schema</span>
    <span class="path">info/&lt;hash&gt;.json</span>
  </div>
  <pre id="json-schema">{
  <span class="jk">"filename"</span>:          <span class="js">"photo.jpg"</span>,
  <span class="jk">"size"</span>:              <span class="jn">204800</span>,
  <span class="jk">"extension"</span>:         <span class="js">"jpg"</span>,
  <span class="jk">"public_key"</span>:        <span class="js">"&lt;user input from form (max 512)&gt;"</span>,
  <span class="jk">"server_public_key"</span>: <span class="js">"&lt;loaded from public_key.txt&gt;"</span>,
  <span class="jk">"node_info"</span>:         <span class="js">"&lt;loaded from node_info.txt&gt;"</span>,
  <span class="jk">"description"</span>:       <span class="js">"&lt;user description (max 512)&gt;"</span>,
  <span class="jk">"date"</span>:              <span class="js">"2026-06-15T11:25:10-03:00"</span>
}</pre>
</div>

</div>
<footer>
  <p>Meento Share  · Object Oriented · 1 GB limit · Strict PHP 7+</p>
  <p class="php-v">PHP <?php echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION; ?></p>
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
const mpillNi  = document.getElementById('mpill-ni');
const pkInline = document.getElementById('pk-inline');

const MAX_CHARS = 512;
const MAX_BYTES = 1073741824; // 1 GB
const BLOCKED   = ['php','php3','php4','php5','php7','phtml','phar'];

let file = null;
let ns   = { public_key: false, node_info: false, peers: 0 };

// -- fetch node status ----------------------------------------------------------
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
    document.getElementById('nb-pk').textContent = d.public_key ? 'Found'   : 'Missing';
    document.getElementById('nb-ni').textContent = d.node_info  ? 'Found'   : 'Missing';
    mpillNi.className = 'mpill ' + (ns.node_info ? 'has' : '');
  })
  .catch(() => {
    ['nb-peers','nb-pk','nb-ni'].forEach(id => {
      const el = document.getElementById(id); if(el) el.textContent = '—';
    });
    peerCnt.textContent = '—';
  });

function fmt(b){
  if(b<1024) return b+' B';
  if(b<1048576) return (b/1024).toFixed(1)+' KB';
  if(b<1073741824) return (b/1048576).toFixed(2)+' MB';
  return (b/1073741824).toFixed(2)+' GB';
}

function extOf(n){ return n.split('.').pop().toLowerCase(); }

function setFile(f){
  if(!f) return;
  if(BLOCKED.includes(extOf(f.name))){ show('fail','PHP files are strictly blocked.'); return; }
  if(f.size > MAX_BYTES){ show('fail', 'File exceeds the 1GB size limit.'); return; }

  file = f;
  fName.textContent = f.name;
  fSize.textContent = fmt(f.size);
  panel.style.display = 'block';
  commentB.value = '';
  updateCount();
  hide();
}

function clear(){
  file = null; fi.value = '';
  panel.style.display = 'none';
  commentB.value = '';
  pkInline.value = '';
}

dz.addEventListener('click', () => fi.click());
dz.addEventListener('keydown', e => { if(e.key==='Enter'||e.key===' ') fi.click(); });
fi.addEventListener('change', () => { if(fi.files[0]) setFile(fi.files[0]); });
xBtn.addEventListener('click', clear);

['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('over'); }));
dz.addEventListener('drop', e => { if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });

function updateCount(){
  const len = [...commentB.value].length; // Account for unicode characters accurately
  ccount.textContent = len.toLocaleString() + ' / ' + MAX_CHARS;
  ccount.classList.toggle('over', len > MAX_CHARS);
}
commentB.addEventListener('input', updateCount);

function show(type, html){ status.className=type; status.innerHTML=html; status.style.display='block'; }
function hide(){ status.style.display='none'; }

sendBtn.addEventListener('click', () => {
  if(!file) return;

  const descText = commentB.value.trim();
  if([...descText].length > MAX_CHARS){
    show('fail','Description exceeds the 512 character limit.'); return;
  }

  const fd = new FormData();
  fd.append('file', file);
  fd.append('description', descText);

  const userPk = pkInline.value.trim();
  if (userPk !== '') {
    fd.append('public_key', userPk.slice(0, MAX_CHARS));
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
    if(!res.ok){ show('fail','! '+(res.error||'Unknown error')); return; }

    showLink(res.filename, !!res.existed);
    clear();
  });

  xhr.addEventListener('error', () => {
    sendBtn.classList.remove('busy');
    sendBtn.lastChild.textContent = ' Send to network';
    show('fail','! Network error.'); progWrap.style.display='none';
  });

  xhr.open('POST', window.location.href);
  xhr.send(fd);
});

function showLink(filename, existed){
  const fileUrl = 'files/' + esc(filename);
  const note    = existed ? ' <span style="opacity:0.6">(already stored)</span>' : '';
  show('ok',
    '? File stored' + note +
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
