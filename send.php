<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
/**
 * Meento Share — Envio de Links
 * PHP 7+ | MySQL | Green Theme
 * Integra com meento.php (mesmo banco de dados)
 */

// ═══════════════════════════════════════════════════════════════
// 1. CONFIGURAÇÃO (igual ao meento.php)
// ═══════════════════════════════════════════════════════════════
define('DB_HOST',       '127.0.0.1');
define('DB_USER',       'root');
define('DB_PASS',       '');
define('DB_NAME',       'meento_share');
define('SUBMIT_COST',   1);    // 1 centavo por envio
define('SITE_NAME',     'Meento Share');
define('ONLINE_CHECK',  0);    // 1 = verifica se o link está online antes de aceitar o envio | 0 = envia sem checar
define('INSERT_CHECK',  0);    // 1 = realiza as operações completas (submitted_links, saldo, wallet_transactions) além da file_registry
                                // 0 (padrão) = apenas grava/atualiza a file_registry, sem descontar saldo do usuário

// Extensões permitidas na URL do servidor
define('ALLOWED_EXTS', ['image','video','pdf','mp3','mp4','jpg','jpeg','gif','png','zip',
                         'mp4','avi','mkv','mov','webm','ogg','wav','flac','webp','svg',
                         'rar','7z','tar','gz','doc','docx','xls','xlsx','ppt','pptx','txt']);

session_start();

// ═══════════════════════════════════════════════════════════════
// 2. BANCO DE DADOS
// ═══════════════════════════════════════════════════════════════
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Tabela de links enviados pelos usuários
    $pdo->exec("CREATE TABLE IF NOT EXISTS submitted_links (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        file_url         VARCHAR(2048) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        extension        VARCHAR(50)  NOT NULL DEFAULT '',
        file_size        BIGINT       NOT NULL DEFAULT 0,
        description      TEXT,
        public_key       VARCHAR(255) DEFAULT NULL,
        node_info        TEXT DEFAULT NULL,
        source_json_url  VARCHAR(2048) DEFAULT NULL,
        status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        submitted_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status),
        INDEX (submitted_at)
    )");

    // Tabela de registro/índice de arquivos por hash (deduplicação)
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

} catch (PDOException $e) {
    die("<div style='color:red;padding:20px;font-family:sans-serif'><strong>Erro de banco:</strong> "
        . htmlspecialchars($e->getMessage()) . "</div>");
}

// ═══════════════════════════════════════════════════════════════
// 3. HELPERS
// ═══════════════════════════════════════════════════════════════
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function reloadUser(PDO $pdo): void {
    $u = currentUser();
    if (!$u) return;
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$u['id']]);
    $fresh = $s->fetch();
    if ($fresh) $_SESSION['user'] = $fresh;
}

function formatBRL(int $centavos): string {
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

/**
 * Valida se a URL termina com uma extensão de arquivo permitida.
 * Retorna a extensão ou null se inválida.
 */
function extractValidExtension(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') return null;
    $allowed = ['pdf','mp3','mp4','jpg','jpeg','gif','png','zip',
                 'avi','mkv','mov','webm','ogg','wav','flac','webp','svg',
                 'rar','7z','tar','gz','doc','docx','xls','xlsx','ppt','pptx','txt','image','video'];
    return in_array($ext, $allowed, true) ? $ext : null;
}

/**
 * Monta a URL do JSON de info baseada na URL do arquivo.
 * Ex: https://test.com/files/cat.jpg → https://test.com/info/cat.json
 */
function buildInfoJsonUrl(string $fileUrl): string {
    $parsed   = parse_url($fileUrl);
    $scheme   = ($parsed['scheme'] ?? 'https') . '://';
    $host     = $parsed['host'] ?? '';
    $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $filename = pathinfo($parsed['path'] ?? '', PATHINFO_FILENAME); // sem extensão
    return $scheme . $host . $port . '/info/' . $filename . '.json';
}

/**
 * Verifica se a URL do arquivo está online (responde com sucesso).
 * Faz uma requisição HEAD; se o servidor não suportar, tenta um GET parcial.
 * Só é chamada quando ONLINE_CHECK estiver ativo (= 1).
 */
function isUrlOnline(string $url, int $timeout = 8): bool {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $headers = @get_headers($url, false, $ctx);
        if (!$headers) return false;
        $code = (int) substr($headers[0], 9, 3);
        return $code >= 200 && $code < 400;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true, // HEAD
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'MeentoShare/1.0',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);

    if ($err || $code === 0) {
        // Alguns servidores bloqueiam HEAD; tenta um GET parcial como fallback.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RANGE          => '0-0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'MeentoShare/1.0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    return $code >= 200 && $code < 400;
}

/**
 * Verifica se a string é um hash SHA-256 válido (64 caracteres hexadecimais).
 */
function isValidSha256(string $hash): bool {
    return (bool) preg_match('/^[a-f0-9]{64}$/i', $hash);
}

/**
 * Extrai o "hash" do arquivo a partir da URL — é o nome do arquivo sem extensão.
 * Ex: https://exemplo.com/abc123....jpg -> abc123....
 * Retorna null se o resultado não for um SHA-256 válido.
 */
function extractFileHash(string $fileUrl): ?string {
    $parsed   = parse_url($fileUrl);
    $filename = pathinfo($parsed['path'] ?? '', PATHINFO_FILENAME);
    $filename = strtolower(trim($filename));
    return isValidSha256($filename) ? $filename : null;
}

/**
 * Decodifica o campo "hosts" (armazenado como array JSON) em um array PHP de URLs.
 * Tolerante a valor vazio/nulo ou a um formato legado (string única).
 */
function decodeHosts(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('trim', $decoded)));
    // Fallback para formato legado (string simples) — trata como uma única entrada
    return [trim($raw)];
}

