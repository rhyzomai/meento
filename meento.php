<?php
/**
 * Meento Share — Central Platform
 * PHP 7+ | MySQL | Green Theme
 * Single-file application
 */

// ═══════════════════════════════════════════════════════════════
// 1. CONFIGURAÇÃO
// ═══════════════════════════════════════════════════════════════
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'meento_share');

define('PIX_KEY',       '');
define('PIX_OWNER',     '');
define('SIGNUP_COST',   1000);   // centavos (R$ 10,00)
define('INTERACTION_COST', 1);   // centavo por like/view único
define('SITE_NAME',     'Meento Share');
define('SITE_URL',      'https://meshare.lovable.app/');

session_start();

// ═══════════════════════════════════════════════════════════════
// 2. BANCO DE DADOS — AUTO-INIT
// ═══════════════════════════════════════════════════════════════
try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // Tabela base (compatível com indexer.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS file_registry (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        file_hash        VARCHAR(255) UNIQUE NOT NULL,
        server_filename  VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size        INT NOT NULL DEFAULT 0,
        extension        VARCHAR(50)  NOT NULL DEFAULT '',
        public_key       TEXT,
        node_info        TEXT,
        description      TEXT,
        hosts            TEXT NOT NULL DEFAULT '[]',
        view_count       INT  NOT NULL DEFAULT 0,
        like_count       INT  NOT NULL DEFAULT 0,
        indexed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (indexed_at),
        INDEX (like_count),
        INDEX (view_count)
    )");

    // Usuários
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(80)  NOT NULL UNIQUE,
        email        VARCHAR(180) NOT NULL UNIQUE,
        password     VARCHAR(255) NOT NULL,
        balance      INT NOT NULL DEFAULT 0,
        is_unlocked  TINYINT(1) NOT NULL DEFAULT 0,
        is_admin     TINYINT(1) NOT NULL DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Transações de saldo
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        type        ENUM('credit','debit') NOT NULL,
        amount      INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        ref_hash    VARCHAR(255) DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (created_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Solicitações de pagamento Pix (criadas pelo usuário, confirmadas pelo admin)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pix_requests (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        amount       INT NOT NULL DEFAULT 1000,
        txid         VARCHAR(100) DEFAULT NULL,
        status       ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
        admin_note   VARCHAR(255) DEFAULT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Interações únicas (view / like por usuário por arquivo)
    $pdo->exec("CREATE TABLE IF NOT EXISTS interactions (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        user_id   INT NOT NULL,
        file_hash VARCHAR(255) NOT NULL,
        type      ENUM('view','like') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_interaction (user_id, file_hash, type),
        INDEX (file_hash),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

} catch (PDOException $e) {
    die("<div style='color:red;padding:20px;font-family:sans-serif'><strong>Erro de banco:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ═══════════════════════════════════════════════════════════════
// 3. HELPERS
// ═══════════════════════════════════════════════════════════════
function formatBRL(int $centavos): string {
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!currentUser()) {
        header('Location: ?page=login');
        exit;
    }
}

function requireAdmin(): void {
    $u = currentUser();
    if (!$u || !$u['is_admin']) {
        header('Location: ?page=home');
        exit;
    }
}

function reloadUser(PDO $pdo): void {
    $u = currentUser();
    if (!$u) return;
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$u['id']]);
    $fresh = $s->fetch();
    if ($fresh) $_SESSION['user'] = $fresh;
}

/**
 * Registra interação única e debita 1 centavo se inédita.
 * Retorna true se a interação foi nova.
 */
function recordInteraction(PDO $pdo, int $userId, string $fileHash, string $type): bool {
    // Verifica se já existe
    $chk = $pdo->prepare("SELECT id FROM interactions WHERE user_id=? AND file_hash=? AND type=?");
    $chk->execute([$userId, $fileHash, $type]);
    if ($chk->fetch()) return false; // já feito, sem débito

    $user = $pdo->prepare("SELECT balance, is_unlocked FROM users WHERE id=?");
    $user->execute([$userId]);
    $u = $user->fetch();
    if (!$u || !$u['is_unlocked']) return false; // não desbloqueado

    // Insere interação
    $ins = $pdo->prepare("INSERT IGNORE INTO interactions (user_id, file_hash, type) VALUES (?,?,?)");
    $ins->execute([$userId, $fileHash, $type]);
    if ($pdo->lastInsertId() == 0) return false; // corrida — já existia

    // Debita saldo
    $newBalance = max(0, (int)$u['balance'] - INTERACTION_COST);
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $userId]);

    // Registra transação
    $label = $type === 'like' ? 'Like' : 'Visualização';
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,description,ref_hash)
                   VALUES (?,?,?,?,?)")
        ->execute([$userId, 'debit', INTERACTION_COST,
                   "$label em arquivo " . substr($fileHash, 0, 12) . '…', $fileHash]);

    // Incrementa contador no arquivo
    $col = $type === 'like' ? 'like_count' : 'view_count';
    $pdo->prepare("UPDATE file_registry SET $col = $col + 1 WHERE file_hash=?")->execute([$fileHash]);

    // Atualiza sessão
    $_SESSION['user']['balance'] = $newBalance;
    return true;
}

// ═══════════════════════════════════════════════════════════════
// 4. ROTEAMENTO DE AÇÕES AJAX / POST
// ═══════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? '';

// ── Registro ────────────────────────────────────────────────────
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $pass     = $_POST['password']      ?? '';
    $pass2    = $_POST['password2']     ?? '';
    $errors   = [];
    if (strlen($username) < 3)  $errors[] = 'Usuário deve ter ao menos 3 caracteres.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
    if (strlen($pass) < 6)      $errors[] = 'Senha deve ter ao menos 6 caracteres.';
    if ($pass !== $pass2)       $errors[] = 'As senhas não coincidem.';
    if (empty($errors)) {
        try {
            $pdo->prepare("INSERT INTO users (username,email,password) VALUES (?,?,?)")
                ->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Conta criada! Faça login.'];
            header('Location: ?page=login'); exit;
        } catch (PDOException $e) {
            $errors[] = 'Usuário ou e-mail já cadastrado.';
        }
    }
    $_SESSION['flash'] = ['type'=>'error','msg'=>implode(' ', $errors)];
    header('Location: ?page=register'); exit;
}

