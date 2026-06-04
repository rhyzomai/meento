<?php
// ── file paths ──────────────────────────────────────────────────────────────
$PUBLIC_KEY_FILE = __DIR__ . '/public_key.txt';
$NODE_INFO_FILE  = __DIR__ . '/node_info.txt';

// ── helpers ─────────────────────────────────────────────────────────────────
function readFileTxt(string $path): string {
    return file_exists($path) ? trim(file_get_contents($path)) : '';
}

function fileExists_notempty(string $path): bool {
    return file_exists($path) && trim(file_get_contents($path)) !== '';
}

// ── handle POST ──────────────────────────────────────────────────────────────
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pk_locked   = fileExists_notempty($PUBLIC_KEY_FILE);
    $ni_locked   = fileExists_notempty($NODE_INFO_FILE);
    $saved_any   = false;
    $errors      = [];

    if (!$pk_locked && isset($_POST['public_key'])) {
        $val = trim($_POST['public_key']);
        if ($val === '') {
            $errors[] = 'Public Key cannot be empty.';
        } elseif (strlen($val) > 512) {
            $errors[] = 'Public Key exceeds 512 characters (' . strlen($val) . ' given).';
        } else {
            file_put_contents($PUBLIC_KEY_FILE, $val);
            $saved_any = true;
        }
    }

    if (!$ni_locked && isset($_POST['node_info'])) {
        $val = trim($_POST['node_info']);
        if ($val === '') {
            $errors[] = 'Node Info cannot be empty.';
        } elseif (strlen($val) > 512) {
            $errors[] = 'Node Info exceeds 512 characters (' . strlen($val) . ' given).';
        } else {
            file_put_contents($NODE_INFO_FILE, $val);
            $saved_any = true;
        }
    }

    if ($errors) {
        $message = implode(' ', $errors);
        $msgType = 'fail';
    } elseif ($saved_any) {
        $message = 'Values saved successfully to disk.';
        $msgType = 'ok';
    } else {
        $message = 'No changes — fields are locked or nothing was submitted.';
        $msgType = 'fail';
    }
}

// ── read current state ───────────────────────────────────────────────────────
$pk_value  = readFileTxt($PUBLIC_KEY_FILE);
$ni_value  = readFileTxt($NODE_INFO_FILE);
$pk_locked = fileExists_notempty($PUBLIC_KEY_FILE);
$ni_locked = fileExists_notempty($NODE_INFO_FILE);
$all_locked = $pk_locked && $ni_locked;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mesh Node — Config</title>
<style>
/* ── reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}

/* ── container ── */
.page{max-width:36rem;width:100%}