/**
 * Codifica um array de URLs como JSON para gravar no campo "hosts".
 */
function encodeHosts(array $hosts): string {
    return json_encode(array_values($hosts), JSON_UNESCAPED_SLASHES);
}

function extIcon(string $ext): string {
    $icons = [
        'pdf'=>'📄','mp4'=>'🎬','mp3'=>'🎵','jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️',
        'gif'=>'🖼️','zip'=>'📦','rar'=>'📦','7z'=>'📦','doc'=>'📝','docx'=>'📝',
        'xls'=>'📊','xlsx'=>'📊','ppt'=>'📊','pptx'=>'📊','txt'=>'📄','svg'=>'🎨',
        'avi'=>'🎬','mkv'=>'🎬','mov'=>'🎬','webm'=>'🎬','ogg'=>'🎵','wav'=>'🎵',
        'flac'=>'🎵','webp'=>'🖼️','gz'=>'📦','tar'=>'📦',
    ];
    return $icons[strtolower($ext)] ?? '📁';
}

// ═══════════════════════════════════════════════════════════════
// 4. AÇÕES AJAX / POST
// ═══════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? '';

// ── Fetch JSON info do servidor (AJAX) ──────────────────────────
if ($action === 'fetch_json' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $u = currentUser();
    if (!$u) { echo json_encode(['ok'=>false,'msg'=>'Não logado']); exit; }

    $fileUrl = trim($_POST['url'] ?? '');
    if (!filter_var($fileUrl, FILTER_VALIDATE_URL) || !extractValidExtension($fileUrl)) {
        echo json_encode(['ok'=>false,'msg'=>'URL inválida ou extensão não permitida.']); exit;
    }

    $jsonUrl = buildInfoJsonUrl($fileUrl);
    $ctx = stream_context_create(['http'=>[
        'timeout'       => 8,
        'ignore_errors' => true,
        'user_agent'    => 'MeentoShare/1.0',
    ]]);
    $raw = @file_get_contents($jsonUrl, false, $ctx);
    if ($raw === false) {
        echo json_encode(['ok'=>false,'msg'=>'Não foi possível buscar o JSON em: ' . htmlspecialchars($jsonUrl)]); exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['ok'=>false,'msg'=>'Resposta inválida do servidor (não é JSON válido).']); exit;
    }

    echo json_encode([
        'ok'        => true,
        'json_url'  => $jsonUrl,
        'data'      => [
            'original_filename' => $data['original_filename'] ?? $data['filename'] ?? $data['name'] ?? '',
            'file_size'         => $data['file_size']         ?? $data['size']     ?? 0,
            'description'       => $data['description']       ?? $data['desc']     ?? '',
            'public_key'        => $data['public_key']        ?? $data['key']      ?? '',
            'node_info'         => $data['node_info']         ?? $data['node']     ?? '',
        ]
    ]);
    exit;
}

