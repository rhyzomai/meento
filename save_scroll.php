<?php
/**
 * save_scroll.php
 * Called by index.html when scrol.js needs to be written/updated.
 * Scans the info/ folder, reads all JSON files, and writes scrol.js.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$INFO_DIR  = __DIR__ . '/info/';
$SCROLL_JS = __DIR__ . '/scrol.js';

// ── SCAN + WRITE ──────────────────────────────────────────────────────
function scanAndWrite($infoDir, $scrollJs) {
    $entries = [];

    if (!is_dir($infoDir)) {
        mkdir($infoDir, 0755, true);
    }

    $files = glob($infoDir . '*.json');
    if ($files === false) $files = [];

    foreach ($files as $filepath) {
        $content = file_get_contents($filepath);
        if ($content === false) continue;
        $obj = json_decode($content, true);
        if (!is_array($obj)) continue;
        $obj['_jsonfile'] = basename($filepath);
        $entries[] = $obj;
    }

    // Sort by original_filename
    usort($entries, function($a, $b) {
        return strcmp(
            strtolower($a['original_filename'] ?? ''),
            strtolower($b['original_filename'] ?? '')
        );
    });

    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($scrollJs, $json);

    return $entries;
}

// ── HANDLE POST (called from JS) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (isset($data['data']) && is_array($data['data'])) {
        // JS sent us parsed data — just write it
        $json = json_encode($data['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($SCROLL_JS, $json);
        echo json_encode(['ok' => true, 'count' => count($data['data'])]);
    } else {
        // Rescan from disk
        $entries = scanAndWrite($INFO_DIR, $SCROLL_JS);
        echo json_encode(['ok' => true, 'count' => count($entries)]);
    }
    exit;
}

// ── HANDLE GET (manual trigger: save_scroll.php?scan=1) ──────────────
if (isset($_GET['scan'])) {
    $entries = scanAndWrite($INFO_DIR, $SCROLL_JS);
    echo json_encode(['ok' => true, 'count' => count($entries), 'entries' => $entries]);
    exit;
}

// Default: return current scrol.js content
if (file_exists($SCROLL_JS)) {
    $content = file_get_contents($SCROLL_JS);
    echo $content;
} else {
    echo '[]';
}