// ── Login ────────────────────────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password']   ?? '';
    $s = $pdo->prepare("SELECT * FROM users WHERE email=?");
    $s->execute([$email]);
    $u = $s->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        $_SESSION['user'] = $u;
        header('Location: ?page=home'); exit;
    }
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Credenciais inválidas.'];
    header('Location: ?page=login'); exit;
}

// ── Logout ───────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    header('Location: ?page=login'); exit;
}

// ── Solicitar Pix ────────────────────────────────────────────────
if ($action === 'request_pix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $u    = currentUser();
    $txid = trim($_POST['txid'] ?? '');
    if (strlen($txid) < 3) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Informe o ID/comprovante do Pix.'];
        header('Location: ?page=wallet'); exit;
    }
    // Verifica se já tem pedido pendente
    $ck = $pdo->prepare("SELECT id FROM pix_requests WHERE user_id=? AND status='pending'");
    $ck->execute([$u['id']]);
    if ($ck->fetch()) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Você já tem um pedido de Pix pendente.'];
        header('Location: ?page=wallet'); exit;
    }
    $pdo->prepare("INSERT INTO pix_requests (user_id,amount,txid) VALUES (?,?,?)")
        ->execute([$u['id'], SIGNUP_COST, $txid]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Solicitação enviada! Aguarde a confirmação.'];
    header('Location: ?page=wallet'); exit;
}

// ── Interação AJAX (view / like) ─────────────────────────────────
if ($action === 'interact' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $u = currentUser();
    if (!$u) { echo json_encode(['ok'=>false,'msg'=>'Não logado']); exit; }
    $hash = trim($_POST['hash'] ?? '');
    $type = $_POST['type'] ?? 'view';
    if (!in_array($type, ['view','like'], true) || empty($hash)) {
        echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit;
    }
    $new = recordInteraction($pdo, (int)$u['id'], $hash, $type);
    reloadUser($pdo);
    $fresh = currentUser();
    // Pega contadores atualizados
    $s = $pdo->prepare("SELECT like_count, view_count FROM file_registry WHERE file_hash=?");
    $s->execute([$hash]);
    $row = $s->fetch();
    echo json_encode([
        'ok'         => true,
        'new'        => $new,
        'balance'    => $fresh['balance'],
        'like_count' => $row['like_count']  ?? 0,
        'view_count' => $row['view_count']  ?? 0,
    ]);
    exit;
}

