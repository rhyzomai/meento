<?php
// ── config ───────────────────────────────────────────────────────────────────
const SERVERS_FILE  = __DIR__ . '/servers.txt';
const IP_HASH_DIR   = __DIR__ . '/tmp_user_ip_hash/';
const SALT          = 'mN#9xQ@2pL!kR7vZ';   // change to your own secret salt
const MAX_URL_LEN   = 512;

// ── helpers ──────────────────────────────────────────────────────────────────
function getUserIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return '0.0.0.0';
}

function ipHashFile(string $ip): string {
    return IP_HASH_DIR . hash('sha256', SALT . $ip) . '.lock';
}

function userAlreadySubmitted(): bool {
    return file_exists(ipHashFile(getUserIP()));
}

function markUserSubmitted(): void {
    if (!is_dir(IP_HASH_DIR)) {
        mkdir(IP_HASH_DIR, 0750, true);
    }
    // store timestamp inside the file for auditing, but name is the hash
    file_put_contents(ipHashFile(getUserIP()), date('Y-m-d H:i:s') . "\n");
}

function countServers(): int {
    if (!file_exists(SERVERS_FILE)) return 0;
    $lines = array_filter(array_map('trim', file(SERVERS_FILE)));
    return count($lines);
}

function isValidUrl(string $url): bool {
    return (bool) filter_var($url, FILTER_VALIDATE_URL) &&
           preg_match('#^https?://#i', $url);
}

// ── handle POST ──────────────────────────────────────────────────────────────
$message = '';
$msgType = '';
$already = userAlreadySubmitted();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {
    $url = trim($_POST['server_url'] ?? '');

    if ($url === '') {
        $message = 'URL cannot be empty.';
        $msgType = 'fail';
    } elseif (strlen($url) > MAX_URL_LEN) {
        $message = 'URL exceeds ' . MAX_URL_LEN . ' characters (' . strlen($url) . ' given).';
        $msgType = 'fail';
    } elseif (!isValidUrl($url)) {
        $message = 'Invalid URL — must start with http:// or https:// and be a valid address.';
        $msgType = 'fail';
    } else {
        // write URL to servers.txt using fopen
        $fh = fopen(SERVERS_FILE, 'a');
        if ($fh === false) {
            $message = 'Could not open servers.txt for writing. Check directory permissions.';
            $msgType = 'fail';
        } else {
            fwrite($fh, $url . "\n");
            fclose($fh);
            markUserSubmitted();
            $already = true;
            $message = 'Server URL registered successfully.';
            $msgType = 'ok';
        }
    }
}

$serverCount = countServers();
$userIP      = getUserIP();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mesh Node — Server Registry</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}

.page{max-width:36rem;width:100%}

