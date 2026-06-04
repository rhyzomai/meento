<?php
// ==========================================
// SESSION & USER ID
// ==========================================
session_start();
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'usr_' . bin2hex(random_bytes(8));
}
$user_id = $_SESSION['user_id'];

// ==========================================
// DATABASE CONFIGURATION (from db.txt)
// ==========================================
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'file_indexer';

try {
    $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS file_registry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_hash VARCHAR(255) UNIQUE NOT NULL,
        server_filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size INT NOT NULL,
        extension VARCHAR(50) NOT NULL,
        public_key TEXT,
        node_info TEXT,
        description TEXT,
        hosts TEXT NOT NULL,
        indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (indexed_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) NOT NULL,
        file_hash VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255),
        extension VARCHAR(50),
        file_size INT,
        description TEXT,
        watched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_file (user_id, file_hash),
        INDEX (user_id),
        INDEX (file_hash)
    )");

} catch (PDOException $e) {
    die("<div style='color:red;font-family:sans-serif;padding:20px'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ==========================================
// MEDIA EXTENSION FILTER
// Uses a simple REGEXP so no placeholder count issues
// ==========================================
// Matches: mp3 mp4 jpg jpeg png gif webp avif svg bmp tiff wav ogg flac aac m4a opus webm mov m4v
define('EXT_REGEXP', '^(mp3|mp4|jpg|jpeg|png|gif|webp|avif|svg|bmp|tiff|wav|ogg|flac|aac|m4a|opus|webm|mov|m4v)$');

function media_where(): string {
    return "LOWER(TRIM(extension)) REGEXP '" . EXT_REGEXP . "'";
}

// ==========================================
// API ENDPOINTS
// ==========================================

$action = $_GET['action'] ?? '';

// ── DEBUG: ?action=debug  (remove in production) ──
if ($action === 'debug') {
    header('Content-Type: application/json');
    $out = [];

    // 1. Total rows in table
    $out['total_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM file_registry")->fetchColumn();

    // 2. Sample of ALL extensions present
    $out['extensions_in_db'] = $pdo->query(
        "SELECT extension, COUNT(*) as cnt FROM file_registry GROUP BY extension ORDER BY cnt DESC LIMIT 30"
    )->fetchAll();

    // 3. Rows matching our media filter
    $out['media_rows'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM file_registry WHERE " . media_where()
    )->fetchColumn();

    // 4. First 3 raw matching rows (full data)
    $out['sample_rows'] = $pdo->query(
        "SELECT * FROM file_registry WHERE " . media_where() . " LIMIT 3"
    )->fetchAll();

    // 5. First 3 raw rows regardless of extension
    $out['sample_any'] = $pdo->query(
        "SELECT file_hash, extension, hosts, original_filename FROM file_registry LIMIT 3"
    )->fetchAll();

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── POST ?action=record_preference ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'record_preference') {
    header('Content-Type: application/json');
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $file_hash = trim($input['file_hash'] ?? '');

    if (!$file_hash) { echo json_encode(['ok'=>false,'error'=>'Missing file_hash']); exit; }

    $stmt = $pdo->prepare("SELECT file_hash, original_filename, extension, file_size, description FROM file_registry WHERE file_hash = ? LIMIT 1");
    $stmt->execute([$file_hash]);
    $file = $stmt->fetch();

    if (!$file) { echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }

    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO users_preferences
            (user_id, file_hash, original_filename, extension, file_size, description)
            VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $user_id,
            $file['file_hash'],
            $file['original_filename'],
            $file['extension'],
            $file['file_size'],
            $file['description'],
        ]);
        echo json_encode(['ok'=>true, 'inserted'=>($ins->rowCount() > 0)]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── HELPER: parse seen hashes from request (GET or POST JSON) ──
function get_seen_hashes(): array {
    $raw = $_GET['seen'] ?? '';
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    // Whitelist: only hex strings (SHA-256 hashes)
    return array_values(array_filter($decoded, fn($h) => preg_match('/^[0-9a-f]{1,128}$/i', $h)));
}

function seen_exclude_clause(array $seen, string $prefix = ''): string {
    if (empty($seen)) return '';
    $placeholders = implode(',', array_fill(0, count($seen), '?'));
    return " AND {$prefix}file_hash NOT IN ($placeholders)";
}

// ── GET ?action=random ──
if ($action === 'random') {
    header('Content-Type: application/json');
    $seen  = get_seen_hashes();
    $where = media_where() . seen_exclude_clause($seen);
    $stmt  = $pdo->prepare(
        "SELECT file_hash, original_filename, file_size, extension, description, hosts
           FROM file_registry
          WHERE $where
          ORDER BY RAND() LIMIT 1"
    );
    $stmt->execute($seen);
    $row = $stmt->fetch();
    echo json_encode($row ?: null);
    exit;
}

// ── GET ?action=feed&page=N ──
if ($action === 'feed') {
    header('Content-Type: application/json');
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;
    $seen   = get_seen_hashes();
    $where  = media_where() . seen_exclude_clause($seen);

    // Per-session seed: shuffles order on each new session/refresh,
    // but stays stable within a session so pagination is consistent.
    if (empty($_SESSION['feed_seed'])) {
        $_SESSION['feed_seed'] = mt_rand(1, 2147483647);
    }
    $seed = (int)$_SESSION['feed_seed'];
    // ?reset=1 forces a new shuffle (sent by JS on page load)
    if (!empty($_GET['reset'])) {
        $_SESSION['feed_seed'] = mt_rand(1, 2147483647);
        $seed = (int)$_SESSION['feed_seed'];
    }

    $stmt = $pdo->prepare(
        "SELECT file_hash, original_filename, file_size, extension, description, hosts
           FROM file_registry
          WHERE $where
          ORDER BY RAND($seed)
          LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($seen);
    $rows = $stmt->fetchAll();

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM file_registry WHERE $where");
    $cntStmt->execute($seen);
    $total = (int)$cntStmt->fetchColumn();

    echo json_encode(['entries' => $rows, 'total' => $total, 'page' => $page]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Files Feed</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0a0a0f;
    --surface: #111118;
    --surface2: #1a1a24;
    --border: rgba(255,255,255,0.07);
    --accent: #00f5a0;
    --accent2: #7c6aff;
    --text: #f0f0f5;
    --muted: #666680;
    --danger: #ff4466;
    --card-h: 100dvh;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  html, body {
    height: 100%;
    overflow: hidden;
    background: var(--bg);
    color: var(--text);
    font-family: 'Syne', sans-serif;
    -webkit-tap-highlight-color: transparent;
    overscroll-behavior: none;
  }

  #topbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    height: 56px;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 20px;
    background: linear-gradient(to bottom, rgba(10,10,15,0.98), transparent);
    pointer-events: none;
  }
  #topbar .logo {
    font-family: 'Space Mono', monospace;
    font-size: 18px; font-weight: 700; letter-spacing: -0.5px;
    color: var(--accent); pointer-events: all;
  }
  #topbar .logo span { color: var(--text); }
  #counter {
    font-family: 'Space Mono', monospace;
    font-size: 11px; color: var(--muted); pointer-events: all;
  }

  #feed {
    position: fixed; inset: 0;
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  #feed::-webkit-scrollbar { display: none; }

  .card {
    height: var(--card-h); width: 100%;
    scroll-snap-align: start; scroll-snap-stop: always;
    position: relative; display: flex; flex-direction: column;
    overflow: hidden; background: var(--surface);
  }

  .card-media {
    flex: 1; min-height: 0; position: relative;
    overflow: hidden; cursor: pointer; background: var(--bg);
  }
  .card-media img { width:100%; height:100%; object-fit:cover; display:block; }
  .card-media video { width:100%; height:100%; object-fit:contain; display:block; background:#000; }

  /* audio visual */
  .audio-bg {
    width:100%; height:100%;
    background: linear-gradient(135deg, #0d0d1a 0%, #1a0d2e 50%, #0d1a2e 100%);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:20px; position:relative; overflow:hidden;
  }
  .audio-bg::before {
    content:''; position:absolute; inset:-50%;
    background: conic-gradient(from 0deg,transparent 0%,var(--accent2) 5%,transparent 10%,
      transparent 45%,var(--accent) 50%,transparent 55%);
    animation: spin 8s linear infinite; opacity:0.08;
  }
  @keyframes spin { to { transform:rotate(360deg); } }
  .audio-disc {
    width:120px; height:120px; border-radius:50%;
    background: conic-gradient(var(--accent2),var(--accent),var(--accent2));
    display:flex; align-items:center; justify-content:center;
    animation: spin 4s linear infinite;
    box-shadow: 0 0 40px rgba(124,106,255,0.3);
  }
  .audio-disc.paused { animation-play-state:paused; }
  .audio-disc::after { content:''; width:36px; height:36px; border-radius:50%; background:var(--bg); }
  .audio-waveform { display:flex; gap:3px; align-items:center; height:40px; }
  .audio-waveform span {
    width:3px; background:var(--accent); border-radius:2px;
    animation: wave 1.2s ease-in-out infinite; opacity:0.7;
  }
  .audio-waveform.paused span { animation-play-state:paused; }
  @keyframes wave {
    0%,100% { height:6px; opacity:0.4; }
    50% { height:28px; opacity:1; }
  }

  .card-overlay {
    position:absolute; bottom:0; left:0; right:0; height:55%;
    background: linear-gradient(to top, rgba(0,0,0,0.92) 0%, transparent 100%);
    pointer-events:none; z-index:2;
  }

  .card-info {
    position:absolute; bottom:80px; left:0; right:72px;
    padding:0 18px; z-index:3;
  }
  .card-filename {
    font-family:'Syne',sans-serif; font-size:16px; font-weight:800;
    line-height:1.2; margin-bottom:6px;
    text-shadow:0 1px 8px rgba(0,0,0,0.8); word-break:break-all;
  }
  .card-meta { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
  .badge {
    font-family:'Space Mono',monospace; font-size:10px; font-weight:700;
    padding:3px 8px; border-radius:6px; text-transform:uppercase; letter-spacing:1px;
  }
  .badge-ext  { background:rgba(0,245,160,0.15); color:var(--accent); border:1px solid rgba(0,245,160,0.25); }
  .badge-size { background:rgba(255,255,255,0.06); color:var(--muted); border:1px solid var(--border); }
  .badge-random {
    background:rgba(124,106,255,0.2); color:var(--accent2); border:1px solid rgba(124,106,255,0.35);
    animation: pulse-badge 2s ease-in-out infinite;
  }
  @keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:0.5} }
  .badge-host {
    background:rgba(255,255,255,0.04); color:var(--muted);
    border:1px solid var(--border); font-size:9px; max-width:160px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .card-desc {
    font-size:13px; color:rgba(240,240,245,0.7); line-height:1.5;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  }

  .card-actions {
    position:absolute; right:12px; bottom:80px;
    display:flex; flex-direction:column; gap:18px; align-items:center; z-index:3;
  }
  .action-btn {
    display:flex; flex-direction:column; align-items:center; gap:4px;
    background:none; border:none; cursor:pointer; color:var(--text);
    -webkit-tap-highlight-color:transparent; transition:transform 0.15s;
  }
  .action-btn:active { transform:scale(0.85); }
  .action-icon {
    width:44px; height:44px; border-radius:50%;
    background:rgba(255,255,255,0.1); backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.12);
    display:flex; align-items:center; justify-content:center; font-size:18px;
    transition:background 0.2s;
  }
  .action-label { font-family:'Space Mono',monospace; font-size:10px; color:rgba(255,255,255,0.6); }

  #bottombar {
    position:fixed; bottom:0; left:0; right:0; z-index:100; height:60px;
    background:linear-gradient(to top, rgba(10,10,15,0.98), transparent);
    display:flex; align-items:center; justify-content:center; gap:6px;
    pointer-events:none;
  }
  .dot-nav { width:6px; height:6px; border-radius:50%; background:var(--muted); transition:all 0.3s; }
  .dot-nav.active { background:var(--accent); width:18px; border-radius:3px; }

  .card-loading {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:16px; height:100%; background:var(--bg);
  }
  .spinner {
    width:40px; height:40px; border:2px solid var(--border);
    border-top-color:var(--accent); border-radius:50%; animation:spin 0.8s linear infinite;
  }
  .loading-text { font-family:'Space Mono',monospace; font-size:12px; color:var(--muted); letter-spacing:2px; }

  .card-empty {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:16px; height:100%; background:var(--bg); padding:20px; text-align:center;
  }
  .empty-icon { font-size:48px; }
  .empty-text { font-size:14px; color:var(--muted); max-width:260px; line-height:1.6; }

  #progress-bar { position:fixed; top:0; left:0; right:0; z-index:200; height:2px; background:var(--border); }
  #progress-fill { height:100%; background:linear-gradient(90deg,var(--accent2),var(--accent)); transition:width 0.3s; width:0%; }

  #toast {
    position:fixed; bottom:80px; left:50%; transform:translateX(-50%) translateY(20px);
    z-index:300; background:var(--surface2); border:1px solid var(--border);
    border-radius:12px; padding:10px 18px; font-family:'Space Mono',monospace;
    font-size:12px; color:var(--text); opacity:0; transition:all 0.3s;
    white-space:nowrap; pointer-events:none;
  }
  #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }

  #index-pill {
    position:fixed; top:66px; right:16px; z-index:100;
    background:rgba(255,255,255,0.06); backdrop-filter:blur(8px);
    border:1px solid var(--border); border-radius:20px; padding:4px 10px;
    font-family:'Space Mono',monospace; font-size:10px; color:var(--muted);
  }

  audio.bg-audio { display:none; }
  .card-media-link { position:absolute; inset:0; z-index:1; }

  /* host error badge */
  .host-error {
    position:absolute; top:56px; left:50%; transform:translateX(-50%);
    z-index:10; background:rgba(255,68,102,0.15); border:1px solid rgba(255,68,102,0.35);
    border-radius:20px; padding:5px 14px;
    font-family:'Space Mono',monospace; font-size:10px; color:var(--danger);
    letter-spacing:1px; opacity:0; transition:opacity 0.4s; pointer-events:none; white-space:nowrap;
  }
  .host-error.show { opacity:1; }

  /* preference saved flash */
  .pref-flash {
    position:absolute; top:12px; left:50%; transform:translateX(-50%);
    z-index:10; background:rgba(0,245,160,0.15); border:1px solid rgba(0,245,160,0.35);
    border-radius:20px; padding:5px 14px;
    font-family:'Space Mono',monospace; font-size:10px; color:var(--accent);
    letter-spacing:1px; opacity:0; transition:opacity 0.5s; pointer-events:none; white-space:nowrap;
  }
  .pref-flash.show { opacity:1; }

  /* watch timer ring */
  .watch-ring { position:absolute; top:10px; right:10px; z-index:10; width:32px; height:32px; pointer-events:none; }
  .watch-ring circle { fill:none; stroke-width:3; }
  .watch-ring .ring-bg { stroke:rgba(255,255,255,0.1); }
  .watch-ring .ring-fill {
    stroke:var(--accent); stroke-dasharray:82; stroke-dashoffset:82;
    stroke-linecap:round; transform:rotate(-90deg); transform-origin:50% 50%;
  }
</style>
</head>
<body>

<div id="progress-bar"><div id="progress-fill"></div></div>
<div id="topbar">
  <div class="logo">MEENTO<span>.feed</span></div>
  <div id="counter">—</div>
</div>
<div id="index-pill">0 / 0</div>
<div id="feed"></div>
<div id="bottombar"></div>
<div id="toast"></div>

<script>
/* ═══════════════════════════════════════════════════
   VAULT FEED  —  PHP/MySQL powered media feed
═══════════════════════════════════════════════════ */

const USER_ID        = <?= json_encode($user_id) ?>;
const WATCH_THRESHOLD = 10; // seconds

const IMAGE_EXTS = ['jpg','jpeg','png','gif','webp','avif','svg','bmp','tiff'];
const VIDEO_EXTS = ['mp4','webm','ogg','mov','m4v'];
const AUDIO_EXTS = ['mp3','wav','ogg','flac','aac','m4a','opus'];

const feed      = document.getElementById('feed');
const counter   = document.getElementById('counter');
const indexPill = document.getElementById('index-pill');
const progressF = document.getElementById('progress-fill');
const toast     = document.getElementById('toast');

let entries         = [];   // [{data, isRandom}]
let rendered        = 0;
let currentIdx      = 0;
let totalCount      = 0;
let currentPage     = 1;
let isFetchingMore  = false;
let allLoaded       = false;
// Persist seen hashes across refreshes within the same browser session
const _storedSeen   = (() => { try { return JSON.parse(sessionStorage.getItem('meento_seen') || '[]'); } catch(e) { return []; } })();
const seenHashes    = new Set(_storedSeen); // tracks all file_hashes shown to user
function persistSeen() {
  try { sessionStorage.setItem('meento_seen', JSON.stringify([...seenHashes])); } catch(e) {}
}

// watchState[cardIndex] = { elapsed, startTime, saved, rafId, paused }
const watchState = {};

// ── UTILITIES ────────────────────────────────────────

function formatBytes(b) {
  if (!b) return '—';
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  return (b/1048576).toFixed(1) + ' MB';
}
function isImage(e) { return IMAGE_EXTS.includes((e||'').toLowerCase()); }
function isVideo(e) { return VIDEO_EXTS.includes((e||'').toLowerCase()); }
function isAudio(e) { return AUDIO_EXTS.includes((e||'').toLowerCase()); }

function showToast(msg, ms=2400) {
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.classList.remove('show'), ms);
}