// ── Admin: confirmar / rejeitar Pix ─────────────────────────────
if ($action === 'admin_pix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $pid    = (int)($_POST['pid']    ?? 0);
    $status = $_POST['status'] ?? '';
    $note   = trim($_POST['note'] ?? '');
    if (!in_array($status, ['confirmed','rejected'], true)) {
        header('Location: ?page=admin'); exit;
    }
    $req = $pdo->prepare("SELECT * FROM pix_requests WHERE id=?");
    $req->execute([$pid]);
    $pr = $req->fetch();
    if ($pr && $pr['status'] === 'pending') {
        $pdo->prepare("UPDATE pix_requests SET status=?,admin_note=? WHERE id=?")
            ->execute([$status, $note, $pid]);
        if ($status === 'confirmed') {
            // Credita saldo e desbloqueia usuário
            $pdo->prepare("UPDATE users SET balance = balance + ?, is_unlocked = 1 WHERE id=?")
                ->execute([$pr['amount'], $pr['user_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,description)
                           VALUES (?,?,?,?)")
                ->execute([$pr['user_id'], 'credit', $pr['amount'], 'Depósito Pix confirmado (ID: ' . htmlspecialchars($pr['txid']) . ')']);
        }
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>"Pedido #{$pid} atualizado."];
    header('Location: ?page=admin'); exit;
}

// ═══════════════════════════════════════════════════════════════
// 5. LÓGICA DE PÁGINAS
// ═══════════════════════════════════════════════════════════════
$page   = $_GET['page'] ?? 'home';
$search = trim($_GET['q'] ?? '');
$pgnum  = max(1, (int)($_GET['p'] ?? 1));
$limit  = 24;
$offset = ($pgnum - 1) * $limit;

$files       = [];
$total_files = 0;

if ($page === 'home' || $page === 'browse') {
    $sort = $_GET['sort'] ?? 'recent';
    $orderMap = [
        'recent'  => 'f.indexed_at DESC',
        'popular' => '(f.like_count + f.view_count) DESC',
        'liked'   => 'f.like_count DESC',
        'views'   => 'f.view_count DESC',
    ];
    $orderBy = $orderMap[$sort] ?? 'f.indexed_at DESC';

    $where  = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE (f.original_filename LIKE ? OR f.description LIKE ? OR f.extension LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }

    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM file_registry f $where");
    $cStmt->execute($params);
    $total_files = (int)$cStmt->fetchColumn();

    $dStmt = $pdo->prepare("SELECT f.* FROM file_registry f $where ORDER BY $orderBy LIMIT ? OFFSET ?");
    foreach ($params as $i => $v) $dStmt->bindValue($i + 1, $v, PDO::PARAM_STR);
    $dStmt->bindValue(count($params) + 1, $limit,  PDO::PARAM_INT);
    $dStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $dStmt->execute();
    $files = $dStmt->fetchAll();

    // Listar quais arquivos o usuário atual já curtiu/visualizou
    $userLikes = $userViews = [];
    $u = currentUser();
    if ($u) {
        $hashes = array_column($files, 'file_hash');
        if ($hashes) {
            $ph = implode(',', array_fill(0, count($hashes), '?'));
            $lk = $pdo->prepare("SELECT file_hash FROM interactions WHERE user_id=? AND type='like' AND file_hash IN ($ph)");
            $lk->execute(array_merge([$u['id']], $hashes));
            $userLikes = array_column($lk->fetchAll(), 'file_hash');

            $vw = $pdo->prepare("SELECT file_hash FROM interactions WHERE user_id=? AND type='view' AND file_hash IN ($ph)");
            $vw->execute(array_merge([$u['id']], $hashes));
            $userViews = array_column($vw->fetchAll(), 'file_hash');
        }
    }
}

$walletTx    = [];
$pixRequests = [];
if ($page === 'wallet') {
    requireLogin();
    reloadUser($pdo);
    $u = currentUser();
    $wStmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $wStmt->execute([$u['id']]);
    $walletTx = $wStmt->fetchAll();
    $pStmt = $pdo->prepare("SELECT * FROM pix_requests WHERE user_id=? ORDER BY requested_at DESC LIMIT 10");
    $pStmt->execute([$u['id']]);
    $pixRequests = $pStmt->fetchAll();
}

$adminPixPending = [];
$adminStats      = [];
if ($page === 'admin') {
    requireAdmin();
    $apStmt = $pdo->prepare("SELECT pr.*, u.username, u.email FROM pix_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.status='pending' ORDER BY pr.requested_at ASC");
    $apStmt->execute();
    $adminPixPending = $apStmt->fetchAll();

    $adminStats = [
        'users'   => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'files'   => $pdo->query("SELECT COUNT(*) FROM file_registry")->fetchColumn(),
        'tx'      => $pdo->query("SELECT COUNT(*) FROM wallet_transactions")->fetchColumn(),
        'pending' => count($adminPixPending),
    ];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$currentUser = currentUser();

// ═══════════════════════════════════════════════════════════════
// 6. HTML / UI
// ═══════════════════════════════════════════════════════════════

function extIcon(string $ext): string {
    $icons = [
        'pdf'=>'?','mp4'=>'?','mp3'=>'?','jpg'=>'?️','jpeg'=>'?️','png'=>'?️',
        'gif'=>'?️','zip'=>'?','rar'=>'?','7z'=>'?','doc'=>'?','docx'=>'?',
        'xls'=>'?','xlsx'=>'?','ppt'=>'?','pptx'=>'?','txt'=>'?','svg'=>'?',
        'avi'=>'?','mkv'=>'?','mov'=>'?','apk'=>'?','exe'=>'?',
    ];
    return $icons[strtolower($ext)] ?? '?';
}

function humanSize(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes/1024,1)    . ' KB';
    if ($bytes < 1073741824) return round($bytes/1048576,1) . ' MB';
    return round($bytes/1073741824,2) . ' GB';
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SITE_NAME ?></title>
<style>
/* ── Design tokens ───────────────────────────────── */
:root {
    --g900: #052e16;
    --g800: #14532d;
    --g700: #15803d;
    --g600: #16a34a;
    --g500: #22c55e;
    --g400: #4ade80;
    --g300: #86efac;
    --g100: #dcfce7;
    --g050: #f0fdf4;
    --neutral900: #0f172a;
    --neutral700: #334155;
    --neutral500: #64748b;
    --neutral300: #cbd5e1;
    --neutral100: #f1f5f9;
    --white: #ffffff;
    --danger: #dc2626;
    --warning: #d97706;
    --card-shadow: 0 1px 4px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.05);
    --radius: 10px;
    --font-sans: 'Segoe UI', system-ui, -apple-system, sans-serif;
    --font-mono: 'Courier New', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font-sans);
    background: var(--g050);
    color: var(--neutral900);
    min-height: 100vh;
    font-size: 15px;
    line-height: 1.6;
}

/* ── Nav ─────────────────────────────────────────── */
.nav {
    background: var(--g800);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
}
.nav-inner {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    height: 58px;
}
.nav-logo {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--g400);
    text-decoration: none;
    letter-spacing: -.5px;
    flex-shrink: 0;
}
.nav-logo span { color: var(--white); }
.nav-search {
    flex: 1;
    max-width: 480px;
    display: flex;
    gap: 6px;
}
.nav-search input {
    flex: 1;
    padding: 7px 14px;
    border: none;
    border-radius: 6px;
    font-size: .9rem;
    background: rgba(255,255,255,.12);
    color: var(--white);
    outline: none;
    transition: background .2s;
}
.nav-search input::placeholder { color: var(--g300); }
.nav-search input:focus { background: rgba(255,255,255,.22); }
.nav-search button {
    padding: 7px 14px;
    background: var(--g500);
    color: var(--g900);
    border: none;
    border-radius: 6px;
    font-weight: 700;
    cursor: pointer;
    font-size: .85rem;
    transition: background .2s;
}
.nav-search button:hover { background: var(--g400); }
.nav-links {
    display: flex;
    gap: 4px;
    margin-left: auto;
}
.nav-links a {
    color: var(--g300);
    text-decoration: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 500;
    transition: background .2s, color .2s;
}
.nav-links a:hover  { background: rgba(255,255,255,.1); color: var(--white); }
.nav-links a.active { background: var(--g600); color: var(--white); }
.nav-badge {
    background: var(--g500);
    color: var(--g900);
    font-size: .72rem;
    font-weight: 800;
    padding: 1px 7px;
    border-radius: 20px;
    margin-left: 4px;
}

/* ── Layout ──────────────────────────────────────── */
.container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 28px 20px 60px;
}

/* ── Flash ───────────────────────────────────────── */
.flash {
    padding: 12px 18px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    font-weight: 600;
    font-size: .92rem;
}
.flash-success { background: var(--g100); color: var(--g800); border: 1px solid var(--g300); }
.flash-error   { background: #fee2e2;     color: #7f1d1d;     border: 1px solid #fca5a5; }

/* ── Botões ──────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 8px;
    border: none;
    font-size: .88rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all .18s;
    white-space: nowrap;
}
.btn-primary { background: var(--g600); color: var(--white); }
.btn-primary:hover { background: var(--g700); }
.btn-outline { background: transparent; color: var(--g700); border: 2px solid var(--g500); }
.btn-outline:hover { background: var(--g100); }
.btn-danger  { background: var(--danger); color: var(--white); }
.btn-sm      { padding: 5px 12px; font-size: .8rem; }
.btn-xs      { padding: 3px 9px;  font-size: .75rem; }

/* ── Cards de arquivo ────────────────────────────── */
.files-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 22px;
}
.files-toolbar h2 { font-size: 1.1rem; color: var(--g800); flex: 1; }
.sort-tabs { display: flex; gap: 4px; }
.sort-tab {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: .82rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--neutral500);
    background: var(--white);
    border: 1px solid var(--neutral300);
    transition: all .15s;
}
.sort-tab:hover  { border-color: var(--g500); color: var(--g700); }
.sort-tab.active { background: var(--g600); color: var(--white); border-color: var(--g600); }