// ── Enviar link ──────────────────────────────────────────────────
if ($action === 'submit_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $u = currentUser();
    if (!$u) { echo json_encode(['ok'=>false,'msg'=>'Você precisa estar logado.']); exit; }

    reloadUser($pdo);
    $u = currentUser();

    // Verifica saldo (só é exigido quando INSERT_CHECK estiver ativado)
    if (INSERT_CHECK && (int)$u['balance'] < SUBMIT_COST) {
        echo json_encode(['ok'=>false,'msg'=>'Saldo insuficiente. Você precisa de pelo menos ' . formatBRL(SUBMIT_COST) . ' para enviar um link.']); exit;
    }

    $fileUrl  = trim($_POST['file_url']          ?? '');
    $filename = trim($_POST['original_filename'] ?? '');
    $fileSize = (int)($_POST['file_size']        ?? 0);
    $desc     = trim($_POST['description']       ?? '');
    $pubKey   = trim($_POST['public_key']        ?? '');
    $nodeInfo = trim($_POST['node_info']         ?? '');
    $jsonUrl  = trim($_POST['source_json_url']   ?? '');

    // Validações
    if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok'=>false,'msg'=>'URL inválida.']); exit;
    }
    $ext = extractValidExtension($fileUrl);
    if (!$ext) {
        echo json_encode(['ok'=>false,'msg'=>'A URL deve terminar com uma extensão de arquivo válida (ex: .jpg, .mp4, .pdf, .zip…)']); exit;
    }
    if (strlen($filename) < 1) {
        echo json_encode(['ok'=>false,'msg'=>'Nome do arquivo é obrigatório.']); exit;
    }

    // O "hash" é o nome do arquivo na URL (sem extensão) — precisa ser um SHA-256 válido
    $fileHash = extractFileHash($fileUrl);
    if (!$fileHash) {
        echo json_encode(['ok'=>false,'msg'=>'O nome do arquivo na URL deve ser um hash SHA-256 válido (64 caracteres hexadecimais), ex: ' . str_repeat('a1', 32) . '.' . $ext]); exit;
    }

    // Verifica se o link está online (pode ser desativado via ONLINE_CHECK)
    if (ONLINE_CHECK && !isUrlOnline($fileUrl)) {
        echo json_encode(['ok'=>false,'msg'=>'O link parece estar offline ou inacessível. Verifique a URL e tente novamente.']); exit;
    }

    try {
        $pdo->beginTransaction();

        // Verifica se o hash já está registrado na file_registry
        $check = $pdo->prepare("SELECT id, hosts FROM file_registry WHERE file_hash = ? FOR UPDATE");
        $check->execute([$fileHash]);
        $existing = $check->fetch();

        if ($existing) {
            // Hash já existe: nenhuma nova entrada — apenas adiciona o host (link) se ainda não estiver presente
            $hosts = decodeHosts($existing['hosts']);
            if (!in_array($fileUrl, $hosts, true)) {
                $hosts[] = $fileUrl;
                $pdo->prepare("UPDATE file_registry SET hosts = ? WHERE id = ?")
                    ->execute([encodeHosts($hosts), $existing['id']]);
            }
        } else {
            // Hash novo: cria o registro na file_registry
            $pdo->prepare("INSERT INTO file_registry
                (file_hash, server_filename, original_filename, file_size, extension, public_key, node_info, description, hosts)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$fileHash, basename($fileUrl), $filename, $fileSize, $ext,
                           $pubKey ?: null, $nodeInfo ?: null, $desc ?: null, encodeHosts([$fileUrl])]);
        }

        if (!INSERT_CHECK) {
            // INSERT_CHECK desativado: só opera na file_registry, sem tocar nas demais tabelas nem no saldo
            $pdo->commit();
            echo json_encode([
                'ok'          => true,
                'msg'         => 'Arquivo registrado com sucesso.',
                'balance'     => (int)$u['balance'],
                'balance_fmt' => formatBRL((int)$u['balance']),
            ]);
            exit;
        }

        // INSERT_CHECK ativado: realiza as demais operações normalmente (submitted_links + saldo)
        $pdo->prepare("INSERT INTO submitted_links
            (user_id, file_url, original_filename, extension, file_size, description, public_key, node_info, source_json_url)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$u['id'], $fileUrl, $filename, $ext, $fileSize, $desc ?: null,
                       $pubKey ?: null, $nodeInfo ?: null, $jsonUrl ?: null]);

        // Débito de saldo (atômico — só debita se ainda houver saldo suficiente)
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?")
            ->execute([SUBMIT_COST, $u['id'], SUBMIT_COST]);

        if ($pdo->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Saldo insuficiente (conferido no servidor).']); exit;
        }

        // Registra transação
        $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (?,?,?,?)")
            ->execute([$u['id'], 'debit', SUBMIT_COST, 'Envio de link: ' . mb_substr(basename($fileUrl), 0, 60)]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Erro ao salvar o link. Nenhum valor foi cobrado.']); exit;
    }

    // Atualiza sessão
    reloadUser($pdo);
    $fresh = currentUser();

    echo json_encode([
        'ok'      => true,
        'msg'     => 'Link enviado com sucesso! Aguardando aprovação.',
        'balance' => (int)$fresh['balance'],
        'balance_fmt' => formatBRL((int)$fresh['balance']),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// 5. LÓGICA DE PÁGINA
// ═══════════════════════════════════════════════════════════════
$currentUser = currentUser();

// Histórico do usuário logado
$myLinks = [];
if ($currentUser) {
    reloadUser($pdo);
    $currentUser = currentUser();
    $lStmt = $pdo->prepare("SELECT * FROM submitted_links WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 30");
    $lStmt->execute([$currentUser['id']]);
    $myLinks = $lStmt->fetchAll();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enviar Link — <?= SITE_NAME ?></title>
<style>
/* ── Design tokens (idêntico ao meento.php) ──────── */
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
    max-width: 860px;
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
.btn-block   { width: 100%; justify-content: center; padding: 12px; font-size: .95rem; }

/* ── Cards ───────────────────────────────────────── */
.section-card {
    background: var(--white);
    border: 1px solid #e2e8f0;
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    padding: 24px;
    margin-bottom: 24px;
}
.section-card h2 {
    font-size: 1.05rem;
    color: var(--g800);
    margin-bottom: 16px;
    border-bottom: 2px solid var(--g100);
    padding-bottom: 10px;
}

/* ── Formulários ─────────────────────────────────── */
.form-group { margin-bottom: 18px; }
.form-group label {
    display: block;
    font-size: .85rem;
    font-weight: 600;
    color: var(--neutral700);
    margin-bottom: 6px;
}
.form-control {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid var(--neutral300);
    border-radius: 8px;
    font-size: .92rem;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
    background: var(--white);
    color: var(--neutral900);
}
.form-control:focus { border-color: var(--g500); box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
.form-control:disabled { background: var(--neutral100); color: var(--neutral500); cursor: not-allowed; }
.form-hint { font-size: .78rem; color: var(--neutral500); margin-top: 4px; }
.form-hint.error { color: var(--danger); }
.form-hint.success { color: var(--g700); }

/* ── URL Input row ───────────────────────────────── */
.url-row {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}
.url-row .form-control { flex: 1; }

/* ── Cost badge ──────────────────────────────────── */
.cost-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, var(--g050), var(--g100));
    border: 1.5px solid var(--g300);
    border-radius: 20px;
    padding: 4px 14px;
    font-size: .82rem;
    font-weight: 700;
    color: var(--g800);
}

/* ── Balance strip ───────────────────────────────── */
.balance-strip {
    background: linear-gradient(135deg, var(--g800), var(--g700));
    color: var(--white);
    border-radius: var(--radius);
    padding: 18px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}
.balance-strip .bal-label { font-size: .85rem; opacity: .8; }
.balance-strip .bal-value { font-size: 2rem; font-weight: 800; color: var(--g400); }

/* ── Page header ─────────────────────────────────── */
.page-header { margin-bottom: 28px; }
.page-header h1 { font-size: 1.7rem; color: var(--g800); margin-bottom: 6px; }
.page-header p { color: var(--neutral500); }

/* ── Ext tags ────────────────────────────────────── */
.ext-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 8px;
}
.ext-tag {
    background: var(--g100);
    color: var(--g700);
    font-size: .72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    letter-spacing: .3px;
    text-transform: uppercase;
}

/* ── JSON preview ────────────────────────────────── */
.json-preview {
    background: var(--g050);
    border: 1.5px solid var(--g200, #bbf7d0);
    border-radius: 8px;
    padding: 14px 16px;
    margin-top: 10px;
    display: none;
}
.json-preview h4 { font-size: .82rem; font-weight: 700; color: var(--g800); margin-bottom: 8px; }
.json-preview .json-url {
    font-family: var(--font-mono);
    font-size: .75rem;
    color: var(--neutral500);
    word-break: break-all;
    margin-bottom: 10px;
}

/* ── Spinner ─────────────────────────────────────── */
.spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2.5px solid rgba(255,255,255,.4);
    border-top-color: var(--white);
    border-radius: 50%;
    animation: spin .7s linear infinite;
    vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── History table ───────────────────────────────── */
.tx-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.tx-table th {
    text-align: left;
    padding: 8px 12px;
    background: var(--g050);
    color: var(--g800);
    font-weight: 700;
    border-bottom: 2px solid var(--g100);
}
.tx-table td { padding: 9px 12px; border-bottom: 1px solid var(--neutral100); word-break: break-all; }
.tx-table tr:hover td { background: var(--g050); }

/* ── Status badges ───────────────────────────────── */
.badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 700;
}
.badge-pending  { background: #fef9c3; color: #713f12; }
.badge-approved { background: var(--g100); color: var(--g800); }
.badge-rejected { background: #fee2e2; color: #7f1d1d; }

/* ── Login wall ──────────────────────────────────── */
.login-wall {
    background: linear-gradient(135deg, var(--g050), var(--g100));
    border: 2px solid var(--g300);
    border-radius: var(--radius);
    padding: 36px 24px;
    text-align: center;
}
.login-wall h3 { color: var(--g700); font-size: 1.2rem; margin-bottom: 10px; }
.login-wall p  { font-size: .9rem; color: var(--neutral700); margin-bottom: 20px; }

/* ── Toast notification ──────────────────────────── */
.toast {
    position: fixed;
    bottom: 28px;
    right: 24px;
    z-index: 9999;
    min-width: 280px;
    max-width: 420px;
    padding: 14px 20px;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: .9rem;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
    opacity: 0;
    transform: translateY(16px);
    transition: opacity .25s, transform .25s;
    pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast-success { background: var(--g800); color: var(--white); }
.toast-error   { background: #7f1d1d; color: var(--white); }

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 640px) {
    .url-row { flex-direction: column; }
    .balance-strip { flex-direction: column; align-items: flex-start; gap: 10px; }
}
</style>
</head>
<body>

<!-- ── Navegação ────────────────────────────────────────────── -->
<nav class="nav">
    <div class="nav-inner">
        <a href="meento.php" class="nav-logo"><?= SITE_NAME ?><span>.</span></a>
        <div class="nav-links">
            <a href="meento.php?page=home">Início</a>
            <a href="submit_link.php" class="active">Enviar</a>
            <a href="meento.php?page=browse">Arquivos</a>
            <?php if ($currentUser): ?>
                <a href="meento.php?page=wallet">
                    Carteira
                    <span class="nav-badge" id="nav-balance"><?= formatBRL((int)$currentUser['balance']) ?></span>
                </a>
                <a href="meento.php?action=logout">Sair (<?= htmlspecialchars($currentUser['username']) ?>)</a>
            <?php else: ?>
                <a href="meento.php?page=login">Entrar</a>
                <a href="meento.php?page=register">Cadastrar</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container">

    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="page-header">
        <h1>📤 Enviar Link de Arquivo</h1>
        <p>Compartilhe um link de arquivo hospedado em outro servidor. Cada envio custa
            <strong><?= formatBRL(SUBMIT_COST) ?></strong>.</p>
    </div>

    <?php if (!$currentUser): ?>
    <!-- ── Wall: não logado ──────────────────────────────────── -->
    <div class="login-wall">
        <h3>🔒 Acesso restrito</h3>
        <p>Você precisa estar logado para enviar links.<br>
           Cada envio custa <strong><?= formatBRL(SUBMIT_COST) ?></strong> e é debitado do seu saldo.</p>
        <a href="meento.php?page=login" class="btn btn-primary btn-sm">Entrar</a>
        &nbsp;
        <a href="meento.php?page=register" class="btn btn-outline btn-sm">Criar conta</a>
    </div>

    <?php else: ?>

    <!-- ── Saldo ─────────────────────────────────────────────── -->
    <div class="balance-strip">
        <div>
            <div class="bal-label">Seu saldo atual</div>
            <div class="bal-value" id="balance-display"><?= formatBRL((int)$currentUser['balance']) ?></div>
        </div>
        <div style="margin-left:auto;text-align:right">
            <div class="cost-badge">💸 Custo por envio: <?= formatBRL(SUBMIT_COST) ?></div>
            <div style="margin-top:8px">
                <a href="meento.php?page=wallet" class="btn btn-outline btn-sm" style="color:var(--g300);border-color:var(--g400)">
                    ➕ Adicionar saldo
                </a>
            </div>
        </div>
    </div>

    <?php if ((int)$currentUser['balance'] < SUBMIT_COST): ?>
    <div class="flash flash-error" style="margin-bottom:24px">
        ⚠️ Saldo insuficiente para enviar links. <a href="meento.php?page=wallet" style="color:var(--danger);font-weight:800">Recarregue sua carteira →</a>
    </div>
    <?php endif; ?>

    <!-- ── Formulário principal ─────────────────────────────── -->
    <div class="section-card">
        <h2>🔗 Informações do Link</h2>

        <div class="form-group">
            <label for="file_url">URL do arquivo *</label>
            <div class="url-row">
                <input type="url" id="file_url" class="form-control"
                       placeholder="https://exemplo.com/files/documento.pdf"
                       autocomplete="off" spellcheck="false">
                <button class="btn btn-outline btn-sm" id="btn-fetch-json" type="button" title="Buscar informações automaticamente do servidor">
                    🔍 Carregar Info
                </button>
            </div>
            <div class="form-hint" id="url-hint">
                A URL deve terminar com uma extensão válida:
            </div>
            <div class="ext-tags">
                <?php foreach (['jpg','jpeg','png','gif','webp','svg','mp4','mp3','wav','pdf','zip','rar','doc','docx','xls','xlsx'] as $e): ?>
                    <span class="ext-tag"><?= $e ?></span>
                <?php endforeach; ?>
                <span class="ext-tag" style="background:var(--neutral100);color:var(--neutral500)">+ outros</span>
            </div>
        </div>

        <!-- Preview do JSON buscado -->
        <div class="json-preview" id="json-preview">
            <h4>✅ Informações carregadas do servidor:</h4>
            <div class="json-url" id="json-url-display"></div>
        </div>

        <hr style="border:none;border-top:1px solid var(--g100);margin:4px 0 20px">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group" style="margin-bottom:0">
                <label for="original_filename">Nome do arquivo *</label>
                <input type="text" id="original_filename" class="form-control"
                       placeholder="ex: relatorio-q3.pdf">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label for="file_size">Tamanho (bytes)</label>
                <input type="number" id="file_size" class="form-control"
                       placeholder="0" min="0">
                <div class="form-hint" id="size-hint"></div>
            </div>
        </div>

        <div class="form-group" style="margin-top:16px">
            <label for="description">Descrição</label>
            <textarea id="description" class="form-control" rows="3"
                      placeholder="Descreva brevemente o conteúdo do arquivo (opcional)"></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group" style="margin-bottom:0">
                <label for="public_key">Chave pública</label>
                <input type="text" id="public_key" class="form-control"
                       placeholder="(opcional)">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label for="node_info">Informações do nó</label>
                <input type="text" id="node_info" class="form-control"
                       placeholder="(opcional)">
            </div>
        </div>

        <!-- Aviso de custo -->
        <div style="background:var(--g050);border:1.5px solid var(--g200,#bbf7d0);border-radius:8px;padding:12px 16px;margin-top:22px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="font-size:1.3rem">💳</span>
            <div style="flex:1">
                <strong style="color:var(--g800)">Custo do envio: <?= formatBRL(SUBMIT_COST) ?></strong>
                <div style="font-size:.82rem;color:var(--neutral500)">
                    Será debitado do seu saldo. Seu saldo atual:
                    <strong id="inline-balance"><?= formatBRL((int)$currentUser['balance']) ?></strong>
                </div>
            </div>
            <button class="btn btn-primary" id="btn-submit" type="button"
                    <?= (int)$currentUser['balance'] < SUBMIT_COST ? 'disabled title="Saldo insuficiente"' : '' ?>>
                📤 Enviar Link
            </button>
        </div>

        <div id="submit-feedback" style="margin-top:12px;display:none"></div>
    </div>

    <!-- ── Histórico do usuário ──────────────────────────────── -->
    <?php if ($myLinks): ?>
    <div class="section-card">
        <h2>📋 Meus Envios Recentes</h2>
        <div style="overflow-x:auto">
        <table class="tx-table">
            <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myLinks as $link): ?>
                <tr>
                    <td>
                        <span style="font-size:1.1rem"><?= extIcon($link['extension']) ?></span>
                        <strong><?= htmlspecialchars($link['original_filename']) ?></strong>
                        <div style="font-size:.75rem;color:var(--neutral500);text-transform:uppercase;letter-spacing:.3px">
                            <?= htmlspecialchars(strtoupper($link['extension'])) ?>
                        </div>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($link['file_url']) ?>" target="_blank"
                           style="color:var(--g700);font-size:.8rem;word-break:break-all">
                            <?= htmlspecialchars(mb_substr($link['file_url'], 0, 60)) . (strlen($link['file_url']) > 60 ? '…' : '') ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge badge-<?= $link['status'] ?>">
                            <?= ['pending'=>'⏳ Pendente','approved'=>'✅ Aprovado','rejected'=>'❌ Rejeitado'][$link['status']] ?? $link['status'] ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;color:var(--neutral500);font-size:.8rem">
                        <?= date('d/m/Y H:i', strtotime($link['submitted_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // logado ?>

</div><!-- /container -->

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ── JavaScript ───────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    const SUBMIT_COST = <?= SUBMIT_COST ?>;

    // ── Helpers ───────────────────────────────────────────────
    function showToast(msg, type = 'success') {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className   = 'toast toast-' + type + ' show';
        clearTimeout(t._tid);
        t._tid = setTimeout(() => t.classList.remove('show'), 4000);
    }

    function humanSize(bytes) {
        bytes = parseInt(bytes) || 0;
        if (bytes === 0) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function formatBRL(centavos) {
        return 'R$ ' + (centavos / 100).toFixed(2).replace('.', ',');
    }

    // ── Elementos ─────────────────────────────────────────────
    const elUrl      = document.getElementById('file_url');
    const elFilename = document.getElementById('original_filename');
    const elSize     = document.getElementById('file_size');
    const elDesc     = document.getElementById('description');
    const elPubKey   = document.getElementById('public_key');
    const elNode     = document.getElementById('node_info');
    const elHint     = document.getElementById('url-hint');
    const elSizeHint = document.getElementById('size-hint');
    const elPreview  = document.getElementById('json-preview');
    const elJsonUrl  = document.getElementById('json-url-display');
    const btnFetch   = document.getElementById('btn-fetch-json');
    const btnSubmit  = document.getElementById('btn-submit');
    const elFeedback = document.getElementById('submit-feedback');

    let currentJsonUrl = '';

    // ── Validação de URL em tempo real ────────────────────────
    const VALID_EXTS = ['pdf','mp3','mp4','jpg','jpeg','gif','png','zip',
                        'avi','mkv','mov','webm','ogg','wav','flac','webp',
                        'svg','rar','7z','tar','gz','doc','docx','xls',
                        'xlsx','ppt','pptx','txt','image','video'];

    function getExtFromUrl(url) {
        try {
            const path = new URL(url).pathname;
            const ext  = path.split('.').pop().toLowerCase();
            return ext && VALID_EXTS.includes(ext) ? ext : null;
        } catch { return null; }
    }

    elUrl.addEventListener('input', function () {
        const val = this.value.trim();
        const ext = val ? getExtFromUrl(val) : null;
        elPreview.style.display = 'none';
        currentJsonUrl = '';

        if (!val) {
            elHint.textContent = 'A URL deve terminar com uma extensão válida:';
            elHint.className   = 'form-hint';
            this.style.borderColor = '';
            return;
        }
        try { new URL(val); } catch {
            elHint.textContent = '⚠️ URL inválida.';
            elHint.className   = 'form-hint error';
            this.style.borderColor = 'var(--danger)';
            return;
        }
        if (!ext) {
            elHint.textContent = '⚠️ Extensão não reconhecida. Use: jpg, png, mp4, pdf, zip…';
            elHint.className   = 'form-hint error';
            this.style.borderColor = 'var(--danger)';
        } else {
            elHint.textContent = '✅ Extensão válida: .' + ext;
            elHint.className   = 'form-hint success';
            this.style.borderColor = 'var(--g500)';
            // Preenche nome do arquivo automaticamente se vazio
            if (!elFilename.value) {
                try {
                    const fname = new URL(val).pathname.split('/').pop();
                    if (fname) elFilename.value = fname;
                } catch {}
            }
        }
    });

    // Hint de tamanho legível
    elSize.addEventListener('input', function () {
        const h = humanSize(this.value);
        elSizeHint.textContent = h ? '≈ ' + h : '';
    });

    // ── Buscar JSON do servidor ───────────────────────────────
    btnFetch.addEventListener('click', async function () {
        const url = elUrl.value.trim();
        if (!url) { showToast('Insira uma URL primeiro.', 'error'); return; }
        if (!getExtFromUrl(url)) { showToast('URL inválida ou extensão não permitida.', 'error'); return; }

        const orig = this.innerHTML;
        this.innerHTML = '<span class="spinner"></span> Buscando…';
        this.disabled  = true;

        try {
            const res  = await fetch('?action=fetch_json', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'url=' + encodeURIComponent(url)
            });
            const data = await res.json();

            if (!data.ok) {
                showToast(data.msg || 'Erro ao buscar informações.', 'error');
            } else {
                const d = data.data;
                if (d.original_filename) elFilename.value = d.original_filename;
                if (d.file_size)         elSize.value     = d.file_size,
                                         elSizeHint.textContent = '≈ ' + humanSize(d.file_size);
                if (d.description)       elDesc.value     = d.description;
                if (d.public_key)        elPubKey.value   = d.public_key;
                if (d.node_info)         elNode.value     = typeof d.node_info === 'object'
                                                            ? JSON.stringify(d.node_info) : d.node_info;
                currentJsonUrl = data.json_url;
                elJsonUrl.textContent    = data.json_url;
                elPreview.style.display  = 'block';
                showToast('✅ Informações carregadas com sucesso!');
            }
        } catch (e) {
            showToast('Erro de rede ao buscar JSON.', 'error');
        } finally {
            this.innerHTML = orig;
            this.disabled  = false;
        }
    });

    // ── Enviar link ───────────────────────────────────────────
    <?php if ($currentUser): ?>
    let currentBalance = <?= (int)$currentUser['balance'] ?>;

    btnSubmit && btnSubmit.addEventListener('click', async function () {
        const url      = elUrl.value.trim();
        const filename = elFilename.value.trim();

        if (!url)      { showToast('Informe a URL do arquivo.', 'error'); elUrl.focus(); return; }
        if (!getExtFromUrl(url)) { showToast('URL inválida ou extensão não permitida.', 'error'); elUrl.focus(); return; }
        if (!filename) { showToast('Informe o nome do arquivo.', 'error'); elFilename.focus(); return; }
        if (currentBalance < SUBMIT_COST) {
            showToast('Saldo insuficiente. Recarregue sua carteira.', 'error'); return;
        }

        const orig     = this.innerHTML;
        this.innerHTML = '<span class="spinner"></span> Enviando…';
        this.disabled  = true;
        elFeedback.style.display = 'none';

        const body = new URLSearchParams({
            file_url:          url,
            original_filename: filename,
            file_size:         elSize.value     || '0',
            description:       elDesc.value     || '',
            public_key:        elPubKey.value   || '',
            node_info:         elNode.value      || '',
            source_json_url:   currentJsonUrl   || '',
        });

        try {
            const res  = await fetch('?action=submit_link', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const data = await res.json();

            if (data.ok) {
                currentBalance = data.balance;
                // Atualiza displays de saldo
                document.querySelectorAll('#balance-display, #inline-balance, #nav-balance').forEach(el => {
                    el.textContent = data.balance_fmt;
                });
                if (currentBalance < SUBMIT_COST && btnSubmit) btnSubmit.disabled = true;

                // Limpa formulário
                elUrl.value = elFilename.value = elSize.value = '';
                elDesc.value = elPubKey.value = elNode.value = '';
                elUrl.style.borderColor = '';
                elSizeHint.textContent  = '';
                elHint.textContent      = 'A URL deve terminar com uma extensão válida:';
                elHint.className        = 'form-hint';
                elPreview.style.display = 'none';
                currentJsonUrl          = '';

                elFeedback.innerHTML    = '<div class="flash flash-success">✅ ' + data.msg + '</div>';
                elFeedback.style.display = 'block';
                showToast('✅ Link enviado com sucesso!');

                // Recarrega histórico após breve delay
                setTimeout(() => location.reload(), 2500);
            } else {
                elFeedback.innerHTML    = '<div class="flash flash-error">⚠️ ' + data.msg + '</div>';
                elFeedback.style.display = 'block';
                showToast(data.msg || 'Erro ao enviar.', 'error');
                this.innerHTML = orig;
                this.disabled  = false;
            }
        } catch (e) {
            showToast('Erro de rede. Tente novamente.', 'error');
            this.innerHTML = orig;
            this.disabled  = false;
        }
    });
    <?php endif; ?>

})();
</script>
</body>
</html>