function updateProgress() {
  const pct = totalCount ? ((currentIdx+1)/totalCount)*100 : 0;
  progressF.style.width = pct + '%';
  indexPill.textContent = `${currentIdx+1} / ${totalCount}`;
}

function updateDots() {
  const bb = document.getElementById('bottombar');
  bb.innerHTML = '';
  const total = Math.min(entries.length, 7);
  const start = Math.max(0, Math.min(currentIdx - 3, entries.length - total));
  for (let i = 0; i < total; i++) {
    const d = document.createElement('div');
    d.className = 'dot-nav' + ((start + i === currentIdx) ? ' active' : '');
    bb.appendChild(d);
  }
}

// ── HOST / URL RESOLUTION ────────────────────────────
// File URL = host + "/files/" + file_hash + "." + ext
// No preflight fetch — set src directly, onerror tries next host.

function parseHosts(hostsRaw) {
  if (!hostsRaw) return [];
  try {
    const parsed = JSON.parse(hostsRaw);
    if (Array.isArray(parsed)) return parsed.map(h => h.replace(/\/$/, ''));
  } catch(e) {}
  return hostsRaw.split(',').map(h => h.trim().replace(/\/$/, '')).filter(Boolean);
}

function buildUrl(host, fileHash, ext) {
  return host + '/files/' + fileHash + '.' + ext;
}