.files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 18px;
}

.file-card {
    background: var(--white);
    border: 1px solid #e2e8f0;
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: transform .15s, box-shadow .15s;
}
.file-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(21,128,61,.12);
    border-color: var(--g300);
}
.file-card-icon {
    background: var(--g050);
    padding: 22px 16px 14px;
    text-align: center;
    font-size: 2.6rem;
    border-bottom: 1px solid var(--g100);
    cursor: pointer;
}
.file-ext-badge {
    display: inline-block;
    background: var(--g100);
    color: var(--g700);
    font-size: .7rem;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 4px;
    letter-spacing: .5px;
    margin-top: 6px;
    text-transform: uppercase;
}
.file-card-body { padding: 14px 16px; flex: 1; }
.file-name {
    font-weight: 700;
    font-size: .95rem;
    color: var(--neutral900);
    margin-bottom: 6px;
    word-break: break-word;
    cursor: pointer;
}
.file-name:hover { color: var(--g700); }
.file-desc {
    font-size: .82rem;
    color: var(--neutral500);
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.file-meta-row {
    display: flex;
    justify-content: space-between;
    font-size: .78rem;
    color: var(--neutral500);
    margin-bottom: 12px;
}
.file-card-footer {
    padding: 10px 16px;
    border-top: 1px solid var(--g050);
    display: flex;
    align-items: center;
    gap: 8px;
}
.like-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: none;
    border: 1.5px solid var(--neutral300);
    border-radius: 20px;
    padding: 4px 12px;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
    color: var(--neutral500);
    transition: all .15s;
}
.like-btn:hover, .like-btn.liked {
    border-color: #fb7185;
    color: #e11d48;
    background: #fff1f2;
}
.view-badge {
    font-size: .78rem;
    color: var(--neutral500);
    margin-left: auto;
}
.download-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .8rem;
    font-weight: 600;
    color: var(--g700);
    text-decoration: none;
    padding: 4px 10px;
    border-radius: 6px;
    background: var(--g050);
    border: 1px solid var(--g200, #bbf7d0);
    transition: background .15s;
}
.download-link:hover { background: var(--g100); }

/* ── Balance strip ───────────────────────────────── */
.balance-strip {
    background: linear-gradient(135deg, var(--g800), var(--g700));
    color: var(--white);
    border-radius: var(--radius);
    padding: 18px 24px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}
.balance-strip .bal-label { font-size: .85rem; opacity: .8; }
.balance-strip .bal-value { font-size: 2rem; font-weight: 800; color: var(--g400); }
.balance-strip .bal-locked { font-size: .82rem; background: rgba(0,0,0,.25); padding: 4px 12px; border-radius: 20px; }

/* ── Seções de página ────────────────────────────── */
.page-header { margin-bottom: 28px; }
.page-header h1 { font-size: 1.7rem; color: var(--g800); margin-bottom: 6px; }
.page-header p { color: var(--neutral500); }

.section-card {
    background: var(--white);
    border: 1px solid #e2e8f0;
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    padding: 24px;
    margin-bottom: 24px;
}
.section-card h2 { font-size: 1.05rem; color: var(--g800); margin-bottom: 16px; border-bottom: 2px solid var(--g100); padding-bottom: 10px; }

/* ── Formulários ─────────────────────────────────── */
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: .85rem; font-weight: 600; color: var(--neutral700); margin-bottom: 6px; }
.form-control {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid var(--neutral300);
    border-radius: 8px;
    font-size: .92rem;
    transition: border-color .2s;
    outline: none;
    background: var(--white);
}
.form-control:focus { border-color: var(--g500); box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
.form-hint { font-size: .78rem; color: var(--neutral500); margin-top: 4px; }

/* ── Tabela de transações ────────────────────────── */
.tx-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.tx-table th { text-align: left; padding: 8px 12px; background: var(--g050); color: var(--g800); font-weight: 700; border-bottom: 2px solid var(--g100); }
.tx-table td { padding: 9px 12px; border-bottom: 1px solid var(--neutral100); }
.tx-table tr:hover td { background: var(--g050); }
.tx-credit { color: var(--g700); font-weight: 700; }
.tx-debit  { color: var(--danger); font-weight: 700; }

/* ── Pix box ─────────────────────────────────────── */
.pix-box {
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border: 2px dashed var(--g400);
    border-radius: var(--radius);
    padding: 24px;
    text-align: center;
    margin-bottom: 20px;
}
.pix-key {
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--g700);
    font-family: var(--font-mono);
    margin: 10px 0 4px;
}
.pix-owner { font-size: .88rem; color: var(--neutral500); }
.pix-amount { font-size: 1.8rem; font-weight: 800; color: var(--g800); margin-top: 10px; }

