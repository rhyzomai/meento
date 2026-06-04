<?php
// ============================================================
//  View — PHP 7+
//  Searches across data.json, data_2.json, data_3.json …
// ============================================================

$dataDir  = __DIR__ . '/';
$thumbDir = __DIR__ . '/thumbs/';
$filesDir = __DIR__ . '/files/';

// ── helpers ──────────────────────────────────────────────────

function dataFilePath(int $n): string {
    global $dataDir;
    return $dataDir . ($n === 1 ? 'data.json' : "data_{$n}.json");
}

function maxDataIndex(): int {
    $n = 1;
    while (file_exists(dataFilePath($n))) $n++;
    return $n - 1;
}

function loadDataFile(int $n): array {
    $path = dataFilePath($n);
    if (!file_exists($path)) return [];
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

// ── JSON search endpoint ──────────────────────────────────────
if (isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q    = strtolower(trim($_GET['q']));
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $max  = maxDataIndex();

    if ($page < 1 || $page > max(1, $max)) {
        echo json_encode(['results' => [], 'max' => $max, 'page' => $page]);
        exit;
    }

    $data    = loadDataFile($page);
    $results = [];

    if ($q !== '') {
        foreach ($data as $item) {
            if (strpos(strtolower(json_encode($item)), $q) !== false) {
                $results[] = $item;
            }
        }
    } else {
        $results = $data;
    }

    echo json_encode(['results' => $results, 'max' => $max, 'page' => $page]);
    exit;
}

// ── page-count endpoint ───────────────────────────────────────
if (isset($_GET['maxpage'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['max' => maxDataIndex()]);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mesh Node — View</title>
<style>
/* minimalist reset */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}

/* container */
.page{max-width:52rem;width:100%}

/* header */
header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}
h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.sub{font-size:0.8rem;color:#777;margin-top:0.2rem}
.upload-link{font-size:0.85rem;color:#0044cc;text-decoration:none;white-space:nowrap}
.upload-link:hover{text-decoration:underline}

/* search bar */
.search-wrap{position:relative;margin-bottom:1.5rem}
.search-wrap svg{position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:#aaa;pointer-events:none}
#searchInput{width:100%;padding:0.55rem 0.85rem 0.55rem 2.4rem;border:1px solid #ddd;border-radius:4px;font-family:inherit;font-size:0.9rem;color:#111;outline:none;transition:border-color 0.2s,box-shadow 0.2s}
#searchInput:focus{border-color:#0044cc;box-shadow:0 0 0 3px rgba(0,68,204,.1)}
#searchInput::placeholder{color:#bbb}

/* pagination */
.pagination{display:flex;align-items:center;justify-content:center;gap:0.6rem;margin-bottom:1.5rem}
.pagination.hidden{display:none}
.page-btn{display:inline-flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid #ddd;border-radius:4px;background:#fff;font-family:inherit;font-size:0.75rem;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#111;cursor:pointer;transition:background 0.15s,border-color 0.15s,color 0.15s}
.page-btn:hover:not(:disabled){background:#0044cc;border-color:#0044cc;color:#fff}
.page-btn:disabled{opacity:0.3;cursor:not-allowed}
.page-info{font-size:0.78rem;color:#777;min-width:80px;text-align:center}

/* results list */
#resultsList{display:flex;flex-direction:column;gap:0.75rem}

/* card */
.card{display:flex;flex-direction:row;border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;text-decoration:none;color:#111;background:#fff;transition:box-shadow 0.18s,border-color 0.18s;animation:fadeUp 0.22s ease both}
.card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);border-color:#bbb}

/* left: thumbnail only */
.card-left{width:160px;flex-shrink:0;border-right:1px solid #eee;background:#f5f5f5;position:relative}
.thumb-wrap{width:100%;height:100%;min-height:110px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.thumb-wrap img{width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.25s ease}
.card:hover .thumb-wrap img{transform:scale(1.04)}
.thumb-placeholder{display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:110px;color:#ccc}

/* right: all info */
.card-right{flex:1;padding:0.85rem 1rem;display:flex;flex-direction:column;gap:0.55rem;overflow:hidden;min-width:0}

/* filename row */
.file-title{font-size:0.9rem;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.file-meta{display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap;margin-top:0.1rem}
.ext-badge{display:inline-block;padding:0.15rem 0.45rem;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:3px;font-size:0.68rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#555}
.file-size{font-size:0.75rem;color:#888}

/* divider */
.card-divider{height:1px;background:#f0f0f0;margin:0.1rem 0}

/* field rows (optional fields) */
.field-row{display:flex;flex-direction:column;gap:0.08rem;overflow:hidden}
.field-label{font-size:0.62rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#aaa}
.field-value{font-size:0.8rem;color:#333;line-height:1.5;word-break:break-all}
.field-value.mono{font-family:"SF Mono","Fira Code","Roboto Mono",monospace;font-size:0.72rem;color:#777}

/* state messages */
.state-msg{text-align:center;color:#aaa;font-size:0.88rem;padding:3.5rem 0}
.state-msg svg{margin:0 auto 0.7rem;display:block;opacity:0.35}

/* progress bar */
#prog-wrap{height:3px;background:#eee;border-radius:3px;overflow:hidden;display:none;margin-top:0.5rem}
#prog-bar{height:100%;width:0%;background:#0044cc;transition:width 0.1s linear}

/* footer */
footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}

/* responsive: stack on mobile */
@media(max-width:560px){
  .card{flex-direction:column}
  .card-left{width:100%;border-right:none;border-bottom:1px solid #eee;min-height:140px}
  .thumb-wrap{min-height:140px}
  .thumb-placeholder{min-height:140px}
}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<div class="page">

  <header>
    <div>
      <h1>Mesh <em>Node</em></h1>
      <div class="sub">Browse and search your media catalogue</div>
    </div>
    <a class="upload-link" href="index.php">↑ Upload</a>
  </header>

  <!-- Search -->
  <div class="search-wrap">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input id="searchInput" type="text" placeholder="Search by name, description, hash…" autocomplete="off" autofocus>
  </div>

  <!-- Pagination -->
  <div class="pagination hidden" id="pagination">
    <button class="page-btn" id="prevBtn" disabled>
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Prev
    </button>
    <span class="page-info" id="pageInfo">— / —</span>
    <button class="page-btn" id="nextBtn" disabled>
      Next
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  </div>

  <!-- Results -->
  <div id="resultsList">
    <div class="state-msg">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      Start typing to search the catalogue.
    </div>
  </div>

  <footer>
    <span>&copy; <?= date('Y') ?> Mesh Node</span>
    <span>All rights reserved</span>
  </footer>

</div><!-- /.page -->

<script>
(function () {
'use strict';

const list      = document.getElementById('resultsList');
const input     = document.getElementById('searchInput');
const pagination= document.getElementById('pagination');
const prevBtn   = document.getElementById('prevBtn');
const nextBtn   = document.getElementById('nextBtn');
const pageInfo  = document.getElementById('pageInfo');

let currentPage = 1;
let maxPage     = 1;
let currentQ    = '';
let searchTimer = null;

// ── utilities ───────────────────────────────────────────────

function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024)         return bytes + ' B';
  if (bytes < 1024 * 1024)  return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// ── card builder ─────────────────────────────────────────────

function buildCard(item) {
  const hash = item.hash || item._file || '';
  const ext  = (item.extension || 'bin').toLowerCase();
  const href = 'files/' + encodeURIComponent(hash) + '.' + ext;

  const imgExts = ['jpg','jpeg','png','gif','webp','bmp','svg','avif'];
  const thumb   = imgExts.includes(ext)
    ? 'files/' + encodeURIComponent(hash) + '.' + ext
    : 'thumbs/' + encodeURIComponent(hash) + '.jpg';

  const title    = item.original_filename || hash || 'Untitled';
  const sizeStr  = fmtSize(item.size);
  const desc     = (item.description || '').trim();
  const pubKey   = (item.public_key  || '').trim();
  const nodeInfo = (item.node_info   || '').trim();

  // Optional field — only rendered when value is non-empty
  const optField = (label, value, mono = false) => {
    if (!value) return '';
    return `<div class="field-row">
      <div class="field-label">${label}</div>
      <div class="field-value${mono ? ' mono' : ''}">${esc(value)}</div>
    </div>`;
  };

  const hasOptional = desc || pubKey || nodeInfo;

  return `
<a class="card" href="${esc(href)}" target="_blank" rel="noopener">

  <!-- LEFT: thumbnail only -->
  <div class="card-left">
    <div class="thumb-wrap">
      <img src="${esc(thumb)}" alt="${esc(title)}"
           loading="lazy"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
      <div class="thumb-placeholder" style="display:none">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
      </div>
    </div>
  </div>

  <!-- RIGHT: filename, extension, size + optional fields -->
  <div class="card-right">
    <div class="file-title" title="${esc(title)}">${esc(title)}</div>
    <div class="file-meta">
      ${ext      ? `<span class="ext-badge">${esc(ext)}</span>` : ''}
      ${sizeStr  ? `<span class="file-size">${esc(sizeStr)}</span>` : ''}
    </div>

    ${hasOptional ? '<div class="card-divider"></div>' : ''}
    ${optField('Description', desc)}
    ${optField('Public Key',  pubKey,  true)}
    ${optField('Node Info',   nodeInfo)}
  </div>

</a>`;
}

// ── render helpers ───────────────────────────────────────────

function renderEmpty(q) {
  const msg = q
    ? `No results for <strong>${esc(q)}</strong> on this page.`
    : 'No entries in this file.';
  list.innerHTML = `<div class="state-msg">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    ${msg}
  </div>`;
}

function renderHint() {
  list.innerHTML = `<div class="state-msg">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    Start typing to search the catalogue.
  </div>`;
}

function updateNav() {
  if (maxPage <= 1) { pagination.classList.add('hidden'); return; }
  pagination.classList.remove('hidden');
  pageInfo.textContent = 'File ' + currentPage + ' / ' + maxPage;
  prevBtn.disabled = (currentPage <= 1);
  nextBtn.disabled = (currentPage >= maxPage);
}

// ── fetch & display ──────────────────────────────────────────

function search(q, page) {
  const url = '?q=' + encodeURIComponent(q) + '&p=' + page;
  fetch(url)
    .then(r => r.json())
    .then(data => {
      maxPage     = data.max  || 1;
      currentPage = data.page || page;
      updateNav();

      const items = data.results || [];
      if (!items.length) { renderEmpty(q); return; }

      list.innerHTML = items.map((item, i) =>
        buildCard(item).replace('class="card"', `class="card" style="animation-delay:${i * 0.04}s"`)
      ).join('');
    })
    .catch(() => {
      list.innerHTML = '<div class="state-msg">Search error — please try again.</div>';
    });
}

// ── init ─────────────────────────────────────────────────────

fetch('?maxpage=1')
  .then(r => r.json())
  .then(d => { maxPage = d.max || 1; updateNav(); })
  .catch(() => {});

// ── events ───────────────────────────────────────────────────

input.addEventListener('input', function () {
  clearTimeout(searchTimer);
  currentQ = this.value.trim();
  currentPage = 1;
  if (!currentQ) { renderHint(); updateNav(); return; }
  searchTimer = setTimeout(() => search(currentQ, currentPage), 220);
});

prevBtn.addEventListener('click', () => {
  if (currentPage <= 1) return;
  currentPage--;
  search(currentQ, currentPage);
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

nextBtn.addEventListener('click', () => {
  if (currentPage >= maxPage) return;
  currentPage++;
  search(currentQ, currentPage);
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

})();
</script>
</body>
</html>