function shuffleArray(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

function seenParam() {
  return seenHashes.size ? '&seen=' + encodeURIComponent(JSON.stringify([...seenHashes])) : '';
}

function hostLabel(h) {
  try { return new URL(h).hostname; } catch(e) { return h; }
}

// Sets src on mediaEl and retries next host on onerror.
// Works for IMG, VIDEO, AUDIO — no fetch/HEAD needed.
function resolveMedia(mediaEl, cardEl, fileHash, ext, hostsRaw) {
  const hosts     = shuffleArray(parseHosts(hostsRaw));
  const errBadge  = cardEl.querySelector('.host-error');
  const hostBadge = cardEl.querySelector('.badge-host');
  let   attempt   = 0;

  if (!hosts.length) {
    if (errBadge) { errBadge.textContent = '\u2715 No hosts configured'; errBadge.classList.add('show'); }
    return;
  }

  function tryNext() {
    if (attempt >= hosts.length) {
      if (errBadge) { errBadge.textContent = '\u2715 All hosts unavailable'; errBadge.classList.add('show'); }
      if (hostBadge) hostBadge.textContent = 'unavailable';
      return;
    }
    const host = hosts[attempt++];
    const url  = buildUrl(host, fileHash, ext);
    if (hostBadge) hostBadge.textContent = hostLabel(host);

    mediaEl.onerror     = null;
    mediaEl.onload      = null;
    mediaEl.onloadstart = null;

    mediaEl.onerror = function() {
      if (errBadge) {
        errBadge.textContent = '\u2715 ' + hostLabel(host) + ' failed, trying next\u2026';
        errBadge.classList.add('show');
        setTimeout(function(){ errBadge.classList.remove('show'); }, 1200);
      }
      tryNext();
    };

    if (mediaEl.tagName === 'IMG') {
      mediaEl.onload = function() {
        mediaEl.style.opacity = '1';
        if (errBadge) errBadge.classList.remove('show');
        const lnk = cardEl.querySelector('.card-media-link');
        if (lnk) lnk.href = url;
      };
      mediaEl.src = url;
    } else {
      // VIDEO or AUDIO
      mediaEl.onloadstart = function() {
        if (errBadge) errBadge.classList.remove('show');
        const lnk = cardEl.querySelector('.card-media-link');
        if (lnk) lnk.href = url;
      };
      mediaEl.src = url;
      mediaEl.load();
    }
  }

  tryNext();
}

// ── PREFERENCE RECORDING ────────────────────────────

async function recordPreference(fileHash, cardEl) {
  if (!fileHash) return;
  try {
    const res  = await fetch('feed.php?action=record_preference', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({file_hash: fileHash})
    });
    const data = await res.json();
    if (data.ok && data.inserted) {
      const flash = cardEl.querySelector('.pref-flash');
      if (flash) { flash.classList.add('show'); setTimeout(()=>flash.classList.remove('show'), 2500); }
      showToast('★ Saved to your preferences');
    }
  } catch(e) {}
}