/* ── Status badges ───────────────────────────────── */
.badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .3px;
}
.badge-pending  { background: #fef9c3; color: #713f12; }
.badge-confirmed{ background: var(--g100); color: var(--g800); }
.badge-rejected { background: #fee2e2; color: #7f1d1d; }

/* ── Admin stats ─────────────────────────────────── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-card { background: var(--white); border: 1px solid #e2e8f0; border-radius: var(--radius); padding: 18px; text-align: center; }
.stat-card .stat-val { font-size: 2rem; font-weight: 800; color: var(--g700); }
.stat-card .stat-label { font-size: .8rem; color: var(--neutral500); margin-top: 4px; }

/* ── Paginação ───────────────────────────────────── */
.pagination { display: flex; justify-content: center; gap: 5px; margin-top: 32px; flex-wrap: wrap; }
.pagination a {
    padding: 7px 13px;
    border: 1px solid var(--neutral300);
    background: var(--white);
    color: var(--neutral700);
    text-decoration: none;
    border-radius: 7px;
    font-size: .85rem;
    transition: all .15s;
}
.pagination a:hover  { border-color: var(--g500); color: var(--g700); }
.pagination a.active { background: var(--g600); color: var(--white); border-color: var(--g600); }

/* ── Auth pages ──────────────────────────────────── */
.auth-wrap {
    min-height: 100vh;
    background: linear-gradient(160deg, var(--g800) 0%, var(--g600) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.auth-card {
    background: var(--white);
    border-radius: 16px;
    padding: 40px 36px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.auth-card h1 {
    font-size: 1.6rem;
    color: var(--g800);
    margin-bottom: 6px;
}
.auth-card .auth-subtitle { color: var(--neutral500); font-size: .9rem; margin-bottom: 28px; }
.auth-logo { font-size: 1.9rem; font-weight: 900; color: var(--g600); margin-bottom: 6px; }

/* ── Hero (home não logado) ──────────────────────── */
.hero {
    background: linear-gradient(135deg, var(--g800) 0%, var(--g600) 60%, var(--g500) 100%);
    color: var(--white);
    padding: 60px 20px 50px;
    text-align: center;
    margin: -28px -20px 36px;
}
.hero h1 { font-size: 2.4rem; font-weight: 900; letter-spacing: -1px; margin-bottom: 14px; }
.hero p { font-size: 1.05rem; opacity: .85; max-width: 560px; margin: 0 auto 28px; }
.hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-hero { padding: 12px 28px; font-size: 1rem; border-radius: 10px; }
.btn-hero-light { background: rgba(255,255,255,.15); color: var(--white); border: 2px solid rgba(255,255,255,.4); }
.btn-hero-light:hover { background: rgba(255,255,255,.25); }

/* ── Locked overlay ──────────────────────────────── */
.locked-notice {
    background: linear-gradient(135deg, var(--g050), var(--g100));
    border: 2px solid var(--g300);
    border-radius: var(--radius);
    padding: 20px 24px;
    text-align: center;
    margin-bottom: 28px;
}
.locked-notice h3 { color: var(--g700); margin-bottom: 8px; }
.locked-notice p  { font-size: .88rem; color: var(--neutral700); }

/* ── No results ──────────────────────────────────── */
.no-results { text-align: center; padding: 60px 20px; color: var(--neutral500); }
.no-results .icon { font-size: 3rem; margin-bottom: 14px; }
.no-results h3 { font-size: 1.1rem; color: var(--neutral700); margin-bottom: 8px; }

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 640px) {
    .nav-search { max-width: 180px; }
    .hero h1 { font-size: 1.7rem; }
    .files-grid { grid-template-columns: 1fr; }
    .balance-strip { flex-direction: column; align-items: flex-start; gap: 10px; }
}
</style>
</head>
<body>

<?php
// Páginas de autenticação (sem nav)
if ($page === 'login' || $page === 'register'):
?>
<div class="auth-wrap">
<div class="auth-card">
    <div class="auth-logo"><?= SITE_NAME ?></div>
    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($page === 'login'): ?>
    <h1>Entrar</h1>
    <p class="auth-subtitle">Acesse sua conta para explorar e interagir.</p>
    <form method="POST" action="?action=login">
        <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" class="form-control" placeholder="seu@email.com" required autofocus>
        </div>
        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px" type="submit">Entrar</button>
    </form>
    <p style="text-align:center;margin-top:18px;font-size:.88rem;color:var(--neutral500)">
        Não tem conta? <a href="?page=register" style="color:var(--g700);font-weight:700">Registrar</a>
    </p>

    <?php else: // register ?>
    <h1>Criar conta</h1>
    <p class="auth-subtitle">Grátis para buscar. Pague R$ 10 via Pix para curtir e ver rankings.</p>
    <form method="POST" action="?action=register">
        <div class="form-group">
            <label>Usuário</label>
            <input type="text" name="username" class="form-control" placeholder="meu_usuario" required autofocus minlength="3">
        </div>
        <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
        </div>
        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="password" class="form-control" placeholder="Mín. 6 caracteres" required minlength="6">
        </div>
        <div class="form-group">
            <label>Confirmar senha</label>
            <input type="password" name="password2" class="form-control" placeholder="Repita a senha" required>
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px" type="submit">Criar conta</button>
    </form>
    <p style="text-align:center;margin-top:18px;font-size:.88rem;color:var(--neutral500)">
        Já tem conta? <a href="?page=login" style="color:var(--g700);font-weight:700">Entrar</a>
    </p>
    <?php endif; ?>
</div>
</div>
<?php else: // páginas com nav ?>

<!-- ── NAV ───────────────────────────────────────────────────── -->
<nav class="nav">
<div class="nav-inner">
    <a class="nav-logo" href="?page=home"><?= SITE_NAME ?></a>
    <form class="nav-search" method="GET" action="?page=browse">
        <input type="hidden" name="page" value="browse">
        <input type="text" name="q" placeholder="Buscar arquivos…" value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Buscar</button>
    </form>
    <div class="nav-links">
        <a href="?page=home"   class="<?= $page==='home'   ? 'active' : '' ?>">Início</a>
        <a href="send.php"   >Enviar</a>
        <a href="?page=browse" class="<?= $page==='browse' ? 'active' : '' ?>">Arquivos</a>
        <?php if ($currentUser): ?>
            <a href="?page=wallet" class="<?= $page==='wallet' ? 'active' : '' ?>">
                Carteira
                <span class="nav-badge"><?= formatBRL($currentUser['balance']) ?></span>
            </a>
            <?php if ($currentUser['is_admin']): ?>
                <a href="?page=admin" class="<?= $page==='admin' ? 'active' : '' ?>">Admin</a>
            <?php endif; ?>
            <a href="?action=logout">Sair (<?= htmlspecialchars($currentUser['username']) ?>)</a>
        <?php else: ?>
            <a href="?page=login">Entrar</a>
            <a href="?page=register" class="btn btn-primary btn-sm" style="margin-left:4px">Cadastrar</a>
        <?php endif; ?>
    </div>
</div>
</nav>

<div class="container">
<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     HOME
══════════════════════════════════════════════════ -->
<?php if ($page === 'home'): ?>

<?php if (!$currentUser): ?>
<div class="hero">
    <h1>Compartilhe. Descubra. Conecte.</h1>
    <p>Plataforma centralizada de indexação de arquivos distribuídos. Busque, curta e baixe conteúdo de toda a rede.</p>
    <div class="hero-actions">
        <a href="?page=register" class="btn btn-primary btn-hero">Criar conta grátis</a>
        <a href="?page=browse"   class="btn btn-hero btn-hero-light">Ver arquivos →</a>
    </div>
</div>
<?php endif; ?>

<?php if ($currentUser && !$currentUser['is_unlocked']): ?>
<div class="locked-notice">
    <h3>? Conta básica — desbloqueie os recursos</h3>
    <p>Faça um Pix de <strong><?= formatBRL(SIGNUP_COST) ?></strong> para <strong><?= PIX_KEY ?></strong> (<?= PIX_OWNER ?>) e informe o comprovante na sua carteira.<br>
    Após confirmação você receberá <strong><?= formatBRL(SIGNUP_COST) ?> de saldo</strong> e acesso a curtidas, rankings e recompensas.</p>
    <a href="?page=wallet" class="btn btn-primary" style="margin-top:14px">Ir para Carteira</a>
</div>
<?php endif; ?>

<?php
// Reusa lógica de browse para a home
$sort = $_GET['sort'] ?? 'popular';
$orderMapH = ['recent'=>'f.indexed_at DESC','popular'=>'(f.like_count+f.view_count) DESC','liked'=>'f.like_count DESC'];
$orderByH  = $orderMapH[$sort] ?? '(f.like_count+f.view_count) DESC';
$cH = $pdo->query("SELECT COUNT(*) FROM file_registry")->fetchColumn();
$dH = $pdo->prepare("SELECT f.* FROM file_registry f ORDER BY $orderByH LIMIT 24");
$dH->execute();
$files = $dH->fetchAll();
$total_files = $cH;
$userLikes = $userViews = [];
if ($currentUser) {
    $hashes = array_column($files, 'file_hash');
    if ($hashes) {
        $ph = implode(',', array_fill(0, count($hashes), '?'));
        $lk2 = $pdo->prepare("SELECT file_hash FROM interactions WHERE user_id=? AND type='like' AND file_hash IN ($ph)");
        $lk2->execute(array_merge([$currentUser['id']], $hashes));
        $userLikes = array_column($lk2->fetchAll(), 'file_hash');
    }
}
?>
<div class="files-toolbar">
    <h2>? <?= number_format($total_files) ?> arquivo<?= $total_files != 1 ? 's' : '' ?> na rede</h2>
    <div class="sort-tabs">
        <a href="?page=home&sort=popular" class="sort-tab <?= ($sort==='popular')?'active':'' ?>">? Popular</a>
        <a href="?page=home&sort=liked"   class="sort-tab <?= ($sort==='liked')  ?'active':'' ?>">❤️ Mais curtidos</a>
        <a href="?page=home&sort=recent"  class="sort-tab <?= ($sort==='recent') ?'active':'' ?>">? Recentes</a>
    </div>
</div>
<?php include_once __FILE__; // grid reutilizado abaixo via include da função ?>
<?php renderGrid($files, $userLikes, $currentUser); ?>

<!-- ══════════════════════════════════════════════════
     BROWSE
══════════════════════════════════════════════════ -->
<?php elseif ($page === 'browse'): ?>

<div class="page-header">
    <h1>?️ Explorar arquivos</h1>
    <p><?= $search ? 'Resultados para "' . htmlspecialchars($search) . '"' : 'Todos os arquivos indexados na rede' ?></p>
</div>

<div class="files-toolbar">
    <h2><?= number_format($total_files) ?> arquivo<?= $total_files != 1 ? 's' : '' ?><?= $search ? ' encontrados' : '' ?></h2>
    <div class="sort-tabs">
        <?php $sortB = $_GET['sort'] ?? 'recent'; ?>
        <a href="?page=browse&q=<?= urlencode($search) ?>&sort=recent"  class="sort-tab <?= $sortB==='recent'  ?'active':'' ?>">? Recentes</a>
        <a href="?page=browse&q=<?= urlencode($search) ?>&sort=popular" class="sort-tab <?= $sortB==='popular' ?'active':'' ?>">? Popular</a>
        <a href="?page=browse&q=<?= urlencode($search) ?>&sort=liked"   class="sort-tab <?= $sortB==='liked'   ?'active':'' ?>">❤️ Curtidos</a>
        <a href="?page=browse&q=<?= urlencode($search) ?>&sort=views"   class="sort-tab <?= $sortB==='views'   ?'active':'' ?>">? Views</a>
    </div>
    <?php if ($search): ?>
        <a href="?page=browse" class="btn btn-outline btn-sm">✕ Limpar busca</a>
    <?php endif; ?>
</div>

<?php renderGrid($files, $userLikes, $currentUser); ?>

<?php if ($total_files > $limit): ?>
<div class="pagination">
    <?php
    $totalPages = ceil($total_files / $limit);
    $baseUrl = "?page=browse&q=" . urlencode($search) . "&sort=" . ($_GET['sort'] ?? 'recent');
    for ($i = 1; $i <= min($totalPages, 20); $i++):
    ?>
        <a href="<?= $baseUrl ?>&p=<?= $i ?>" class="<?= $i===$pgnum?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     WALLET
══════════════════════════════════════════════════ -->
<?php elseif ($page === 'wallet'):
    reloadUser($pdo);
    $currentUser = currentUser();
?>

<div class="page-header">
    <h1>? Minha Carteira</h1>
    <p>Gerencie seu saldo e desbloqueie recursos premium.</p>
</div>

<div class="balance-strip">
    <div>
        <div class="bal-label">Saldo disponível</div>
        <div class="bal-value"><?= formatBRL($currentUser['balance']) ?></div>
    </div>
    <div>
        <div class="bal-label">Status da conta</div>
        <div style="margin-top:4px">
            <?php if ($currentUser['is_unlocked']): ?>
                <span class="badge badge-confirmed">✓ Desbloqueada</span>
            <?php else: ?>
                <span class="badge badge-pending">? Básica</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="margin-left:auto">
        <div class="bal-label" style="opacity:.8">Cada like ou visualização única desconta</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--g400)"><?= formatBRL(INTERACTION_COST) ?></div>
    </div>
</div>

<?php if (!$currentUser['is_unlocked']): ?>
<div class="section-card">
    <h2>? Desbloquear conta via Pix</h2>
    <div class="pix-box">
        <div style="font-size:.9rem;color:var(--g800);font-weight:600">Envie exatamente:</div>
        <div class="pix-amount"><?= formatBRL(SIGNUP_COST) ?></div>
        <div style="font-size:.9rem;color:var(--neutral500);margin:8px 0 14px">para a chave Pix:</div>
        <div class="pix-key"><?= PIX_KEY ?></div>
        <div class="pix-owner"><?= PIX_OWNER ?></div>
    </div>
    <?php
    $hasPending = false;
    foreach ($pixRequests as $pr) { if ($pr['status'] === 'pending') { $hasPending = true; break; } }
    ?>
    <?php if (!$hasPending): ?>
    <form method="POST" action="?action=request_pix">
        <div class="form-group">
            <label>ID / Chave de comprovante do Pix</label>
            <input type="text" name="txid" class="form-control" placeholder="Cole aqui o ID da transação ou texto do comprovante" required>
            <div class="form-hint">Após o pagamento, informe o ID da transação ou qualquer texto do comprovante para que possamos confirmar.</div>
        </div>
        <button type="submit" class="btn btn-primary">Enviar comprovante</button>
    </form>
    <?php else: ?>
        <div class="flash flash-success" style="margin-top:12px">✓ Comprovante enviado! Aguardando confirmação do administrador.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pixRequests): ?>
<div class="section-card">
    <h2>? Histórico de solicitações Pix</h2>
    <table class="tx-table">
        <tr><th>Data</th><th>Valor</th><th>ID</th><th>Status</th><th>Obs.</th></tr>
        <?php foreach ($pixRequests as $pr): ?>
        <tr>
            <td><?= date('d/m/Y H:i', strtotime($pr['requested_at'])) ?></td>
            <td><?= formatBRL($pr['amount']) ?></td>
            <td style="font-family:var(--font-mono);font-size:.8rem"><?= htmlspecialchars($pr['txid'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $pr['status'] ?>"><?= ucfirst($pr['status']) ?></span></td>
            <td style="font-size:.8rem;color:var(--neutral500)"><?= htmlspecialchars($pr['admin_note'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="section-card">
    <h2>? Extrato de transações</h2>
    <?php if ($walletTx): ?>
    <table class="tx-table">
        <tr><th>Data</th><th>Tipo</th><th>Valor</th><th>Descrição</th></tr>
        <?php foreach ($walletTx as $tx): ?>
        <tr>
            <td><?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?></td>
            <td>
                <?php if ($tx['type']==='credit'): ?>
                    <span class="tx-credit">↑ Crédito</span>
                <?php else: ?>
                    <span class="tx-debit">↓ Débito</span>
                <?php endif; ?>
            </td>
            <td class="<?= $tx['type']==='credit' ? 'tx-credit' : 'tx-debit' ?>">
                <?= ($tx['type']==='credit' ? '+' : '−') . formatBRL($tx['amount']) ?>
            </td>
            <td><?= htmlspecialchars($tx['description']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <p style="color:var(--neutral500);font-size:.9rem">Nenhuma transação ainda.</p>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════
     ADMIN
══════════════════════════════════════════════════ -->
<?php elseif ($page === 'admin'): ?>

<div class="page-header">
    <h1>⚙️ Painel Administrativo</h1>
    <p>Gerencie pagamentos, usuários e conteúdo.</p>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-val"><?= $adminStats['users'] ?></div><div class="stat-label">Usuários</div></div>
    <div class="stat-card"><div class="stat-val"><?= number_format($adminStats['files']) ?></div><div class="stat-label">Arquivos</div></div>
    <div class="stat-card"><div class="stat-val"><?= number_format($adminStats['tx']) ?></div><div class="stat-label">Transações</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--warning)"><?= $adminStats['pending'] ?></div><div class="stat-label">Pix pendentes</div></div>
</div>

<div class="section-card">
    <h2>? Solicitações de Pix pendentes</h2>
    <?php if ($adminPixPending): ?>
    <?php foreach ($adminPixPending as $pr): ?>
    <div style="background:var(--g050);border:1px solid var(--g200,#bbf7d0);border-radius:var(--radius);padding:16px 20px;margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
            <strong style="color:var(--g800)"><?= htmlspecialchars($pr['username']) ?></strong>
            <span style="color:var(--neutral500);font-size:.85rem"><?= htmlspecialchars($pr['email']) ?></span>
            <span class="badge badge-pending">Pendente</span>
            <span style="margin-left:auto;font-weight:700;color:var(--g700)"><?= formatBRL($pr['amount']) ?></span>
        </div>
        <div style="font-size:.82rem;color:var(--neutral500);margin-bottom:12px">
            <strong>ID Pix:</strong> <code><?= htmlspecialchars($pr['txid']) ?></code>
            &nbsp;|&nbsp; <strong>Solicitado em:</strong> <?= date('d/m/Y H:i', strtotime($pr['requested_at'])) ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="POST" action="?action=admin_pix" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="pid" value="<?= $pr['id'] ?>">
                <input type="hidden" name="status" value="confirmed">
                <input type="text" name="note" class="form-control" style="width:220px;padding:5px 10px;font-size:.82rem" placeholder="Obs. (opcional)">
                <button class="btn btn-primary btn-sm" type="submit">✓ Confirmar</button>
            </form>
            <form method="POST" action="?action=admin_pix" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="pid" value="<?= $pr['id'] ?>">
                <input type="hidden" name="status" value="rejected">
                <input type="text" name="note" class="form-control" style="width:220px;padding:5px 10px;font-size:.82rem" placeholder="Motivo da rejeição">
                <button class="btn btn-danger btn-sm" type="submit">✕ Rejeitar</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
        <p style="color:var(--neutral500)">Nenhuma solicitação pendente. ?</p>
    <?php endif; ?>
</div>

<div class="section-card">
    <h2>? Promover usuário a admin</h2>
    <form method="POST" action="?action=make_admin" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="email" name="email" class="form-control" style="max-width:280px" placeholder="E-mail do usuário">
        <button class="btn btn-outline" type="submit">Promover</button>
    </form>
</div>

<?php endif; // fim páginas ?>

</div><!-- /container -->
<?php endif; // fim páginas com nav ?>

<!-- ── JS: interações ───────────────────────────────────────── -->
<script>
<?php if ($currentUser): ?>
const USER_UNLOCKED = <?= $currentUser['is_unlocked'] ? 'true' : 'false' ?>;
const USER_BALANCE  = <?= (int)$currentUser['balance'] ?>;

function doInteract(hash, type, btn) {
    if (!USER_UNLOCKED) {
        if (confirm('Sua conta precisa ser desbloqueada. Ir para a Carteira?')) {
            window.location = '?page=wallet';
        }
        return;
    }
    fetch('?action=interact', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'hash=' + encodeURIComponent(hash) + '&type=' + type
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) return;
        // Atualiza badge de saldo na nav
        document.querySelectorAll('.nav-badge').forEach(el => {
            el.textContent = 'R$ ' + (d.balance / 100).toFixed(2).replace('.', ',');
        });
        if (type === 'like') {
            btn.classList.add('liked');
            const span = btn.querySelector('.lc');
            if (span) span.textContent = d.like_count;
        }
        if (type === 'view') {
            const vc = document.querySelector('[data-vc="' + hash + '"]');
            if (vc) vc.textContent = '? ' + d.view_count;
        }
    });
}

// Registra visualização automaticamente ao clicar no card
document.addEventListener('click', function(e) {
    const trigger = e.target.closest('[data-view-hash]');
    if (trigger) {
        const hash = trigger.dataset.viewHash;
        doInteract(hash, 'view', trigger);
    }
});
<?php endif; ?>
</script>

</body>
</html>
<?php
// ═══════════════════════════════════════════════════════════════
// FUNÇÃO: renderGrid (declarada fora do HTML para reuso)
// ═══════════════════════════════════════════════════════════════
function renderGrid(array $files, array $userLikes, ?array $currentUser): void {
    if (empty($files)):
?>
<div class="no-results">
    <div class="icon">?</div>
    <h3>Nenhum arquivo encontrado</h3>
    <p>Tente outros termos ou aguarde a sincronização de novos servidores.</p>
</div>
<?php
    return;
    endif;
?>
<div class="files-grid">
<?php foreach ($files as $row):
    $hosts   = json_decode($row['hosts'] ?? '[]', true);
    if (!is_array($hosts)) $hosts = [];
    $isLiked = in_array($row['file_hash'], $userLikes, true);
?>
<div class="file-card">
    <div class="file-card-icon" data-view-hash="<?= htmlspecialchars($row['file_hash']) ?>">
        <?= extIcon($row['extension']) ?>
        <div><span class="file-ext-badge"><?= htmlspecialchars(strtoupper($row['extension'])) ?></span></div>
    </div>
    <div class="file-card-body">
        <div class="file-name" data-view-hash="<?= htmlspecialchars($row['file_hash']) ?>">
            <?= htmlspecialchars($row['original_filename']) ?>
        </div>
        <?php if (!empty($row['description'])): ?>
        <div class="file-desc"><?= htmlspecialchars($row['description']) ?></div>
        <?php endif; ?>
        <div class="file-meta-row">
            <span><?= humanSize((int)$row['file_size']) ?></span>
            <span><?= date('d/m/Y', strtotime($row['indexed_at'])) ?></span>
        </div>
        <?php if ($hosts): ?>
        <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px">
            <?php foreach ($hosts as $host): ?>
            <a href="<?= htmlspecialchars($host) ?>/files/<?= htmlspecialchars($row['server_filename']) ?>"
               target="_blank" class="download-link"
               onclick="<?= $currentUser ? "doInteract('".htmlspecialchars($row['file_hash'])."','view',this)" : "window.location='?page=login'" ?>">
                ↓ <?= htmlspecialchars(parse_url($host, PHP_URL_HOST) ?: $host) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="file-card-footer">
        <?php if ($currentUser): ?>
        <button class="like-btn <?= $isLiked ? 'liked' : '' ?>"
                onclick="doInteract('<?= htmlspecialchars($row['file_hash']) ?>','like',this)">
            ❤️ <span class="lc"><?= (int)$row['like_count'] ?></span>
        </button>
        <?php else: ?>
        <a href="?page=login" class="like-btn">❤️ <?= (int)$row['like_count'] ?></a>
        <?php endif; ?>
        <span class="view-badge" data-vc="<?= htmlspecialchars($row['file_hash']) ?>">
            ? <?= (int)$row['view_count'] ?>
        </span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php
}

// Admin: promover usuário
if (($action ?? '') === 'make_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $email = trim($_POST['email'] ?? '');
    $pdo->prepare("UPDATE users SET is_admin=1 WHERE email=?")->execute([$email]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>"Usuário $email promovido a admin."];
    header('Location: ?page=admin'); exit;
}
?>