/* ── header ── */
header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}
h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.sub{font-size:0.8rem;color:#777;margin-top:0.2rem}

/* ── node status bar ── */
#node-bar{display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.75rem;font-size:0.8rem;color:#555}
.nb-pill{display:flex;align-items:center;gap:0.35rem;padding:0.25rem 0.6rem;background:#f5f5f5;border-radius:4px;border:1px solid #e0e0e0}
.nb-dot{width:7px;height:7px;border-radius:50%;background:#aaa}
.nb-dot.ok{background:#1a8e3f}
.nb-dot.blue{background:#0044cc}
.nb-dot.warn{background:#c87000}
.nb-val{font-weight:500}

/* ── form card ── */
.card{border:1px solid #ddd;border-radius:6px;overflow:hidden;margin-bottom:1.25rem}
.card-head{display:flex;align-items:center;gap:0.75rem;padding:0.8rem 1rem;border-bottom:1px solid #eee;background:#fafafa}
.card-icon{width:2rem;height:2rem;background:#f0f4ff;border-radius:4px;display:grid;place-items:center;flex-shrink:0;font-size:1rem;color:#0044cc}
.card-icon.locked-icon{background:#fff8f0;color:#c87000}
.card-title{font-size:0.9rem;font-weight:600;flex:1}
.lock-badge{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.72rem;padding:0.15rem 0.55rem;border-radius:4px;border:1px solid #f0c080;background:#fff8f0;color:#c87000;font-weight:600}
.lock-badge svg{width:0.75rem;height:0.75rem;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.open-badge{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.72rem;padding:0.15rem 0.55rem;border-radius:4px;border:1px solid #c0e0c0;background:#f0fff0;color:#1a8e3f;font-weight:600}
.open-badge svg{width:0.75rem;height:0.75rem;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}

/* ── field body ── */
.card-body{padding:1rem}
label.fl{display:block;font-size:0.75rem;color:#777;margin-bottom:0.5rem}

/* editable input */
.field-input{width:100%;padding:0.6rem 0.8rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;outline:none;transition:border-color 0.2s;background:#fff;color:#111}
.field-input:focus{border-color:#0044cc}
.field-input.locked{background:#f9f9f9;color:#555;border-color:#e0e0e0;cursor:not-allowed}

/* value display (when locked) */
.value-display{font-family:"SF Mono","Fira Code","Fira Mono","Roboto Mono",monospace;font-size:0.82rem;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:4px;padding:0.65rem 0.85rem;color:#333;word-break:break-all;line-height:1.6}

.hint{font-size:0.73rem;color:#999;margin-top:0.4rem}

/* ── panel footer / submit ── */
.form-footer{padding:0.8rem 1rem;border-top:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:0.8rem;background:#fafafa}
.form-note{font-size:0.8rem;color:#777}
.form-note b{font-weight:600;color:#555}
.btn-save{padding:0.5rem 1.2rem;background:#0044cc;color:#fff;border:none;border-radius:4px;font-weight:600;font-size:0.85rem;cursor:pointer;transition:opacity 0.2s;display:flex;align-items:center;gap:0.4rem}
.btn-save:hover{opacity:0.88}
.btn-save:disabled{opacity:0.4;cursor:not-allowed}
.btn-save svg{width:1rem;height:1rem;stroke:#fff;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* ── status message ── */
.status-msg{display:none;margin-bottom:1.25rem;padding:0.8rem 1rem;border-radius:4px;font-size:0.9rem;line-height:1.5;animation:fadeUp 0.25s ease}
.status-msg.ok{background:#f0fff0;border:1px solid #c0e0c0;color:#1a8e3f;display:block}
.status-msg.fail{background:#fff0f0;border:1px solid #e0c0c0;color:#d00;display:block}
.status-msg svg{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-0.15rem;margin-right:0.3rem}

/* ── all-locked info box ── */
.info-box{border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-top:0.5rem}
.info-box-head{padding:0.5rem 1rem;border-bottom:1px solid #eee;font-size:0.75rem;color:#777;background:#fafafa;display:flex;align-items:center;gap:0.4rem}
.info-box-head svg{width:0.9rem;height:0.9rem;stroke:#777;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
pre.json-pre{padding:1rem;font-family:"SF Mono","Fira Code","Fira Mono","Roboto Mono",monospace;font-size:0.8rem;line-height:1.6;color:#333;overflow-x:auto;margin:0}
.jk{color:#0044cc}.js{color:#1a8e3f}.jn{color:#b04000}

/* ── footer ── */
footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}

@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="page">

  <!-- header -->
  <header>
    <div>
      <h1>Mesh Node <em>/ config</em></h1>
      <p class="sub">Node identity &amp; key provisioning</p>
    </div>
  </header>

  <!-- status bar -->
  <div id="node-bar">
    <div class="nb-pill">
      <span class="nb-dot blue"></span>
      <span>config</span>
    </div>
    <div class="nb-pill">
      <span class="nb-dot <?= $pk_locked ? 'ok' : 'warn' ?>"></span>
      <span>public_key <span class="nb-val"><?= $pk_locked ? 'set' : 'empty' ?></span></span>
    </div>
    <div class="nb-pill">
      <span class="nb-dot <?= $ni_locked ? 'ok' : 'warn' ?>"></span>
      <span>node_info <span class="nb-val"><?= $ni_locked ? 'set' : 'empty' ?></span></span>
    </div>
  </div>

  <!-- feedback message -->
  <?php if ($message): ?>
  <div class="status-msg <?= htmlspecialchars($msgType) ?>">
    <?php if ($msgType === 'ok'): ?>
      <!-- checkmark -->
      <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    <?php else: ?>
      <!-- x -->
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    <?php endif; ?>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- form -->
  <form method="POST" action="">

    <!-- ── Public Key card ── -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon <?= $pk_locked ? 'locked-icon' : '' ?>">
          <?php if ($pk_locked): ?>
            <!-- lock -->
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <?php else: ?>
            <!-- key -->
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3"/></svg>
          <?php endif; ?>
        </div>
        <span class="card-title">Public Key</span>
        <?php if ($pk_locked): ?>
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
        <label class="fl" for="public_key">
          <?= $pk_locked ? 'Stored value in <code>public_key.txt</code>' : 'Enter value to write to <code>public_key.txt</code>' ?>
        </label>
        <?php if ($pk_locked): ?>
          <div class="value-display"><?= htmlspecialchars($pk_value) ?></div>
          <p class="hint">This file already has content — the field is locked.</p>
        <?php else: ?>
          <input
            type="text"
            id="public_key"
            name="public_key"
            class="field-input"
            placeholder="e.g. ssh-ed25519 AAAAC3Nza…"
            autocomplete="off"
            spellcheck="false"
            maxlength="512"
            oninput="updateCount('public_key','pk-count')"
          >
          <div class="cmeta">
            <p class="hint">Once saved, this field will be permanently locked.</p>
            <span id="pk-count" class="ccount">0 / 512</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Node Info card ── -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon <?= $ni_locked ? 'locked-icon' : '' ?>">
          <?php if ($ni_locked): ?>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <?php else: ?>
            <!-- server icon -->
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          <?php endif; ?>
        </div>
        <span class="card-title">Node Info</span>
        <?php if ($ni_locked): ?>
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
        <label class="fl" for="node_info">
          <?= $ni_locked ? 'Stored value in <code>node_info.txt</code>' : 'Enter value to write to <code>node_info.txt</code>' ?>
        </label>
        <?php if ($ni_locked): ?>
          <div class="value-display"><?= htmlspecialchars($ni_value) ?></div>
          <p class="hint">This file already has content — the field is locked.</p>
        <?php else: ?>
          <input
            type="text"
            id="node_info"
            name="node_info"
            class="field-input"
            placeholder="e.g. node-01 | region=us-east | role=relay"
            autocomplete="off"
            spellcheck="false"
            maxlength="512"
            oninput="updateCount('node_info','ni-count')"
          >
          <div class="cmeta">
            <p class="hint">Once saved, this field will be permanently locked.</p>
            <span id="ni-count" class="ccount">0 / 512</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── form footer ── -->
    <?php if (!$all_locked): ?>
    <div class="form-footer" style="border:1px solid #ddd;border-radius:6px;margin-bottom:1.25rem">
      <p class="form-note">Writing to <b><?= !$pk_locked && !$ni_locked ? 'both files' : (!$pk_locked ? 'public_key.txt' : 'node_info.txt') ?></b></p>
      <button type="submit" class="btn-save">
        <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save to disk
      </button>
    </div>
    <?php endif; ?>

  </form>

  <!-- ── JSON preview of both files ── -->
  <div class="info-box">
    <div class="info-box-head">
      <svg viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
      Current file contents
    </div>
    <pre class="json-pre"><?php
      $pk_disp = $pk_value !== '' ? htmlspecialchars($pk_value) : '<span style="color:#aaa">— empty —</span>';
      $ni_disp = $ni_value !== '' ? htmlspecialchars($ni_value) : '<span style="color:#aaa">— empty —</span>';
      echo '<span class="jk">{</span>' . "\n";
      echo '  <span class="jk">"public_key.txt"</span>: <span class="js">"' . $pk_disp . '"</span>,'. "\n";
      echo '  <span class="jk">"node_info.txt"</span>:  <span class="js">"' . $ni_disp . '"</span>'. "\n";
      echo '<span class="jk">}</span>';
    ?></pre>
  </div>

  <footer>
    <span>mesh-node · config provisioning</span>
    <span>public_key.txt &amp; node_info.txt</span>
  </footer>

</div>

<script>
function updateCount(inputId, countId) {
  var len = document.getElementById(inputId).value.length;
  var el  = document.getElementById(countId);
  el.textContent = len + ' / 512';
  el.classList.toggle('over', len >= 490);
}
</script>
</body>
</html>