// ── WATCH TIMER ──────────────────────────────────────

function startWatchTimer(cardEl, index, fileHash) {
  if (!watchState[index]) watchState[index] = { saved:false, elapsed:0, paused:false };
  const state = watchState[index];
  if (state.saved) return;

  state.paused    = false;
  state.startTime = Date.now();

  const ring = cardEl.querySelector('.ring-fill');
  const C    = 82; // circumference

  function tick() {
    if (watchState[index]?.paused) return;
    const totalElapsed = state.elapsed + (Date.now() - state.startTime) / 1000;
    const progress = Math.min(totalElapsed / WATCH_THRESHOLD, 1);
    if (ring) ring.style.strokeDashoffset = C * (1 - progress);

    if (totalElapsed >= WATCH_THRESHOLD && !state.saved) {
      state.saved = true;
      recordPreference(fileHash, cardEl);
      return;
    }
    state.rafId = requestAnimationFrame(tick);
  }
  state.rafId = requestAnimationFrame(tick);
}

function pauseWatchTimer(index) {
  const state = watchState[index];
  if (!state || state.paused) return;
  state.elapsed += (Date.now() - (state.startTime || Date.now())) / 1000;
  state.startTime = null;
  state.paused = true;
  cancelAnimationFrame(state.rafId);
}

