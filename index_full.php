<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  MESH NODE — authenticated file replication over HTTP/HTTPS
//  Account files are keyed by the user's public_key (sanitized), not a hash.
//  Single-file PHP 7.4+ application
// ═══════════════════════════════════════════════════════════════════════════════

define('FILES_DIR',        __DIR__ . '/files');
define('INFO_DIR',         __DIR__ . '/info');
define('FILES_INDEX',      __DIR__ . '/files.txt');
define('SERVERS_FILE',     __DIR__ . '/servers.txt');
define('NODE_PUB_KEY',     __DIR__ . '/public_key.txt');
define('NODE_INFO_FILE',   __DIR__ . '/node_info.txt');
define('ACCOUNTS_DIR',     __DIR__ . '/accounts');
define('TRANSACTIONS_DIR', __DIR__ . '/transactions');
define('MAX_COMMENT',      1 * 1024);
define('BLOCKED_EXT',      ['php','php3','php4','php5','php7','phtml','phar']);

foreach ([FILES_DIR, INFO_DIR, ACCOUNTS_DIR, TRANSACTIONS_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}
if (!file_exists(FILES_INDEX)) file_put_contents(FILES_INDEX, '');

// ══════════════════════════════════════════════════════════════════════════════
//  Helpers — account key derived from public_key
//  We sanitize the public key to a safe filename: base64url chars + dots only,
//  then truncate to 200 chars to stay within filesystem limits.
// ══════════════════════════════════════════════════════════════════════════════
function pubkeyToFilename(string $publicKey): string {
    // Keep alphanumeric, +, /, =, - (base64 / base64url chars) and replace the rest
    $safe = preg_replace('/[^A-Za-z0-9+\/=\-_]/', '_', trim($publicKey));
    // Collapse runs of underscores and trim to 200 chars
    $safe = preg_replace('/_+/', '_', $safe);
    $safe = trim($safe, '_');
    return substr($safe, 0, 200);
}

function accountPath(string $publicKey): string {
    return ACCOUNTS_DIR . '/' . pubkeyToFilename($publicKey) . '.json';
}

function transactionPath(string $publicKey): string {
    return TRANSACTIONS_DIR . '/' . pubkeyToFilename($publicKey) . '.log';
}

function nowIso(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
}

// ══════════════════════════════════════════════════════════════════════════════
//  UserStore — accounts keyed by public_key (plain, sanitized as filename)
// ══════════════════════════════════════════════════════════════════════════════
class UserStore
{
    private int $maxFieldLen = 512;

    // ── Register ──────────────────────────────────────────────────────────────
    public function register(
        string $username,
        string $password,
        string $publicKey,
        string $mail,
        string $aboutMe,
        string $userServer = ''
    ): array {
        $required = [
            'username'   => $username,
            'password'   => $password,
            'public_key' => $publicKey,
            'mail'       => $mail,
            'about_me'   => $aboutMe,
        ];
        foreach ($required as $field => $value) {
            if ($value === '') return ['ok' => false, 'message' => "Field '$field' must not be empty."];
            if (strlen($value) > $this->maxFieldLen) return ['ok' => false, 'message' => "Field '$field' exceeds 512 characters."];
        }
        if (strlen($userServer) > $this->maxFieldLen) return ['ok' => false, 'message' => "Field 'user_server' exceeds 512 characters."];

        $path = accountPath($publicKey);
        if (file_exists($path)) return ['ok' => false, 'message' => 'A account with this public key already exists.'];

        $data = [
            'username'    => $username,
            'password'    => hash('sha512', $password),
            'public_key'  => $publicKey,
            'mail'        => $mail,
            'about_me'    => $aboutMe,
            'user_server' => $userServer,
            'balance'     => 0,
            'files'       => [],   // array of file-record objects
            'servers'     => [],   // { sanitized_server: raw_address }
            'dates'       => [],   // { sanitized_server: ISO datetime }
            'created_at'  => nowIso(),
            'updated_at'  => nowIso(),
        ];

        return $this->safeWrite($publicKey, $data)
            ? ['ok' => true, 'message' => 'Account created successfully.']
            : ['ok' => false, 'message' => 'Failed to write account file.'];
    }

    // ── Login ─────────────────────────────────────────────────────────────────
    public function login(string $publicKey, string $password): array
    {
        $data = $this->loadByKey($publicKey);
        if ($data === null) return ['ok' => false, 'message' => 'Account not found.'];
        if (!hash_equals($data['password'], hash('sha512', $password))) return ['ok' => false, 'message' => 'Invalid password.'];
        $safe = $data;
        unset($safe['password']);
        return ['ok' => true, 'message' => 'Login successful.', 'data' => $safe];
    }

    // ── Add / update file record ───────────────────────────────────────────────
    public function addFileRecord(string $publicKey, array $fileRecord): array
    {
        $data = $this->loadByKey($publicKey);
        if ($data === null) return ['ok' => false, 'message' => 'Account not found.'];

        // Look for duplicate by hash — merge servers + dates if found
        foreach ($data['files'] as &$f) {
            if (is_array($f) && ($f['hash'] ?? '') === $fileRecord['hash']) {
                // Merge server lists — dedup by URL key AND resolved IP hash
                $knownUrlKeys  = [];
                $knownIpHashes = [];
                foreach ($f['servers'] ?? [] as $s) {
                    $knownUrlKeys[]  = serverUrlKey($s);
                    $ih = serverIpHash($s);
                    if ($ih !== null) $knownIpHashes[] = $ih;
                }
                foreach ($fileRecord['servers'] ?? [] as $s) {
                    $uk    = serverUrlKey($s);
                    $ih    = serverIpHash($s);
                    $dupUrl = in_array($uk, $knownUrlKeys, true);
                    $dupIp  = ($ih !== null) && in_array($ih, $knownIpHashes, true);
                    if (!$dupUrl && !$dupIp) {
                        $f['servers'][]  = $s;
                        $knownUrlKeys[]  = $uk;
                        if ($ih !== null) $knownIpHashes[] = $ih;
                    }
                }
                // Merge dates map — new entries added, existing entries preserved (first-seen wins)
                $existingDates = $f['dates'] ?? [];
                $newDates      = $fileRecord['dates'] ?? [];
                foreach ($newDates as $srv => $dt) {
                    if (!isset($existingDates[$srv])) {
                        $existingDates[$srv] = $dt;
                    }
                }
                $f['dates']         = $existingDates;
                $f['last_verified'] = nowIso();
                unset($f);
                $data['updated_at'] = nowIso();
                return $this->safeWrite($publicKey, $data)
                    ? ['ok' => true, 'message' => 'File record updated (servers merged).']
                    : ['ok' => false, 'message' => 'Failed to save account.'];
            }
        }
        unset($f);

        $data['files'][]    = $fileRecord;
        $data['updated_at'] = nowIso();
        return $this->safeWrite($publicKey, $data)
            ? ['ok' => true, 'message' => 'File record added.']
            : ['ok' => false, 'message' => 'Failed to save account.'];
    }

    // ── Report a server that confirmed storage ─────────────────────────────────
    public function reportServer(string $publicKey, string $serverAddress): array
    {
        $data = $this->loadByKey($publicKey);
        if ($data === null) return ['ok' => false, 'message' => 'Account not found.', 'awarded' => false];

        // Ensure legacy accounts have the new ip-hash list
        if (!isset($data['server_ip_hashes'])) $data['server_ip_hashes'] = [];

        $urlKey = serverUrlKey($serverAddress);   // sha256 of normalised URL — dedup key
        $ipHash = serverIpHash($serverAddress);   // sha256 of resolved IP (may be null)
        $awarded = false;

        // Reject if the resolved IP is already tracked (different URL, same machine)
        if ($ipHash !== null && in_array($ipHash, $data['server_ip_hashes'], true)) {
            return [
                'ok'          => true,
                'message'     => 'Server with this IP already registered (duplicate IP rejected).',
                'awarded'     => false,
                'new_balance' => $data['balance'],
            ];
        }

        if (!isset($data['servers'][$urlKey])) {
            $data['servers'][$urlKey]  = $serverAddress;
            $data['dates'][$urlKey]    = nowIso();
            if ($ipHash !== null) $data['server_ip_hashes'][] = $ipHash;
            $data['balance']++;
            $data['updated_at'] = nowIso();
            $awarded = true;
        } else {
            $data['dates'][$urlKey] = nowIso();
        }

        if (!$this->safeWrite($publicKey, $data)) return ['ok' => false, 'message' => 'Failed to save account.', 'awarded' => false];
        if ($awarded) $this->logTransaction($publicKey, $serverAddress, $urlKey, $data['balance']);

        return [
            'ok'          => true,
            'message'     => $awarded ? '+1 balance for new server.' : 'Server already registered; date updated.',
            'awarded'     => $awarded,
            'new_balance' => $data['balance'],
        ];
    }

    // ── Get public profile (no password) ──────────────────────────────────────
    public function getProfile(string $publicKey): ?array
    {
        $data = $this->loadByKey($publicKey);
        if ($data === null) return null;
        unset($data['password']);
        return $data;
    }

    // ── Internal helpers ───────────────────────────────────────────────────────
    public function loadByKey(string $publicKey): ?array
    {
        $path = accountPath($publicKey);
        if (!file_exists($path)) return null;
        $raw  = file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function safeWrite(string $publicKey, array $data): bool
    {
        $real = accountPath($publicKey);
        $tmp  = $real . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        if (json_decode(file_get_contents($tmp), true) === null) return false;
        if (PHP_OS_FAMILY === 'Windows' && file_exists($real)) unlink($real);
        if (!rename($tmp, $real)) return false;
        file_put_contents($tmp, $json, LOCK_EX); // keep .tmp backup
        return true;
    }

    private function logTransaction(string $publicKey, string $server, string $serverKey, int $balance): void
    {
        $line = sprintf("[%s] +1 point | server: %s (key: %s) | balance: %d\n", nowIso(), $server, $serverKey, $balance);
        file_put_contents(transactionPath($publicKey), $line, FILE_APPEND | LOCK_EX);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Mesh Node helpers
// ══════════════════════════════════════════════════════════════════════════════
// ── Server deduplication helpers ─────────────────────────────────────────────
// A server entry is considered duplicate if it shares either its normalised URL
// OR the sha256 of its resolved IP address with an already-known server.
// The IP hash is stored in the account JSON under 'server_ip_hashes'.

/**
 * Resolve a URL's hostname to an IP and return sha256(ip).
 * Returns null on resolution failure (unresolvable hostnames are still allowed
 * but won't be matched against IP duplicates).
 */
function serverIpHash(string $serverUrl): ?string {
    $host = parse_url(rtrim($serverUrl, '/'), PHP_URL_HOST);
    if (!$host) return null;
    $ip = @gethostbyname($host);
    // gethostbyname returns the input unchanged when resolution fails
    if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    return hash('sha256', $ip);
}

/**
 * Canonical form of a server URL used as the unique key in the servers map.
 * Strips scheme, trailing slashes and the script filename so that
 * "http://host/path/", "https://host/path" and "http://host/path/index_full.php"
 * all map to the same key.
 */
function serverUrlKey(string $serverUrl): string {
    $url = rtrim(strtolower(trim($serverUrl)), '/');
    $url = preg_replace('#^https?://#', '', $url);
    $url = preg_replace('#/' . preg_quote(basename(__FILE__), '#') . '$#i', '', $url);
    return hash('sha256', $url);   // store the hash, not the raw URL (safe as map key)
}

function blockedExt(string $ext): bool { return in_array(strtolower($ext), BLOCKED_EXT, true); }
function safeExt(string $fn): string   { return preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($fn, PATHINFO_EXTENSION))); }
function jsonOut(array $d, int $c=200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function readOptional(string $p): string { return file_exists($p) ? trim((string)file_get_contents($p)) : ''; }

function appendIndex(string $entry): void {
    $lines = file_exists(FILES_INDEX) ? array_map('trim', file(FILES_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    if (!in_array($entry, $lines, true)) file_put_contents(FILES_INDEX, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function loadServers(): array {
    if (!file_exists(SERVERS_FILE)) return [];
    $script = basename(__FILE__);
    $seen = []; $out = [];
    foreach (file(SERVERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('#^https?://#i', $line)) $line = 'http://' . $line;
        $line = rtrim($line, '/');
        // Normalise: strip trailing script filename so both forms map to the same base
        $base = preg_replace('#/' . preg_quote($script, '#') . '$#i', '', $line);
        if (isset($seen[$base])) continue;
        $seen[$base] = true;
        $out[] = $base;
    }
    return $out;
}

function storeLocally(string $tmp, string $origName, string $desc, string $pubKey, string $nodeInfo): array {
    $ext  = safeExt($origName);
    if (blockedExt($ext)) return ['ok' => false, 'error' => 'PHP files are not accepted.'];
    $hash = hash_file('sha256', $tmp);
    $base = $ext !== '' ? "{$hash}.{$ext}" : $hash;
    $dest = FILES_DIR . '/' . $base;
    $info = INFO_DIR  . '/' . $hash . '.json';
    $existed = file_exists($dest);
    if (!$existed) {
        if (!copy($tmp, $dest)) return ['ok' => false, 'error' => 'Could not write file to disk.'];
        appendIndex($base);
    }
    if (!file_exists($info)) {
        file_put_contents($info, json_encode([
            'original_filename' => $origName,
            'size'              => (int)filesize($dest),
            'extension'         => $ext,
            'public_key'        => $pubKey,
            'node_info'         => $nodeInfo,
            'description'       => $desc,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return ['ok' => true, 'hash' => $hash, 'filename' => $base, 'existed' => $existed];
}

function pushToServer(string $server, string $filePath, string $fn, string $desc, string $pubKey, string $nodeInfo, string $accountJson = ''): array {
    $url = rtrim($server, '/') . '/' . basename(__FILE__);

    // Prefer cURL: avoids file_get_contents multipart quirks, gives real HTTP status,
    // and sidesteps the $http_response_header scoping bug entirely.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fields = [
            'file'        => new CURLFile($filePath, @mime_content_type($filePath) ?: 'application/octet-stream', $fn),
            'description' => $desc,
            'public_key'  => $pubKey,
            'node_info'   => $nodeInfo,
            'peer_push'   => '1',
        ];
        if ($accountJson !== '') $fields['account_json'] = $accountJson;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['X-Mesh-Node: 1'],
        ]);
        $raw     = curl_exec($ch);
        $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            return ['ok' => false, 'server' => $server, 'http_status' => $status, 'error' => "Connection failed: {$curlErr}"];
        }
        $raw = (string)$raw; if (substr($raw,0,3)==="\xef\xbb\xbf") $raw=substr($raw,3);
        $dec = json_decode($raw, true);
        if (!is_array($dec)) {
            return ['ok' => false, 'server' => $server, 'http_status' => $status, 'error' => 'Bad response: ' . substr($raw, 0, 200)];
        }
        return array_merge($dec, ['server' => $server, 'http_status' => $status]);
    }

    // Fallback: file_get_contents multipart (no cURL)
    $bnd  = '----MeshBoundary' . bin2hex(random_bytes(12));
    $mime = @mime_content_type($filePath) ?: 'application/octet-stream';
    $body = "--{$bnd}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . addslashes($fn) . "\"\r\nContent-Type: {$mime}\r\n\r\n" . file_get_contents($filePath) . "\r\n";
    $txtFields = ['description' => $desc, 'public_key' => $pubKey, 'node_info' => $nodeInfo, 'peer_push' => '1'];
    if ($accountJson !== '') $txtFields['account_json'] = $accountJson;
    foreach ($txtFields as $k => $v) {
        $body .= "--{$bnd}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $body .= "--{$bnd}--\r\n";
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", ["Content-Type: multipart/form-data; boundary={$bnd}", "Content-Length: " . strlen($body), "X-Mesh-Node: 1"]), 'content' => $body, 'timeout' => 30, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'server' => $server, 'error' => 'Connection failed.'];
    if (substr($raw,0,3)==="\xef\xbb\xbf") $raw=substr($raw,3);
    $dec = json_decode($raw, true);
    if (!is_array($dec)) return ['ok' => false, 'server' => $server, 'error' => 'Bad response: ' . substr($raw, 0, 200)];
    return array_merge($dec, ['server' => $server]);
}

/**
 * Push the full updated account JSON to a peer so it can overwrite accounts/<key>.json.
 * The peer receives it via POST field account_sync=1.  Failures are silent — sync is best-effort.
 */
function pushAccountToServer(string $server, string $accountJson, string $publicKey): void {
    $url = rtrim($server, '/') . '/' . basename(__FILE__);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'account_sync' => '1',
                'public_key'   => $publicKey,
                'account_json' => $accountJson,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['X-Mesh-Node: 1', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    $body = http_build_query(['account_sync' => '1', 'public_key' => $publicKey, 'account_json' => $accountJson]);
    $ctx  = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nX-Mesh-Node: 1", 'content' => $body, 'timeout' => 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Verify that $filename (identified by $hash) is reachable on $server.
 *
 * Strategy:
 *  1. cURL HEAD on the public file URL  (preferred — no global-variable scoping issues)
 *  2. cURL GET  on the info JSON        (fallback if HEAD is blocked)
 *  3. get_headers() HEAD                (fallback when cURL is unavailable — get_headers()
 *                                        returns the headers array directly, so it is
 *                                        immune to the $http_response_header scoping bug)
 *
 * NOTE: file_get_contents() + $http_response_header is intentionally avoided here.
 *       $http_response_header is a plain global (not a superglobal), so inside any
 *       function it is always NULL unless declared with `global $http_response_header`.
 *       Using curl or get_headers() sidesteps the issue entirely.
 */
function verifyOnServer(string $server, string $hash, string $filename): array {
    $base    = rtrim($server, '/');
    $fileUrl = $base . '/files/' . rawurlencode($filename);
    $infoUrl = $base . '/info/'  . rawurlencode($hash . '.json');

    // ── Path 1: cURL (available on virtually every PHP install) ──────────────
    if (function_exists('curl_init')) {

        // 1a. HEAD on the file URL
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,   // HEAD request
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true,  'server' => $server, 'method' => 'curl_head_file', 'status' => $status];
        }
        if ($status === 0) {
            // Network-level failure — no point trying more on this server
            return ['ok' => false, 'server' => $server, 'method' => 'curl_head_file', 'status' => 0, 'error' => $err];
        }

        // 1b. GET the info JSON (handles servers that block HEAD)
        $ch2 = curl_init($infoUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body2   = curl_exec($ch2);
        $status2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($status2 >= 200 && $status2 < 300 && is_array(json_decode((string)$body2, true))) {
            return ['ok' => true,  'server' => $server, 'method' => 'curl_get_info', 'status' => $status2];
        }

        // 1c. GET the file URL as last resort (definitive but wastes bandwidth)
        $ch3 = curl_init($fileUrl);
        curl_setopt_array($ch3, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RANGE          => '0-0',  // request only 1 byte to minimise download
        ]);
        curl_exec($ch3);
        $status3 = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
        curl_close($ch3);

        // 206 Partial Content also counts as "file exists"
        $ok3 = ($status3 >= 200 && $status3 < 300) || $status3 === 206;
        return ['ok' => $ok3, 'server' => $server, 'method' => 'curl_get_file', 'status' => $status3];
    }

    // ── Path 2: get_headers() fallback (no cURL) ─────────────────────────────
    // get_headers() returns the header lines as an array — no global-variable
    // scoping problem because the result comes back as the function's return value.
    stream_context_set_default([
        'http' => ['method' => 'HEAD', 'timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $hdrs = @get_headers($fileUrl);
    if (is_array($hdrs) && isset($hdrs[0])) {
        $status = (int)substr($hdrs[0], 9, 3);
        if ($status >= 200 && $status < 300) {
            return ['ok' => true,  'server' => $server, 'method' => 'get_headers_head_file', 'status' => $status];
        }
    }

    // Fallback: GET info JSON via get_headers
    $hdrs2 = @get_headers($infoUrl);
    if (is_array($hdrs2) && isset($hdrs2[0])) {
        $status2 = (int)substr($hdrs2[0], 9, 3);
        if ($status2 >= 200 && $status2 < 300) {
            return ['ok' => true,  'server' => $server, 'method' => 'get_headers_get_info', 'status' => $status2];
        }
    }

    return ['ok' => false, 'server' => $server, 'method' => 'get_headers', 'status' => 0, 'error' => 'File not found on remote server'];
}

function appendDataJson(string $hash): void {
    ob_start(); $prev = error_reporting(0);
    try {
        $raw = @file_get_contents(INFO_DIR . '/' . $hash . '.json');
        if ($raw === false) return;
        $info = json_decode($raw, true);
        if (!is_array($info)) return;
        $ext  = $info['extension'] ?? '';
        appendIndex($ext !== '' ? "{$hash}.{$ext}" : $hash);
        $entry    = array_merge(['hash' => $hash], $info);
        $dataJson = __DIR__ . '/data.json';
        if (!file_exists($dataJson)) file_put_contents($dataJson, "[\n]", LOCK_EX);
        $fp = @fopen($dataJson, 'c+');
        if (!$fp) return;
        if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
        $content = rtrim((string)stream_get_contents($fp));
        $cp      = strrpos($content, ']');
        $enc     = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $ind     = implode("\n", array_map(fn($l) => '    ' . $l, explode("\n", $enc)));
        if ($cp === false)                                        $nc = "[\n{$ind}\n]";
        elseif (trim(substr($content, 1, $cp - 1)) === '')       $nc = "[\n{$ind}\n]";
        else                                                      $nc = rtrim(substr($content, 0, $cp)) . ",\n{$ind}\n]";
        rewind($fp); ftruncate($fp, 0); fwrite($fp, $nc); flock($fp, LOCK_UN); fclose($fp);
    } catch (\Throwable $e) {}
    finally { error_reporting($prev); ob_end_clean(); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Session nonce validation
//  Server-side: sha256(pubkey_filename + date('Y-m-d'))
// ══════════════════════════════════════════════════════════════════════════════
function expectedNonce(string $publicKey): string {
    return hash('sha256', pubkeyToFilename($publicKey) . date('Y-m-d'));
}

// ══════════════════════════════════════════════════════════════════════════════
//  JSON / multipart API routing
// ══════════════════════════════════════════════════════════════════════════════

// ── Auth actions (JSON POST) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $store  = new UserStore();

    if ($action === 'register') {
        echo json_encode($store->register(
            trim($body['username']   ?? ''),
            $body['password']        ?? '',
            trim($body['publicKey']  ?? ''),
            trim($body['mail']       ?? ''),
            trim($body['aboutMe']    ?? ''),
            trim($body['userServer'] ?? '')
        ));
        exit;
    }

    if ($action === 'login') {
        // login by public_key + password
        echo json_encode($store->login(
            trim($body['publicKey'] ?? ''),
            $body['password']       ?? ''
        ));
        exit;
    }

    if ($action === 'get_profile') {
        $pk = trim($body['public_key'] ?? '');
        $p  = $store->getProfile($pk);
        echo json_encode($p ? ['ok' => true, 'data' => $p] : ['ok' => false, 'message' => 'Not found.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Account sync receive (POST account_sync=1) ───────────────────────────────
// A peer sends the full account JSON after a confirmed upload so this node can
// keep accounts/ in sync.  We only overwrite existing files — never create new ones.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_sync'] ?? '') === '1') {
    $publicKey   = trim($_POST['public_key']   ?? '');
    $accountJson = trim($_POST['account_json'] ?? '');

    if ($publicKey === '' || $accountJson === '') jsonOut(['ok' => false, 'error' => 'Missing fields.'], 400);

    $decoded = json_decode($accountJson, true);
    if (!is_array($decoded)) jsonOut(['ok' => false, 'error' => 'Invalid JSON.'], 400);

    $path = accountPath($publicKey);
    if (!file_exists($path)) jsonOut(['ok' => true, 'message' => 'Account not local — skipped.']);

    if (($decoded['public_key'] ?? '') !== $publicKey) jsonOut(['ok' => false, 'error' => 'Public key mismatch.'], 400);

    $tmp = $path . '.sync.tmp';
    if (file_put_contents($tmp, $accountJson, LOCK_EX) === false) jsonOut(['ok' => false, 'error' => 'Write failed.'], 500);
    if (json_decode(file_get_contents($tmp), true) === null) { @unlink($tmp); jsonOut(['ok' => false, 'error' => 'Round-trip verify failed.'], 500); }
    if (PHP_OS_FAMILY === 'Windows' && file_exists($path)) unlink($path);
    if (!rename($tmp, $path)) jsonOut(['ok' => false, 'error' => 'Rename failed.'], 500);

    jsonOut(['ok' => true, 'message' => 'Account updated.']);
}

// ── Peer-push receive (multipart, peer_push=1) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'], $_POST['peer_push']) && $_POST['peer_push'] === '1') {
    $upload = $_FILES['file'];
    if ($upload['error'] !== UPLOAD_ERR_OK)      jsonOut(['ok' => false, 'error' => 'Upload error ' . $upload['error']], 400);
    if (blockedExt(safeExt($upload['name'])))    jsonOut(['ok' => false, 'error' => 'PHP files are not accepted.'], 400);
    $desc    = isset($_POST['description']) ? substr(trim($_POST['description']), 0, MAX_COMMENT) : '';
    $pubKey  = isset($_POST['public_key'])  ? trim($_POST['public_key'])  : '';
    $nodeInf = isset($_POST['node_info'])   ? trim($_POST['node_info'])   : '';
    $result  = storeLocally($upload['tmp_name'], $upload['name'], $desc, $pubKey, $nodeInf);
    if ($result['ok'] && !$result['existed']) appendDataJson($result['hash']);
    // Write / refresh the uploader's account JSON on this peer if embedded in the push
    if ($result['ok'] && $pubKey !== '' && isset($_POST['account_json'])) {
        $aj      = trim($_POST['account_json']);
        $decoded = json_decode($aj, true);
        if (is_array($decoded) && ($decoded['public_key'] ?? '') === $pubKey) {
            $path = accountPath($pubKey);
            $tmp  = $path . '.peer.tmp';
            if (@file_put_contents($tmp, $aj, LOCK_EX) !== false && json_decode(@file_get_contents($tmp), true) !== null) {
                if (PHP_OS_FAMILY === 'Windows' && file_exists($path)) @unlink($path);
                @rename($tmp, $path);
            } else {
                @unlink($tmp);
            }
        }
    }
    jsonOut($result, $result['ok'] ? 200 : 500);
}

// ── Authenticated upload (multipart, no peer_push) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && !isset($_POST['peer_push'])) {
    $publicKey = trim($_POST['public_key']    ?? '');
    $nonce     = trim($_POST['session_nonce'] ?? '');

    if ($publicKey === '' || !hash_equals(expectedNonce($publicKey), $nonce)) {
        jsonOut(['ok' => false, 'error' => 'Not authenticated. Please log in first.'], 401);
    }

    $store   = new UserStore();
    $profile = $store->loadByKey($publicKey);
    if ($profile === null) jsonOut(['ok' => false, 'error' => 'Account not found.'], 403);

    $upload = $_FILES['file'];
    if ($upload['error'] !== UPLOAD_ERR_OK)   jsonOut(['ok' => false, 'error' => 'Upload error ' . $upload['error']], 400);
    if (blockedExt(safeExt($upload['name']))) jsonOut(['ok' => false, 'error' => 'PHP files are not accepted.'], 400);

    $desc    = isset($_POST['comment']) ? substr(trim($_POST['comment']), 0, MAX_COMMENT) : '';
    // Always use the logged-in user's public key — never the node's public_key.txt
    $nodeInf = readOptional(NODE_INFO_FILE);

    $local = storeLocally($upload['tmp_name'], $upload['name'], $desc, $publicKey, $nodeInf);
    if (!$local['ok']) jsonOut($local, 500);

    if ($local['existed']) {
        jsonOut(['ok' => true, 'hash' => $local['hash'], 'filename' => $local['filename'], 'existed' => true, 'peers_pushed' => 0, 'peers_verified' => 0, 'verified_servers' => [], 'account_updated' => false]);
    }

    appendDataJson($local['hash']);

    $storedPath  = FILES_DIR . '/' . $local['filename'];
    $peerResults = [];

    // Collect push targets: servers.txt peers + the user's own declared server
    $pushTargets = loadServers();
    $userSrv     = rtrim(trim($profile['user_server'] ?? ''), '/');
    if ($userSrv !== '') {
        $userSrvBase = preg_replace('#/' . preg_quote(basename(__FILE__), '#') . '$#i', '', $userSrv);
        $alreadyListed = false;
        foreach ($pushTargets as $t) {
            if (serverUrlKey($t) === serverUrlKey($userSrvBase)) { $alreadyListed = true; break; }
        }
        if (!$alreadyListed) $pushTargets[] = $userSrvBase;
    }

    // Pre-encode the current account JSON to embed in the push so peers can
    // write it immediately without a separate account_sync round-trip.
    $pushAccountJson = '';
    $preProfile = $store->loadByKey($publicKey);
    if ($preProfile !== null) {
        $pushAccountJson = (string)json_encode($preProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    foreach ($pushTargets as $srv) {
        $peerResults[] = pushToServer($srv, $storedPath, $upload['name'], $desc, $publicKey, $nodeInf, $pushAccountJson);
    }

    // For each peer: if push succeeded (peer returned ok+hash), consider that
    // primary proof of storage. verifyOnServer then does a secondary HTTP check.
    // If the secondary check fails (firewall, wrong path, etc.) we still count
    // the server as confirmed when the push response itself carried the correct hash.
    $verifiedServers = [];
    $serverDates     = [];
    $verDets         = [];
    foreach ($peerResults as $pr) {
        if (!empty($pr['ok'])) {
            // Push-response integrity check: peer echoed back the same hash
            $pushConfirmed = isset($pr['hash']) && $pr['hash'] === $local['hash'];

            $vr = verifyOnServer($pr['server'], $local['hash'], $local['filename']);
            $vr['push_confirmed'] = $pushConfirmed;
            $verDets[] = $vr;

            // Accept server if EITHER the HTTP verify passed OR the push itself
            // returned the correct hash (covers firewalled / non-public peers)
            if ($vr['ok'] || $pushConfirmed) {
                $srv = $pr['server'];
                if (!in_array($srv, $verifiedServers, true)) {
                    $verifiedServers[] = $srv;
                    $serverDates[$srv] = nowIso();
                }
            }
        }
    }

    $now = nowIso();
    // 'servers' = unique list of confirmed server URLs
    // 'dates'   = { server_url: verification_datetime } for integrity auditing
    $fileRecord = [
        'hash'              => $local['hash'],
        'original_filename' => $upload['name'],
        'extension'         => safeExt($upload['name']),
        'size'              => (int)filesize($storedPath),
        'description'       => $desc,
        'servers'           => $verifiedServers,
        'dates'             => $serverDates,
        'uploaded_at'       => $now,
        'last_verified'     => $now,
    ];
    $addResult = $store->addFileRecord($publicKey, $fileRecord);
    foreach ($verifiedServers as $srv) $store->reportServer($publicKey, $srv);

    // Broadcast the fully-updated account JSON to every confirmed peer.
    // Re-load after all writes so the JSON reflects the latest balance/servers.
    $updatedAccount = $store->loadByKey($publicKey);
    if ($updatedAccount !== null) {
        $syncJson = (string)json_encode($updatedAccount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($verifiedServers as $srv) {
            pushAccountToServer($srv, $syncJson, $publicKey);
        }
    }

    jsonOut([
        'ok'               => true,
        'hash'             => $local['hash'],
        'filename'         => $local['filename'],
        'existed'          => false,
        'peers_pushed'     => count($peerResults),
        'peers_verified'   => count($verifiedServers),
        'verified_servers' => $verifiedServers,
        'push_details'     => $peerResults,
        'verify_details'   => $verDets,
        'account_updated'  => $addResult['ok'],
        'account_message'  => $addResult['message'],
    ]);
}

// ── Node status ────────────────────────────────────────────────────────────────
if (isset($_GET['node_status'])) {
    jsonOut(['public_key' => readOptional(NODE_PUB_KEY) !== '', 'node_info' => readOptional(NODE_INFO_FILE) !== '', 'peers' => count(loadServers())]);
}

// ── Debug: verify file presence on a remote server without uploading ──────────
// Usage: ?debug_verify&server=https://example.com&hash=<sha256>&filename=<hash.ext>
//
// Access control: localhost-only by default.
// Set the env var DEBUG_VERIFY_TOKEN (or define it below) to allow remote access
// by passing ?token=<value> in the URL.
if (isset($_GET['debug_verify'])) {
    $debugToken = defined('DEBUG_VERIFY_TOKEN') ? DEBUG_VERIFY_TOKEN : (getenv('DEBUG_VERIFY_TOKEN') ?: '');
    $callerIp   = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocal    = in_array($callerIp, ['127.0.0.1', '::1', ''], true);
    $tokenOk    = ($debugToken !== '' && ($_GET['token'] ?? '') === $debugToken);

    if (!$isLocal && !$tokenOk) {
        jsonOut(['ok' => false, 'error' => 'debug_verify is restricted to localhost. Set DEBUG_VERIFY_TOKEN and pass ?token= to use remotely.'], 403);
    }

    $server   = trim($_GET['server']   ?? '');
    $hash     = trim($_GET['hash']     ?? '');
    $filename = trim($_GET['filename'] ?? '');

    if ($server === '') {
        jsonOut([
            'ok'      => true,
            'usage'   => '?debug_verify&server=https://…&hash=<sha256>&filename=<hash.ext>[&token=…]',
            'servers' => loadServers(),
            'note'    => 'Supply server+hash+filename for a live check.',
        ]);
    }

    if ($hash === '' || $filename === '') {
        jsonOut(['ok' => false, 'error' => 'Both hash and filename are required.'], 400);
    }

    $filename = basename($filename);
    if (!preg_match('/^[a-f0-9]{64}(\.[a-z0-9]{1,10})?$/', $filename)) {
        jsonOut(['ok' => false, 'error' => 'filename must be a sha256 hex string with an optional safe extension.'], 400);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        jsonOut(['ok' => false, 'error' => 'hash must be a 64-char lowercase hex string.'], 400);
    }

    if (!preg_match('#^https?://#i', $server)) $server = 'http://' . $server;
    $server = rtrim($server, '/');

    $t0     = microtime(true);
    $result = verifyOnServer($server, $hash, $filename);
    $ms     = round((microtime(true) - $t0) * 1000);

    jsonOut(array_merge($result, [
        'elapsed_ms'     => $ms,
        'checked_urls'   => [
            'file_url' => $server . '/files/' . rawurlencode($filename),
            'info_url' => $server . '/info/'  . rawurlencode($hash . '.json'),
        ],
        'curl_available' => function_exists('curl_init'),
    ]));
}

// ══════════════════════════════════════════════════════════════════════════════
//  HTML — style follows index_test.php design language
// ══════════════════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mesh Node</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #f7f6f3;
    --surface:   #ffffff;
    --surface2:  #f2f1ee;
    --border:    rgba(0,0,0,0.10);
    --border2:   rgba(0,0,0,0.18);
    --text:      #1a1a18;
    --text2:     #6b6b67;
    --text3:     #a3a39e;
    --accent:    #1a1a18;
    --success-bg:#eaf3de;
    --success-bd:#639922;
    --success-tx:#3b6d11;
    --danger-bg: #fcebeb;
    --danger-bd: #e24b4a;
    --danger-tx: #a32d2d;
    --info-bg:   #e6f1fb;
    --info-tx:   #185fa5;
    --warn-bg:   #fdf6e3;
    --warn-bd:   #d4a017;
    --warn-tx:   #7a5a00;
    --radius:    8px;
    --radius-lg: 12px;
    --mono:      'Courier New', Courier, monospace;
    --sans:      -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  }

  @media (prefers-color-scheme: dark) {
    :root {
      --bg:        #1c1c1a;
      --surface:   #252523;
      --surface2:  #2e2e2b;
      --border:    rgba(255,255,255,0.10);
      --border2:   rgba(255,255,255,0.18);
      --text:      #edede8;
      --text2:     #9a9a94;
      --text3:     #606060;
      --accent:    #edede8;
      --success-bg:#173404;
      --success-bd:#3b6d11;
      --success-tx:#c0dd97;
      --danger-bg: #501313;
      --danger-bd: #a32d2d;
      --danger-tx: #f09595;
      --info-bg:   #042c53;
      --info-tx:   #85b7eb;
      --warn-bg:   #3a2e00;
      --warn-bd:   #7a5a00;
      --warn-tx:   #f0d080;
    }
  }

  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2.5rem 1rem 3rem;
  }

  .card { width: 100%; max-width: 480px; }

  /* ── Header ── */
  .header { text-align: center; margin-bottom: 1.75rem; }
  .header-logo { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 5px; }
  .header-logo svg { width: 22px; height: 22px; color: var(--text); }
  .header-title { font-family: var(--mono); font-size: 18px; font-weight: 600; letter-spacing: -0.02em; color: var(--text); }
  .header-sub { font-size: 13px; color: var(--text2); }

  /* ── Node status bar ── */
  .node-bar { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.75rem; font-size: .78rem; color: var(--text2); }
  .nb-pill { display: flex; align-items: center; gap: .35rem; padding: .2rem .65rem; background: var(--surface); border: 0.5px solid var(--border); border-radius: 20px; }
  .nb-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text3); flex-shrink: 0; }
  .nb-dot.ok { background: var(--success-bd); }
  .nb-dot.blue { background: var(--info-tx); }

  /* ── Panel ── */
  .panel {
    background: var(--surface);
    border: 0.5px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 1rem;
  }

  /* ── Tabs ── */
  .tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; background: var(--surface2); padding: 4px; border-radius: var(--radius); margin-bottom: 1.5rem; }
  .tab-btn { background: transparent; border: none; border-radius: calc(var(--radius) - 1px); padding: 7px 0; font-size: 13px; font-family: var(--sans); color: var(--text2); cursor: pointer; font-weight: 400; transition: background .12s, color .12s; }
  .tab-btn.active { background: var(--surface); border: 0.5px solid var(--border); color: var(--text); font-weight: 500; }

  /* ── Panel body ── */
  .panel-body { padding: 1.5rem; }

  /* ── Fields ── */
  .field { margin-bottom: 1.2rem; }
  .field label { display: block; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--text2); margin-bottom: 6px; font-weight: 500; }
  .field-wrap { position: relative; }
  .field-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text3); pointer-events: none; display: flex; align-items: center; }
  .field-icon svg { width: 16px; height: 16px; }
  .field input, .field textarea {
    width: 100%; background: var(--surface); border: 0.5px solid var(--border);
    border-radius: var(--radius); padding: 9px 12px 9px 36px;
    font-family: var(--mono); font-size: 13px; color: var(--text);
    outline: none; transition: border-color .15s; -webkit-appearance: none;
  }
  .field textarea { padding: 9px 12px; font-family: var(--sans); resize: vertical; min-height: 72px; }
  .field input:focus, .field textarea:focus { border-color: var(--border2); box-shadow: 0 0 0 3px rgba(0,0,0,.06); }
  .field input::placeholder, .field textarea::placeholder { color: var(--text3); }
  .field-hint { margin-top: 4px; font-size: 11px; color: var(--text3); }
  .has-eye input { padding-right: 38px; }
  .eye-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px; color: var(--text3); display: flex; align-items: center; }
  .eye-btn svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }

  /* ── Buttons ── */
  .btn {
    width: 100%; display: flex; align-items: center; justify-content: center;
    gap: 7px; padding: 9px 16px;
    border: 0.5px solid var(--border2); border-radius: var(--radius);
    background: transparent; font-family: var(--sans); font-size: 13px; font-weight: 500;
    color: var(--text); cursor: pointer; transition: background .12s, transform .1s;
  }
  .btn svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
  .btn:hover { background: var(--surface2); }
  .btn:active { transform: scale(.98); }
  .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

  /* ── Banner ── */
  .banner { display: flex; align-items: flex-start; gap: 8px; padding: 10px 14px; border-radius: var(--radius); font-size: 13px; margin-bottom: 1.25rem; animation: fadeIn .2s ease; }
  .banner svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 1px; }
  .banner.ok   { background: var(--success-bg); border: 0.5px solid var(--success-bd); color: var(--success-tx); }
  .banner.err  { background: var(--danger-bg);  border: 0.5px solid var(--danger-bd);  color: var(--danger-tx); }
  .banner.warn { background: var(--warn-bg);    border: 0.5px solid var(--warn-bd);    color: var(--warn-tx); }

  /* ── Profile ── */
  .profile-head { text-align: center; margin-bottom: 1.5rem; animation: slideUp .3s ease; }
  .avatar { width: 52px; height: 52px; border-radius: 50%; background: var(--info-bg); color: var(--info-tx); font-size: 19px; font-weight: 600; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; letter-spacing: .03em; }
  .profile-name { font-size: 17px; font-weight: 500; margin-bottom: 2px; }
  .profile-mail { font-size: 13px; color: var(--text2); }

  /* ── Stats grid ── */
  .stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 1rem; }
  .stat { background: var(--surface2); border-radius: var(--radius); padding: 10px 12px; }
  .stat-label { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing: .06em; display: flex; align-items: center; gap: 4px; margin-bottom: 2px; }
  .stat-label svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .stat-val { font-size: 22px; font-weight: 500; font-family: var(--mono); }

  /* ── Info boxes ── */
  .infobox { background: var(--surface2); border-radius: var(--radius); padding: 12px 16px; margin-bottom: 1rem; font-size: 13px; }
  .infobox-label { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; font-weight: 500; }
  .infobox-val { font-family: var(--mono); font-size: 12px; word-break: break-all; line-height: 1.6; color: var(--text); }
  .about-quote { font-style: italic; color: var(--text2); margin-bottom: 0; }

  /* ── Drop zone ── */
  .drop-zone { border: 2px dashed var(--border2); border-radius: var(--radius); padding: 2rem 1.5rem; text-align: center; cursor: pointer; transition: border-color .2s, background .2s; margin-bottom: 1rem; }
  .drop-zone:hover, .drop-zone:focus { background: var(--surface2); border-color: var(--accent); }
  .drop-zone.over { border-color: var(--accent); background: var(--surface2); }
  .dz-icon { font-size: 1.75rem; margin-bottom: .65rem; }
  .dz-title { font-size: .95rem; font-weight: 600; margin-bottom: .3rem; }
  .dz-sub { font-size: .8rem; color: var(--text2); font-family: var(--mono); }
  .dz-sub b { color: var(--accent); }

  /* ── File panel ── */
  .file-panel { background: var(--surface); border: 0.5px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 1rem; display: none; }
  .fp-top { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-bottom: 0.5px solid var(--border); }
  .fp-icon { width: 2rem; height: 2rem; background: var(--surface2); border-radius: var(--radius); display: grid; place-items: center; flex-shrink: 0; font-size: 1rem; }
  .fp-name { font-family: var(--mono); font-size: .82rem; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fp-size { font-size: .78rem; color: var(--text2); flex-shrink: 0; font-family: var(--mono); }
  .x-btn { background: none; border: none; cursor: pointer; font-size: 1.1rem; color: var(--text3); padding: 0 .2rem; transition: color .15s; }
  .x-btn:hover { color: var(--danger-tx); }

  .fp-mid { padding: 1rem 1.1rem; }
  .fp-mid label { display: block; font-size: 11px; letter-spacing: .07em; text-transform: uppercase; color: var(--text2); margin-bottom: 6px; font-weight: 500; }
  textarea#comment-box { width: 100%; min-height: 4rem; padding: .6rem .85rem; background: var(--surface2); border: 0.5px solid var(--border); border-radius: var(--radius); font-family: var(--sans); font-size: .88rem; color: var(--text); resize: vertical; outline: none; transition: border-color .2s; }
  textarea#comment-box:focus { border-color: var(--border2); }
  .cmeta { display: flex; justify-content: space-between; margin-top: .35rem; font-size: 11px; color: var(--text3); font-family: var(--mono); }
  .ccount.over { color: var(--danger-tx); }

  .fp-bot { padding: .85rem 1.1rem; border-top: 0.5px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: .8rem; }
  .peer-info { font-size: .8rem; color: var(--text2); font-family: var(--mono); }
  .peer-info b { color: var(--text); font-weight: 600; }
  .btn-send { padding: .5rem 1.2rem; background: var(--accent); color: var(--surface); border: none; border-radius: var(--radius); font-weight: 600; font-size: .85rem; font-family: var(--sans); cursor: pointer; transition: opacity .2s; display: flex; align-items: center; gap: .4rem; }
  .btn-send:hover { opacity: .82; }
  .btn-send.busy { opacity: .45; pointer-events: none; }
  .btn-send svg { width: 14px; height: 14px; stroke: var(--surface); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

  /* ── Progress ── */
  .prog-wrap { height: 2px; background: var(--surface2); border-radius: 2px; overflow: hidden; display: none; margin-bottom: 1rem; }
  .prog-bar { height: 100%; width: 0%; background: var(--accent); transition: width .1s linear; }

  /* ── Result / status ── */
  .result-box { display: none; border-radius: var(--radius); padding: .9rem 1.1rem; font-size: 13px; line-height: 1.6; margin-bottom: 1rem; animation: fadeIn .25s ease; }
  .result-box.ok   { background: var(--success-bg); border: 0.5px solid var(--success-bd); color: var(--success-tx); }
  .result-box.err  { background: var(--danger-bg);  border: 0.5px solid var(--danger-bd);  color: var(--danger-tx); }
  .res-title { font-weight: 600; margin-bottom: .5rem; display: flex; align-items: center; gap: .4rem; }
  .res-row { display: flex; gap: .5rem; font-size: 12px; font-family: var(--mono); margin: .2rem 0; }
  .res-label { color: var(--text2); min-width: 7rem; flex-shrink: 0; }
  .file-link { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .9rem; font-size: .8rem; color: var(--success-tx); border: 0.5px solid var(--success-bd); border-radius: var(--radius); text-decoration: none; margin-top: .6rem; transition: background .15s; }
  .file-link:hover { background: var(--success-bg); }
  .srv-badge { display: inline-flex; align-items: center; font-size: 11px; font-family: var(--mono); padding: .15rem .55rem; border-radius: 4px; margin: .2rem .2rem 0 0; }
  .srv-badge.ok  { background: var(--success-bg); border: 0.5px solid var(--success-bd); color: var(--success-tx); }
  .srv-badge.fail{ background: var(--danger-bg);  border: 0.5px solid var(--danger-bd);  color: var(--danger-tx); }

  /* ── Files list ── */
  .section-label { font-size: 11px; font-family: var(--mono); color: var(--text3); text-transform: uppercase; letter-spacing: .1em; margin-bottom: .85rem; display: flex; align-items: center; gap: .5rem; }
  .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .file-row { background: var(--surface); border: 0.5px solid var(--border); border-radius: var(--radius); padding: .7rem 1rem; margin-bottom: .45rem; display: flex; align-items: center; gap: .75rem; font-size: .82rem; transition: border-color .15s; }
  .file-row:hover { border-color: var(--border2); }
  .frow-ext { width: 2rem; height: 2rem; background: var(--surface2); border-radius: var(--radius); display: grid; place-items: center; font-size: .62rem; font-family: var(--mono); color: var(--text2); text-transform: uppercase; flex-shrink: 0; }
  .frow-info { flex: 1; min-width: 0; }
  .frow-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .frow-hash { font-family: var(--mono); font-size: .7rem; color: var(--text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .frow-srv  { font-size: .7rem; color: var(--text3); font-family: var(--mono); margin-top: .15rem; }
  .frow-srv b { color: var(--success-tx); }
  .empty-files { text-align: center; color: var(--text3); font-family: var(--mono); font-size: .8rem; padding: 1.5rem; border: 0.5px dashed var(--border); border-radius: var(--radius); }

  /* ── Footer ── */
  .foot { text-align: center; margin-top: 1rem; font-size: 11px; color: var(--text3); }

  /* ── Spinner ── */
  @keyframes spin { to { transform: rotate(360deg); } }
  .spin { animation: spin .75s linear infinite; display: inline-flex; }
  @keyframes fadeIn  { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
  @keyframes slideUp { from { opacity: 0; transform: translateY(8px);  } to { opacity: 1; transform: none; } }
</style>
</head>
<body>
<div class="card">

  <!-- Header -->
  <div class="header">
    <div class="header-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="5" r="3"/><circle cx="5" cy="19" r="3"/><circle cx="19" cy="19" r="3"/>
        <line x1="12" y1="8" x2="5" y2="16"/><line x1="12" y1="8" x2="19" y2="16"/>
      </svg>
      <span class="header-title">MeshNode</span>
    </div>
    <p class="header-sub">Authenticated file replication · SHA-256 storage</p>
  </div>

  <!-- Node status bar -->
  <div class="node-bar" id="node-bar">
    <div class="nb-pill"><span class="nb-dot blue"></span>peers <span id="nb-peers" style="margin-left:.2rem;font-weight:500">…</span></div>
    <div class="nb-pill"><span class="nb-dot" id="nb-pk-dot"></span>public_key.txt <span id="nb-pk" style="margin-left:.2rem">…</span></div>
    <div class="nb-pill"><span class="nb-dot" id="nb-ni-dot"></span>node_info.txt <span id="nb-ni" style="margin-left:.2rem">…</span></div>
  </div>

  <!-- Auth section -->
  <div id="auth-section">
    <div class="panel" id="auth-panel"><!-- JS renders --></div>
    <p class="foot">Login uses your public key · passwords stored as sha-512</p>
  </div>

  <!-- Upload + profile section (hidden until logged in) -->
  <div id="upload-section" style="display:none">

    <!-- Profile head -->
    <div class="panel" style="padding:1.5rem;margin-bottom:1rem;animation:slideUp .3s ease">
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
        <div class="avatar" id="av-letters" style="flex-shrink:0">??</div>
        <div style="flex:1;min-width:0">
          <div id="p-username" style="font-size:16px;font-weight:600"></div>
          <div id="p-mail" style="font-size:13px;color:var(--text2)"></div>
        </div>
        <button class="btn" id="logout-btn" style="width:auto;padding:.4rem .9rem;font-size:12px">
          <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign out
        </button>
      </div>
      <div class="stats">
        <div class="stat">
          <div class="stat-label">
            <svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/></svg>
            Balance
          </div>
          <div class="stat-val" id="p-balance">0</div>
        </div>
        <div class="stat">
          <div class="stat-label">
            <svg viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><polyline points="14 2 14 8 20 8"/></svg>
            Files
          </div>
          <div class="stat-val" id="p-files">0</div>
        </div>
        <div class="stat">
          <div class="stat-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            Servers
          </div>
          <div class="stat-val" id="p-servers">0</div>
        </div>
      </div>
      <div id="p-about-box" style="display:none" class="infobox" style="margin-top:1rem;margin-bottom:0">
        <div class="infobox-label">About</div>
        <div class="infobox-val about-quote" id="p-about"></div>
      </div>
    </div>

    <!-- Drop zone -->
    <div class="drop-zone" id="drop-zone" tabindex="0" role="button" aria-label="Select or drop a file">
      <div class="dz-icon">☁️</div>
      <p class="dz-title">Drop a file or click to select</p>
      <p class="dz-sub">Stored as <b>sha256.ext</b> · pushed to peers · verified · <b>.php blocked</b></p>
    </div>
    <input type="file" id="file-input">

    <!-- File panel -->
    <div class="file-panel" id="file-panel">
      <div class="fp-top">
        <div class="fp-icon">📄</div>
        <span class="fp-name" id="fp-name">—</span>
        <span class="fp-size" id="fp-size"></span>
        <button class="x-btn" id="x-btn">✕</button>
      </div>
      <div class="fp-mid">
        <label for="comment-box">Description <span style="color:var(--text3);font-weight:400">(optional · max 1 KB)</span></label>
        <textarea id="comment-box" placeholder="Add an optional description for this file…"></textarea>
        <div class="cmeta">
          <span>Saved to your file record</span>
          <span class="ccount" id="ccount">0 / 1 000</span>
        </div>
      </div>
      <div class="fp-bot">
        <span class="peer-info">→ <b id="peer-count">…</b> peer(s)</span>
        <button class="btn-send" id="send-btn">
          <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          <span>Send &amp; Verify</span>
        </button>
      </div>
    </div>

    <div class="prog-wrap" id="prog-wrap"><div class="prog-bar" id="prog-bar"></div></div>
    <div class="result-box" id="result-box"></div>

    <!-- Files list -->
    <div id="files-section" style="margin-top:1.75rem">
      <p class="section-label">Your files</p>
      <div id="files-container"><p class="empty-files">No files uploaded yet.</p></div>
    </div>

  </div><!-- #upload-section -->

  <p class="foot" id="foot-note">Mesh Node · PHP <?php echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION; ?></p>

</div><!-- .card -->

<script>
(function(){
'use strict';

/* ── SVG icons ── */
const ICO = {
  user:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.58-7 8-7s8 3 8 7"/></svg>`,
  lock:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>`,
  key:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M15 9l-4.24 4.24M19 5l-2 2-2-2 2-2 2 2zM21 3l-2 2"/></svg>`,
  mail:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>`,
  server:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>`,
  about:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`,
  eye:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
  eyeOff:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`,
  arrow:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>`,
  userPlus:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>`,
  check:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
  alert:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
  loader:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>`,
};
const ico = k => ICO[k] || '';

/* ── Helpers ── */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmt(b){ if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(2)+' MB'; }
function extOf(n){ return n.split('.').pop().toLowerCase(); }
const BLOCKED = ['php','php3','php4','php5','php7','phtml','phar'];

/* ── Session nonce (mirrors server: sha256(pubkey_filename + YYYY-MM-DD)) ── */
function sanitizePubkey(pk){
  return pk.trim().replace(/[^A-Za-z0-9+\/=\-_]/g,'_').replace(/_+/g,'_').replace(/^_+|_+$/g,'').slice(0,200);
}
async function deriveNonce(pk){
  const date = new Date().toISOString().slice(0,10); // YYYY-MM-DD
  const raw  = sanitizePubkey(pk) + date;
  const buf  = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
}

/* ── API ── */
async function api(action, payload){
  const r = await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,...payload})});
  return r.json();
}

/* ── State ── */
const S = {
  tab: 'login',
  user: null,      // profile object (no password)
  nonce: null,
  status: null,
  loading: false,
  showPass: false,
  f: { publicKey:'', password:'', username:'', mail:'', aboutMe:'', userServer:'' },
};

/* ════════════════════════════════════
   Auth panel rendering
════════════════════════════════════ */
function renderAuth(){
  const ap = document.getElementById('auth-panel');
  ap.innerHTML = authHTML();
  bindAuth();
}

function bannerHTML(){
  if(!S.status) return '';
  const t = S.status.ok?'ok':'err';
  const i = S.status.ok?ico('check'):ico('alert');
  return `<div class="banner ${t}">${i}<span>${esc(S.status.message)}</span></div>`;
}

function fieldHTML(label, key, type, placeholder, hint='', textarea=false){
  const v = S.f[key]||'';
  const isP = type==='password';
  const rT  = isP&&S.showPass?'text':type;
  const eye  = isP?`<button class="eye-btn" id="eyeBtn" type="button">${S.showPass?ico('eyeOff'):ico('eye')}</button>`:'';
  const ik   = {publicKey:'key',password:'lock',username:'user',mail:'mail',aboutMe:'about',userServer:'server'}[key]||'about';
  if(textarea) return `<div class="field"><label for="f_${key}">${label}</label><div class="field-wrap"><textarea id="f_${key}" maxlength="512" placeholder="${placeholder}" data-field="${key}" rows="3">${esc(v)}</textarea></div>${hint?`<p class="field-hint">${hint}</p>`:''}</div>`;
  return `<div class="field"><label for="f_${key}">${label}</label><div class="field-wrap${isP?' has-eye':''}"><span class="field-icon">${ico(ik)}</span><input id="f_${key}" type="${rT}" value="${esc(v)}" maxlength="512" placeholder="${placeholder}" data-field="${key}" autocomplete="off">${eye}</div>${hint?`<p class="field-hint">${hint}</p>`:''}</div>`;
}

function btnHTML(id, ik, label){
  if(S.loading) return `<button class="btn" disabled><span class="spin">${ico('loader')}</span>${label}…</button>`;
  return `<button class="btn" id="${id}">${ico(ik)}<span>${label}</span></button>`;
}

function authHTML(){
  const body = S.tab==='login'
    ? `${bannerHTML()}
       ${fieldHTML('Public key','publicKey','text','ssh-ed25519 AAAA… / your-public-key','Your account public key')}
       ${fieldHTML('Password','password','password','••••••••')}
       ${btnHTML('doLogin','arrow','Sign in')}`
    : `${bannerHTML()}
       ${fieldHTML('Username','username','text','satoshi_n')}
       ${fieldHTML('Password','password','password','Min. 8 characters')}
       ${fieldHTML('Public key','publicKey','text','ssh-ed25519 AAAA…','Used as your account identifier across nodes')}
       ${fieldHTML('E-mail','mail','email','you@example.com')}
       ${fieldHTML('User server','userServer','text','https://mynode.example.com','Optional — your own mesh node address')}
       ${fieldHTML('About me','aboutMe','text','Tell the network who you are…','',true)}
       ${btnHTML('doRegister','userPlus','Create account')}`;

  return `<div class="panel-body">
    <div class="tabs">
      <button class="tab-btn${S.tab==='login'?' active':''}" data-tab="login">Sign in</button>
      <button class="tab-btn${S.tab==='register'?' active':''}" data-tab="register">Register</button>
    </div>
    ${body}
  </div>`;
}

function bindAuth(){
  document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{ S.tab=b.dataset.tab; S.status=null; renderAuth(); }));
  document.querySelectorAll('[data-field]').forEach(el=>el.addEventListener('input',e=>{ S.f[e.target.dataset.field]=e.target.value; }));
  const eye=document.getElementById('eyeBtn');
  if(eye) eye.addEventListener('click',()=>{ S.showPass=!S.showPass; renderAuth(); });

  const loginBtn=document.getElementById('doLogin');
  if(loginBtn) loginBtn.addEventListener('click',async()=>{
    S.status=null; S.loading=true; renderAuth();
    const res=await api('login',{publicKey:S.f.publicKey,password:S.f.password});
    S.loading=false;
    if(res.ok){
      S.user=res.data;
      S.nonce=await deriveNonce(res.data.public_key);
      S.status=null;
      showUpload();
    } else { S.status=res; renderAuth(); }
  });

  const regBtn=document.getElementById('doRegister');
  if(regBtn) regBtn.addEventListener('click',async()=>{
    S.status=null; S.loading=true; renderAuth();
    const res=await api('register',{username:S.f.username,password:S.f.password,publicKey:S.f.publicKey,mail:S.f.mail,aboutMe:S.f.aboutMe,userServer:S.f.userServer});
    S.loading=false;
    if(res.ok){
      const login=await api('login',{publicKey:S.f.publicKey,password:S.f.password});
      if(login.ok){ S.user=login.data; S.nonce=await deriveNonce(login.data.public_key); S.status=null; showUpload(); }
      else { S.status={ok:true,message:'Registered! Please sign in.'}; S.tab='login'; renderAuth(); }
    } else { S.status=res; renderAuth(); }
  });
}

/* ════════════════════════════════════
   Profile + upload section
════════════════════════════════════ */
function showUpload(){
  document.getElementById('auth-section').style.display='none';
  document.getElementById('upload-section').style.display='block';
  document.getElementById('foot-note').style.display='none';
  updateProfile();
  renderFiles();
}

function updateProfile(){
  const d=S.user; if(!d) return;
  document.getElementById('av-letters').textContent=(d.username||d.public_key||'?').slice(0,2).toUpperCase();
  document.getElementById('p-username').textContent=d.username||'(no username)';
  document.getElementById('p-mail').textContent=d.mail||'';
  document.getElementById('p-balance').textContent=d.balance??0;
  document.getElementById('p-files').textContent=(d.files||[]).length;
  document.getElementById('p-servers').textContent=Object.keys(d.servers||{}).length;
  const ab=document.getElementById('p-about-box');
  if(d.about_me){ ab.style.display=''; document.getElementById('p-about').textContent='"'+d.about_me+'"'; }
  else ab.style.display='none';
}

function renderFiles(){
  const files=(S.user?.files||[]).slice().reverse();
  const c=document.getElementById('files-container');
  if(!files.length){ c.innerHTML='<p class="empty-files">No files uploaded yet.</p>'; return; }
  c.innerHTML=files.map(f=>{
    if(typeof f==='string') return `<div class="file-row"><div class="frow-ext">—</div><div class="frow-info"><div class="frow-name">${esc(f)}</div></div></div>`;
    const ext=esc(f.extension||'—');
    const sc=(f.servers||[]).length;
    const fn=f.hash+(f.extension?'.'+f.extension:'');
    return `<div class="file-row">
      <div class="frow-ext">${ext}</div>
      <div class="frow-info">
        <div class="frow-name">${esc(f.original_filename||f.hash||'unknown')}</div>
        <div class="frow-hash">${esc(f.hash||'')}</div>
        <div class="frow-srv">verified on <b>${sc}</b> server(s) · ${esc((f.uploaded_at||'').slice(0,10))}</div>
      </div>
      <a href="files/${esc(fn)}" target="_blank" rel="noopener" style="font-size:.75rem;font-family:var(--mono);color:var(--text2);text-decoration:none;flex-shrink:0" title="Open">↗</a>
    </div>`;
  }).join('');
}

async function refreshProfile(){
  const r=await api('get_profile',{public_key:S.user?.public_key||''});
  if(r.ok&&r.data){ S.user=r.data; updateProfile(); renderFiles(); }
}

/* ── Logout ── */
document.getElementById('logout-btn').addEventListener('click',()=>{
  S.user=null; S.nonce=null; S.status=null; S.tab='login';
  S.f={publicKey:'',password:'',username:'',mail:'',aboutMe:'',userServer:''};
  document.getElementById('upload-section').style.display='none';
  document.getElementById('auth-section').style.display='';
  document.getElementById('foot-note').style.display='';
  clearUploadUI();
  renderAuth();
});

/* ════════════════════════════════════
   Upload logic
════════════════════════════════════ */
const dz       = document.getElementById('drop-zone');
const fi       = document.getElementById('file-input');
const filePanel= document.getElementById('file-panel');
const fpName   = document.getElementById('fp-name');
const fpSize   = document.getElementById('fp-size');
const xBtn     = document.getElementById('x-btn');
const commentB = document.getElementById('comment-box');
const ccount   = document.getElementById('ccount');
const sendBtn  = document.getElementById('send-btn');
const peerCnt  = document.getElementById('peer-count');
const resultBox= document.getElementById('result-box');
const progWrap = document.getElementById('prog-wrap');
const progBar  = document.getElementById('prog-bar');
const MAX_C    = 1000;
let curFile    = null;

function setFile(f){
  if(!f) return;
  if(BLOCKED.includes(extOf(f.name))){ showResult('err','PHP files are not accepted.'); return; }
  curFile=f; fpName.textContent=f.name; fpSize.textContent=fmt(f.size);
  filePanel.style.display='block'; commentB.value=''; updateCount();
  resultBox.style.display='none';
}

function clearUploadUI(){
  curFile=null; fi.value='';
  filePanel.style.display='none'; commentB.value='';
  resultBox.style.display='none'; progWrap.style.display='none';
}

dz.addEventListener('click',()=>fi.click());
dz.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' ')fi.click(); });
fi.addEventListener('change',()=>{ if(fi.files[0]) setFile(fi.files[0]); });
xBtn.addEventListener('click',clearUploadUI);
['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{ e.preventDefault(); dz.classList.add('over'); }));
['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{ e.preventDefault(); dz.classList.remove('over'); }));
dz.addEventListener('drop',e=>{ if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });

function updateCount(){
  const len=new TextEncoder().encode(commentB.value).length;
  ccount.textContent=len.toLocaleString()+' / '+MAX_C.toLocaleString();
  ccount.classList.toggle('over',len>MAX_C);
}
commentB.addEventListener('input',updateCount);

function showResult(type,html){ resultBox.className='result-box '+type; resultBox.innerHTML=html; resultBox.style.display='block'; }

sendBtn.addEventListener('click',async()=>{
  if(!curFile||!S.user||!S.nonce) return;
  if(new TextEncoder().encode(commentB.value).length>MAX_C){ showResult('err','Description exceeds the 1 KB limit.'); return; }

  const fd=new FormData();
  fd.append('file',curFile);
  fd.append('comment',commentB.value);
  fd.append('public_key',S.user.public_key);
  fd.append('session_nonce',S.nonce);

  const xhr=new XMLHttpRequest();
  sendBtn.classList.add('busy');
  sendBtn.querySelector('span').textContent='Sending…';
  progWrap.style.display='block'; progBar.style.width='0%';
  resultBox.style.display='none';

  xhr.upload.addEventListener('progress',e=>{ if(e.lengthComputable) progBar.style.width=Math.round(e.loaded/e.total*100)+'%'; });

  xhr.addEventListener('load',()=>{
    sendBtn.classList.remove('busy');
    sendBtn.querySelector('span').textContent='Send & Verify';
    progBar.style.width='100%';
    setTimeout(()=>{ progWrap.style.display='none'; progBar.style.width='0%'; },700);

    let res;
    try{ res=JSON.parse(xhr.responseText); } catch(e){ showResult('err','Unexpected server response.'); return; }
    if(!res.ok){ showResult('err',`${ico('alert')} <b>Error:</b> ${esc(res.error||'Unknown error')}`); return; }

    refreshProfile();

    const pushed  = res.peers_pushed||0;
    const verified= res.peers_verified||0;
    const srvs    = res.verified_servers||[];
    const existed = res.existed?` <span style="opacity:.55">(already stored)</span>`:'';
    let badges = srvs.map(s=>`<span class="srv-badge ok">✓ ${esc(s)}</span>`).join('');
    if(!badges&&pushed>0) badges='<span class="srv-badge fail">✗ no servers confirmed</span>';
    const furl='files/'+esc(res.filename||'');
    const acct = res.account_updated?`<div class="res-row"><span class="res-label">account</span><span>✓ updated</span></div>`:'';

    // Debug panel
    const pushRows=(res.push_details||[]).map(p=>{
      const ok=p.ok?'✓':'✗';
      const info=p.error?esc(p.error):(p.hash?'hash:'+esc(p.hash.slice(0,12))+'...':'no hash');
      const st=p.http_status?' HTTP '+p.http_status:'';
      return `<div style="font-size:10px;font-family:var(--mono);opacity:.75">${ok} push → ${esc(p.server||'')}${st} — ${info}</div>`;
    }).join('');
    const vrRows=(res.verify_details||[]).map(v=>{
      const ok=v.ok?'✓':'✗';
      const pc=v.push_confirmed?' (push✓)':'';
      return `<div style="font-size:10px;font-family:var(--mono);opacity:.75">${ok} verify[${esc(v.method||'?')}] HTTP ${v.status||0}${pc}${v.error?' — '+esc(v.error):''}</div>`;
    }).join('');
    const dbg=(pushRows||vrRows)?`<details style="margin-top:.4rem"><summary style="font-size:10px;cursor:pointer;opacity:.6">debug details</summary><div style="margin-top:.2rem">${pushRows}${vrRows}</div></details>`:'';

    showResult('ok',`
      <div class="res-title">${ico('check')} File stored${existed}</div>
      <div class="res-row"><span class="res-label">hash</span><span style="font-family:var(--mono);font-size:11px;word-break:break-all">${esc(res.hash||'')}</span></div>
      <div class="res-row"><span class="res-label">peers pushed</span><span>${pushed}</span></div>
      <div class="res-row"><span class="res-label">verified</span><span>${verified} / ${pushed}</span></div>
      ${acct}
      <div style="margin-top:.5rem">${badges}</div>
      ${dbg}
      <div><a class="file-link" href="${furl}" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Open file in new tab
      </a></div>`);

    filePanel.style.display='none'; curFile=null; fi.value='';
  });

  xhr.addEventListener('error',()=>{
    sendBtn.classList.remove('busy');
    sendBtn.querySelector('span').textContent='Send & Verify';
    showResult('err','✗ Network error.'); progWrap.style.display='none';
  });

  xhr.open('POST',location.href);
  xhr.send(fd);
});

/* ── Node status bar ── */
fetch(location.href+'?node_status=1')
  .then(r=>r.json())
  .then(d=>{
    document.getElementById('nb-peers').textContent=d.peers;
    peerCnt.textContent=d.peers;
    const dot=(id,ok)=>{ const el=document.getElementById(id); if(el) el.className='nb-dot '+(ok?'ok':''); };
    dot('nb-pk-dot',d.public_key); dot('nb-ni-dot',d.node_info);
    document.getElementById('nb-pk').textContent=d.public_key?'✓ found':'— missing';
    document.getElementById('nb-ni').textContent=d.node_info?'✓ found':'— missing';
  })
  .catch(()=>{});

/* ── Init ── */
renderAuth();
})();
</script>
</body>
</html>