/* header */
header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}
h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.sub{font-size:0.8rem;color:#777;margin-top:0.2rem}

/* node bar */
#node-bar{display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.75rem;font-size:0.8rem;color:#555}
.nb-pill{display:flex;align-items:center;gap:0.35rem;padding:0.25rem 0.6rem;background:#f5f5f5;border-radius:4px;border:1px solid #e0e0e0}
.nb-dot{width:7px;height:7px;border-radius:50%;background:#aaa}
.nb-dot.ok{background:#1a8e3f}
.nb-dot.blue{background:#0044cc}
.nb-dot.warn{background:#c87000}
.nb-val{font-weight:500}

/* status message */
.status-msg{margin-bottom:1.25rem;padding:0.8rem 1rem;border-radius:4px;font-size:0.9rem;line-height:1.5;animation:fadeUp 0.25s ease;display:flex;align-items:flex-start;gap:0.55rem}
.status-msg.ok{background:#f0fff0;border:1px solid #c0e0c0;color:#1a8e3f}
.status-msg.fail{background:#fff0f0;border:1px solid #e0c0c0;color:#d00}
.status-msg.warn{background:#fff8f0;border:1px solid #f0c080;color:#c87000}
.status-msg svg{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;margin-top:0.15rem}

/* card */
.card{border:1px solid #ddd;border-radius:6px;overflow:hidden;margin-bottom:1.25rem}
.card-head{display:flex;align-items:center;gap:0.75rem;padding:0.8rem 1rem;border-bottom:1px solid #eee;background:#fafafa}
.card-icon{width:2rem;height:2rem;background:#f0f4ff;border-radius:4px;display:grid;place-items:center;flex-shrink:0;color:#0044cc}
.card-icon.locked-icon{background:#fff8f0;color:#c87000}
.card-title{font-size:0.9rem;font-weight:600;flex:1}

.lock-badge{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.72rem;padding:0.15rem 0.55rem;border-radius:4px;border:1px solid #f0c080;background:#fff8f0;color:#c87000;font-weight:600}
.lock-badge svg{width:0.75rem;height:0.75rem;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.open-badge{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.72rem;padding:0.15rem 0.55rem;border-radius:4px;border:1px solid #c0e0c0;background:#f0fff0;color:#1a8e3f;font-weight:600}
.open-badge svg{width:0.75rem;height:0.75rem;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}

/* body */
.card-body{padding:1rem}
label.fl{display:block;font-size:0.75rem;color:#777;margin-bottom:0.5rem}

.field-wrap{position:relative}
.field-prefix{position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);pointer-events:none}
.field-prefix svg{width:0.9rem;height:0.9rem;stroke:#aaa;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.field-input{width:100%;padding:0.6rem 0.8rem 0.6rem 2.2rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;outline:none;transition:border-color 0.2s;background:#fff;color:#111}
.field-input:focus{border-color:#0044cc}
.field-input.locked{background:#f9f9f9;color:#777;border-color:#e0e0e0;cursor:not-allowed;padding-left:2.2rem}

.cmeta{display:flex;justify-content:space-between;align-items:center;margin-top:0.4rem;font-size:0.73rem;color:#999}
.ccount{transition:color 0.2s}
.ccount.over{color:#d00}
.hint{line-height:1.5}

/* footer */
.form-footer{padding:0.8rem 1rem;border-top:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:0.8rem;background:#fafafa}
.form-note{font-size:0.8rem;color:#777}
.form-note b{color:#555;font-weight:600}

.btn-save{padding:0.5rem 1.2rem;background:#0044cc;color:#fff;border:none;border-radius:4px;font-weight:600;font-size:0.85rem;cursor:pointer;transition:opacity 0.2s;display:flex;align-items:center;gap:0.4rem}
.btn-save:hover{opacity:0.88}
.btn-save:disabled{opacity:0.4;cursor:not-allowed}
.btn-save svg{width:1rem;height:1rem;stroke:#fff;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* servers list */
.info-box{border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-top:0.25rem}
.info-box-head{padding:0.5rem 1rem;border-bottom:1px solid #eee;font-size:0.75rem;color:#777;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:0.4rem}
.info-box-head-left{display:flex;align-items:center;gap:0.4rem}
.info-box-head svg{width:0.9rem;height:0.9rem;stroke:#777;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.info-box-count{font-size:0.72rem;padding:0.1rem 0.5rem;border-radius:4px;background:#f0f4ff;border:1px solid #d0dcff;color:#0044cc;font-weight:600}

.server-list{list-style:none;margin:0;padding:0}
.server-list li{display:flex;align-items:center;gap:0.6rem;padding:0.6rem 1rem;border-bottom:1px solid #f0f0f0;font-size:0.82rem;font-family:"SF Mono","Fira Code","Fira Mono","Roboto Mono",monospace;color:#333;word-break:break-all}
.server-list li:last-child{border-bottom:none}
.server-list li:hover{background:#fafafa}
.s-dot{width:6px;height:6px;border-radius:50%;background:#1a8e3f;flex-shrink:0}
.s-num{font-size:0.7rem;color:#bbb;min-width:1.4rem;flex-shrink:0}
.empty-state{padding:1.5rem 1rem;text-align:center;font-size:0.82rem;color:#aaa}

/* ip note */
.ip-note{margin-top:1rem;padding:0.65rem 1rem;border-radius:4px;background:#f5f5f5;border:1px solid #e8e8e8;font-size:0.75rem;color:#888;display:flex;align-items:center;gap:0.5rem}
.ip-note svg{width:0.85rem;height:0.85rem;stroke:#bbb;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}

footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}

@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="page">

  <header>
    <div>
      <h1>Mesh Node <em>/ registry</em></h1>
      <p class="sub">Submit a server URL to the network peer list</p>
    </div>
  </header>

  <!-- status bar -->
  <div id="node-bar">
    <div class="nb-pill">
      <span class="nb-dot blue"></span>
      <span>registry</span>
    </div>
    <div class="nb-pill">
      <span class="nb-dot ok"></span>
      <span>servers.txt <span class="nb-val"><?= $serverCount ?> entr<?= $serverCount === 1 ? 'y' : 'ies' ?></span></span>
    </div>
    <div class="nb-pill">
      <span class="nb-dot <?= $already ? 'warn' : 'ok' ?>"></span>
      <span>your slot <span class="nb-val"><?= $already ? 'used' : 'open' ?></span></span>
    </div>
  </div>

  <!-- feedback -->
  <?php if ($message): ?>
  <div class="status-msg <?= htmlspecialchars($msgType) ?>">
    <?php if ($msgType === 'ok'): ?>
      <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    <?php elseif ($msgType === 'fail'): ?>
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?php endif; ?>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- already submitted banner -->
  <?php if ($already && !$message): ?>
  <div class="status-msg warn">
    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Your IP has already submitted a server URL. Only one submission per host is allowed.
  </div>
  <?php endif; ?>

  <!-- form card -->
  <form method="POST" action="">
    <div class="card">
      <div class="card-head">
        <div class="card-icon <?= $already ? 'locked-icon' : '' ?>">
          <?php if ($already): ?>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <?php else: ?>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <?php endif; ?>
        </div>
        <span class="card-title">Server URL</span>
        <?php if ($already): ?>
          <span class="lock-badge">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            locked
          </span>
        <?php else: ?>
          <span class="open-badge">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            writable
          </span>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <label class="fl" for="server_url">
          <?= $already ? 'Submission window closed for this host' : 'Enter the full URL of your server node' ?>
        </label>
        <div class="field-wrap">
          <span class="field-prefix">
            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </span>
          <input
            type="url"
            id="server_url"
            name="server_url"
            class="field-input<?= $already ? ' locked' : '' ?>"
            placeholder="https://node.example.com"
            autocomplete="off"
            spellcheck="false"
            maxlength="<?= MAX_URL_LEN ?>"
            <?= $already ? 'disabled' : 'oninput="updateCount(this.value)"' ?>
          >
        </div>
        <div class="cmeta">
          <span class="hint"><?= $already ? 'One submission allowed per IP address.' : 'Must begin with http:// or https://' ?></span>
          <?php if (!$already): ?>
            <span id="url-count" class="ccount">0 / <?= MAX_URL_LEN ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$already): ?>
      <div class="form-footer">
        <p class="form-note">Appends to <b>servers.txt</b></p>
        <button type="submit" class="btn-save">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add server
        </button>
      </div>
      <?php endif; ?>
    </div>
  </form>

  <!-- servers list -->
  <div class="info-box">
    <div class="info-box-head">
      <div class="info-box-head-left">
        <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        servers.txt — registered nodes
      </div>
      <span class="info-box-count"><?= $serverCount ?></span>
    </div>
    <?php
      $entries = [];
      if (file_exists(SERVERS_FILE)) {
          $entries = array_values(array_filter(array_map('trim', file(SERVERS_FILE))));
      }
    ?>
    <?php if ($entries): ?>
    <ul class="server-list">
      <?php foreach ($entries as $i => $line): ?>
      <li>
        <span class="s-num"><?= $i + 1 ?></span>
        <span class="s-dot"></span>
        <?= htmlspecialchars($line) ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="empty-state">No servers registered yet.</div>
    <?php endif; ?>
  </div>

  <!-- ip note -->
  <div class="ip-note">
    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Your session is identified by a salted SHA-256 hash of your IP address. Raw IPs are never stored.
  </div>

  <footer>
    <span>mesh-node · server registry</span>
    <span>one submission per host</span>
  </footer>

</div>

<script>
function updateCount(val) {
  var el = document.getElementById('url-count');
  if (!el) return;
  var len = val.length;
  el.textContent = len + ' / <?= MAX_URL_LEN ?>';
  el.classList.toggle('over', len >= <?= MAX_URL_LEN - 22 ?>);
}
</script>
</body>
</html>