// ── CARD CREATION ────────────────────────────────────

function makeCard(entry, index, isRandom = false) {
  const ext      = (entry.extension || '').toLowerCase();
  const fileHash = entry.file_hash  || '';
  const hosts    = entry.hosts      || '';

  const card = document.createElement('div');
  card.className    = 'card';
  card.dataset.index = index;
  card.dataset.hash  = fileHash;

  // ── MEDIA ──
  const media = document.createElement('div');
  media.className = 'card-media';

  let mediaEl = null;

  if (isImage(ext)) {
    mediaEl = document.createElement('img');
    mediaEl.alt     = entry.original_filename || 'Image';
    mediaEl.loading = 'lazy';
    // placeholder bg while resolving
    mediaEl.style.opacity = '0';
    media.appendChild(mediaEl);

  } else if (isVideo(ext)) {
    mediaEl = document.createElement('video');
    mediaEl.muted       = true;
    mediaEl.loop        = true;
    mediaEl.playsInline = true;
    mediaEl.controls    = false;
    mediaEl.setAttribute('playsinline','');
    media.appendChild(mediaEl);

  } else if (isAudio(ext)) {
    const audioBg = makeAudioVisual();
    media.appendChild(audioBg);
    mediaEl = document.createElement('audio');
    mediaEl.className = 'bg-audio';
    mediaEl.loop      = true;
    mediaEl.preload   = 'none';
    card.appendChild(mediaEl);
  }

  // Kick off host resolution
  if (mediaEl && fileHash && hosts) {
    resolveMedia(mediaEl, card, fileHash, ext, hosts);
  }

  // Tap-to-open link (populated after host resolution updates src)
  const link = document.createElement('a');
  link.className = 'card-media-link';
  link.target    = '_blank';
  link.rel       = 'noopener noreferrer';
  link.title     = 'Open ' + (entry.original_filename || 'file');
  // Keep href in sync with resolved src
  if (mediaEl) {
    const syncHref = () => { if (mediaEl.src) link.href = mediaEl.src; };
    mediaEl.addEventListener('load', syncHref);
    mediaEl.addEventListener('loadedmetadata', syncHref);
  }
  media.appendChild(link);

  card.appendChild(media);

  // ── OVERLAY ──
  const overlay = document.createElement('div');
  overlay.className = 'card-overlay';
  card.appendChild(overlay);

  // ── HOST ERROR / PREF FLASH ──
  const hostErr = document.createElement('div');
  hostErr.className = 'host-error';
  card.appendChild(hostErr);

  const prefFlash = document.createElement('div');
  prefFlash.className = 'pref-flash';
  prefFlash.textContent = '★ SAVED TO PREFERENCES';
  card.appendChild(prefFlash);

  // ── WATCH RING ──
  const ringNS = 'http://www.w3.org/2000/svg';
  const ringEl = document.createElementNS(ringNS, 'svg');
  ringEl.setAttribute('class','watch-ring');
  ringEl.setAttribute('viewBox','0 0 32 32');
  ringEl.innerHTML = `<circle class="ring-bg" cx="16" cy="16" r="13"/>
    <circle class="ring-fill" cx="16" cy="16" r="13"/>`;
  card.appendChild(ringEl);

  // ── INFO ──
  const info = document.createElement('div');
  info.className = 'card-info';

  const fn = document.createElement('div');
  fn.className = 'card-filename';
  fn.textContent = entry.original_filename || fileHash || 'Media';
  info.appendChild(fn);

  const meta = document.createElement('div');
  meta.className = 'card-meta';

  if (isRandom) {
    const bRnd = document.createElement('span');
    bRnd.className = 'badge badge-random';
    bRnd.textContent = '✦ random';
    meta.appendChild(bRnd);
  }
  if (ext) {
    const bExt = document.createElement('span');
    bExt.className = 'badge badge-ext';
    bExt.textContent = ext;
    meta.appendChild(bExt);
  }
  if (entry.file_size) {
    const bSz = document.createElement('span');
    bSz.className = 'badge badge-size';
    bSz.textContent = formatBytes(entry.file_size);
    meta.appendChild(bSz);
  }
  // Host badge — will be filled once resolved
  const bHost = document.createElement('span');
  bHost.className = 'badge badge-host';
  bHost.textContent = '…';
  meta.appendChild(bHost);

  info.appendChild(meta);

  if (entry.description) {
    const desc = document.createElement('div');
    desc.className = 'card-desc';
    desc.textContent = entry.description;
    info.appendChild(desc);
  }
  card.appendChild(info);

  // ── SIDE ACTIONS ──
  const actions = document.createElement('div');
  actions.className = 'card-actions';

  const shareBtn = makeActionBtn('Share', `
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
      <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
      <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
    </svg>`);
  shareBtn.onclick = (e) => {
    e.preventDefault(); e.stopPropagation();
    const url = window.location.origin + window.location.pathname + '?hash=' + fileHash;
    if (navigator.share) {
      navigator.share({title: entry.original_filename, url}).catch(()=>{});
    } else {
      navigator.clipboard.writeText(url).then(()=>showToast('Link copied!')).catch(()=>{});
    }
  };
  actions.appendChild(shareBtn);

  const dlBtn = makeActionBtn('Save', `
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="7 10 12 15 17 10"/>
      <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>`);
  dlBtn.onclick = (e) => {
    e.preventDefault(); e.stopPropagation();
    const src = mediaEl?.src || '';
    if (!src) { showToast('Still loading…'); return; }
    const a = document.createElement('a');
    a.href = src;
    a.download = entry.original_filename || fileHash + '.' + ext;
    a.click();
    showToast('Downloading…');
  };
  actions.appendChild(dlBtn);

  card.appendChild(actions);
  return card;
}

function makeActionBtn(label, svgHtml) {
  const btn  = document.createElement('button');
  btn.className = 'action-btn';
  const icon = document.createElement('div');
  icon.className = 'action-icon';
  icon.innerHTML = svgHtml;
  const lbl  = document.createElement('div');
  lbl.className = 'action-label';
  lbl.textContent = label;
  btn.appendChild(icon);
  btn.appendChild(lbl);
  return btn;
}

function makeAudioVisual() {
  const wrap = document.createElement('div');
  wrap.className = 'audio-bg';
  const disc = document.createElement('div');
  disc.className = 'audio-disc';
  const wf = document.createElement('div');
  wf.className = 'audio-waveform';
  for (let i = 0; i < 12; i++) {
    const s = document.createElement('span');
    s.style.animationDelay    = (i * 0.1) + 's';
    s.style.animationDuration = (0.8 + Math.random()*0.6) + 's';
    wf.appendChild(s);
  }
  wrap.appendChild(disc);
  wrap.appendChild(wf);
  return wrap;
}

function makeLoadingCard() {
  const card = document.createElement('div');
  card.className = 'card';
  card.innerHTML = `<div class="card-loading"><div class="spinner"></div><div class="loading-text">LOADING...</div></div>`;
  return card;
}

function makeEmptyCard() {
  const card = document.createElement('div');
  card.className = 'card';
  card.innerHTML = `<div class="card-empty"><div class="empty-icon">🗂️</div><div class="card-filename">No media found</div><div class="empty-text">No mp3, mp4 or image files are indexed in the database yet.</div></div>`;
  return card;
}

// ── MEDIA PLAY / PAUSE ───────────────────────────────

function handleMediaPlay(card, play) {
  const vid  = card.querySelector('video');
  const aud  = card.querySelector('audio.bg-audio');
  const disc = card.querySelector('.audio-disc');
  const wf   = card.querySelector('.audio-waveform');
  if (vid) { play ? vid.play().catch(()=>{}) : vid.pause(); }
  if (aud) {
    if (play) {
      aud.play().catch(()=>{});
      disc?.classList.remove('paused');
      wf?.classList.remove('paused');
    } else {
      aud.pause();
      disc?.classList.add('paused');
      wf?.classList.add('paused');
    }
  }
}

// ── INTERSECTION OBSERVER ────────────────────────────

let observer;

function setupScrollObserver() {
  observer = new IntersectionObserver((ioEntries) => {
    ioEntries.forEach(e => {
      const idx      = parseInt(e.target.dataset.index);
      const fileHash = e.target.dataset.hash || '';

      if (e.isIntersecting && e.intersectionRatio > 0.5) {
        currentIdx = idx;
        counter.textContent = `${idx+1} of ${totalCount}`;
        updateProgress();
        updateDots();
        handleMediaPlay(e.target, true);
        startWatchTimer(e.target, idx, fileHash);

        // Load next page when 3 cards from end
        if (idx >= entries.length - 3 && !allLoaded && !isFetchingMore) {
          loadMoreEntries();
        }
      } else {
        handleMediaPlay(e.target, false);
        pauseWatchTimer(idx);
      }
    });
  }, { root: feed, threshold: 0.5 });
}

function ensureRenderedUpTo(upTo) {
  const limit = Math.min(upTo, entries.length - 1);
  while (rendered <= limit) {
    const entry = entries[rendered];
    const card  = makeCard(entry.data, rendered, entry.isRandom);
    feed.appendChild(card);
    observer.observe(card);
    rendered++;
  }
}

// ── DATA LOADING ─────────────────────────────────────

async function loadMoreEntries() {
  if (isFetchingMore || allLoaded) return;
  isFetchingMore = true;
  try {
    const seen = seenParam();
    const [pageData, randomEntry] = await Promise.all([
      fetch(`feed.php?action=feed&page=${currentPage + 1}${seen}`).then(r => r.json()),
      fetch(`feed.php?action=random${seen}`).then(r => r.json())
    ]);
    currentPage++;

    if (!pageData.entries?.length) {
      allLoaded = true;
    } else {
      if (randomEntry && !seenHashes.has(randomEntry.file_hash)) {
        seenHashes.add(randomEntry.file_hash);
        entries.push({ data: randomEntry, isRandom: true });
      }
      for (const e of pageData.entries) {
        if (!seenHashes.has(e.file_hash)) {
          seenHashes.add(e.file_hash);
          entries.push({ data: e, isRandom: false });
        }
      }
      persistSeen();
      totalCount = pageData.total;
      ensureRenderedUpTo(rendered + 1);
    }
  } catch(err) {
    console.error('loadMore failed:', err);
  } finally {
    isFetchingMore = false;
  }
}

// ── INIT ─────────────────────────────────────────────

async function init() {
  const loadCard = makeLoadingCard();
  feed.appendChild(loadCard);
  try {
    // &reset=1 tells PHP to generate a new RAND() seed → different order each page load
    const data = await fetch('feed.php?action=feed&page=1&reset=1').then(r => r.json());
    feed.removeChild(loadCard);

    if (!data.entries?.length) {
      feed.appendChild(makeEmptyCard());
      counter.textContent = 'empty';
      return;
    }

    totalCount = data.total;
    for (const e of data.entries) {
      seenHashes.add(e.file_hash);
      entries.push({ data: e, isRandom: false });
    }
    persistSeen();

    counter.textContent = `1 of ${totalCount}`;
    setupScrollObserver();
    ensureRenderedUpTo(entries.length - 1); // render all fetched entries upfront
    updateProgress();
    updateDots();
  } catch(err) {
    feed.removeChild(loadCard);
    feed.appendChild(makeEmptyCard());
    console.error('Init failed:', err);
  }
}

init();
</script>
</body>
</html>