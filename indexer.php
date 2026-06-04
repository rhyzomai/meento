<?php
/**
 * Distributed File Indexer
 * Compatible with PHP 7+
 * All-in-one file (Database Auto-Creation, Sync Engine, Search, UI)
 */

// ==========================================
// 1. CONFIGURATION
// ==========================================
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'file_indexer';

// ==========================================
// 2. DATABASE AUTO-INITIALIZATION
// ==========================================
try {
    $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    $table_schema = "CREATE TABLE IF NOT EXISTS file_registry (
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
    )";
    $pdo->exec($table_schema);

} catch (PDOException $e) {
    die("<div style='color:red; font-family:sans-serif; padding:20px;'><strong>Database Connection Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ==========================================
// 3. SYNC ENGINE ACTION
// ==========================================
$sync_message = '';
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    if (!file_exists('servers.txt')) {
        $sync_message = "<div class='alert error'>Error: 'servers.txt' is missing from the local indexer directory!</div>";
    } else {
        $servers = file('servers.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $inserted = 0;
        $updated = 0;
        $errors = [];

        foreach ($servers as $server) {
            $server_line = trim($server);
            if (empty($server_line)) continue;

            if (strpos($server_line, 'http://') !== 0 && strpos($server_line, 'https://') !== 0) {
                $server_line = 'http://' . $server_line;
            }

            if (pathinfo($server_line, PATHINFO_EXTENSION) === 'php') {
                $base_url = dirname($server_line);
            } else {
                $base_url = rtrim($server_line, '/');
            }

            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $remote_files_url = $base_url . '/files.txt';
            $files_data = @file_get_contents($remote_files_url, false, $ctx);

            if ($files_data === false) {
                $errors[] = "Could not reach or read files.txt at: " . htmlspecialchars($remote_files_url);
                continue;
            }

            $files = explode("\n", str_replace("\r", "", $files_data));

            foreach ($files as $file_line) {
                $file_line = trim($file_line);
                if (empty($file_line)) continue;

                // FIX: Strip the extension from the files.txt line to get the pure hash
                $file_hash = pathinfo($file_line, PATHINFO_FILENAME); 

                // Target URL becomes: http://localhost/meento/1/info/{hash}.json
                $json_url = $base_url . "/info/" . $file_hash . ".json";
                $json_data = @file_get_contents($json_url, false, $ctx);

                if ($json_data === false) {
                    $errors[] = "Metadata file missing or unreachable: " . htmlspecialchars($json_url);
                    continue; 
                }

                $data = json_decode($json_data, true);

                if ($data && isset($data['original_filename'], $data['size'], $data['extension'])) {
                    
                    $stmt = $pdo->prepare("SELECT id, hosts FROM file_registry WHERE file_hash = ?");
                    $stmt->execute([$file_hash]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $current_hosts = json_decode($existing['hosts'], true);
                        if (!is_array($current_hosts)) {
                            $current_hosts = [];
                        }

                        if (!in_array($base_url, $current_hosts)) {
                            $current_hosts[] = $base_url;
                            $update_stmt = $pdo->prepare("UPDATE file_registry SET hosts = ?, server_filename = ? WHERE file_hash = ?");
                            $update_stmt->execute([json_encode($current_hosts), $file_line, $file_hash]);
                            $updated++;
                        }
                    } else {
                        $initial_hosts = json_encode([$base_url]);
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO file_registry 
                            (file_hash, server_filename, original_filename, file_size, extension, public_key, node_info, description, hosts) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([
                            $file_hash,
                            $file_line, // e.g., hash.jpg
                            $data['original_filename'],
                            (int)$data['size'],
                            $data['extension'],
                            $data['public_key'] ?? '',
                            $data['node_info'] ?? '',
                            $data['description'] ?? '',
                            $initial_hosts
                        ]);
                        $inserted++;
                    }
                }
            }
        }

        $err_summary = !empty($errors) ? "<br><small style='display:block; margin-top:10px; color:#991b1b;'><strong>Logs:</strong><br>" . implode("<br>", $errors) . "</small>" : "";
        $sync_message = "<div class='alert success'>Sync complete! Added $inserted new files. Updated host links for $updated existing files. $err_summary</div>";
    }
}

// ==========================================
// 4. SEARCH & PAGINATION LOGIC
// ==========================================
$limit = 100;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($search !== '') {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM file_registry WHERE original_filename LIKE ? OR description LIKE ? OR file_hash LIKE ?");
    $count_stmt->execute(["%$search%", "%$search%", "%$search%"]);
} else {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM file_registry");
    $count_stmt->execute();
}
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

if ($search !== '') {
    $data_stmt = $pdo->prepare("SELECT * FROM file_registry WHERE original_filename LIKE ? OR description LIKE ? OR file_hash LIKE ? ORDER BY indexed_at DESC LIMIT ? OFFSET ?");
    $data_stmt->bindValue(1, "%$search%", PDO::PARAM_STR);
    $data_stmt->bindValue(2, "%$search%", PDO::PARAM_STR);
    $data_stmt->bindValue(3, "%$search%", PDO::PARAM_STR);
    $data_stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $data_stmt->bindValue(5, $offset, PDO::PARAM_INT);
} else {
    $data_stmt = $pdo->prepare("SELECT * FROM file_registry ORDER BY indexed_at DESC LIMIT ? OFFSET ?");
    $data_stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $data_stmt->bindValue(2, $offset, PDO::PARAM_INT);
}
$data_stmt->execute();
$results = $data_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Decentralized File Indexer</title>
    <style>
        :root { --primary: #2563eb; --primary-hover: #1d4ed8; --bg: #f8fafc; --card-bg: #ffffff; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: system-ui, -apple-system, sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding: 20px; line-height: 1.5; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; border-bottom: 2px solid var(--border); padding-bottom: 20px; }
        h1 { margin: 0; font-size: 1.75rem; color: #0f172a; }
        .actions { display: flex; gap: 10px; align-items: center; }
        .search-form { display: flex; gap: 8px; }
        input[type="text"] { padding: 8px 14px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; width: 260px; }
        input[type="text"]:focus { outline: 2px solid var(--primary); }
        .btn { padding: 8px 16px; border-radius: 6px; border: none; font-size: 0.95rem; cursor: pointer; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; }
        .btn-primary { background-color: var(--primary); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-secondary { background-color: #64748b; color: white; }
        .btn-secondary:hover { background-color: #475569; }
        .btn-sync { background-color: #10b981; color: white; }
        .btn-sync:hover { background-color: #059669; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; word-break: break-all; }
        .alert.success { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert.error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .file-title { font-size: 1.2rem; font-weight: 600; color: #0f172a; margin: 0; }
        .file-meta { font-size: 0.85rem; color: #64748b; margin-bottom: 12px; font-family: monospace; }
        .file-desc { background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-size: 0.9rem; margin-bottom: 15px; color: #334155; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.85rem; margin-bottom: 15px; border-top: 1px dashed var(--border); padding-top: 10px; }
        .hosts-section { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
        .host-label { font-size: 0.85rem; font-weight: 600; color: #475569; }
        .host-link { font-size: 0.85rem; padding: 4px 10px; background: #eff6ff; color: var(--primary); border: 1px solid #bfdbfe; border-radius: 4px; text-decoration: none; transition: all 0.2s; }
        .host-link:hover { background: var(--primary); color: white; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 30px; margin-bottom: 5px; }
        .pagination a { padding: 8px 14px; border: 1px solid var(--border); background: white; color: var(--text); text-decoration: none; border-radius: 6px; font-size: 0.9rem; }
        .pagination a.active { background: var(--primary); color: white; border-color: var(--primary); }
        .no-results { text-align: center; padding: 40px; color: #64748b; font-size: 1.1rem; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>Decentralized File Indexer</h1>
        <div class="actions">
            <form class="search-form" method="GET" action="indexer.php">
                <input type="text" name="q" id="searchField" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, description, hash...">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($search !== ''): ?>
                    <button type="button" class="btn btn-secondary" onclick="clearSearch()">Clear</button>
                <?php endif; ?>
            </form>
            <a href="indexer.php?action=sync" class="btn btn-sync">Run Sync Engine</a>
        </div>
    </div>

    <?= $sync_message ?>

    <p style="font-size: 0.95rem; color: #64748b;">Found <?= $total_rows ?> matching records. Displaying up to 100 items per page.</p>

    <?php if (count($results) > 0): ?>
        <?php foreach ($results as $row): 
            $hosts_list = json_decode($row['hosts'], true);
            if (!is_array($hosts_list)) $hosts_list = [];
        ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="file-title"><?= htmlspecialchars($row['original_filename']) ?></h3>
                    <span class="btn" style="background:#e0f2fe; color:#0369a1; font-size:0.8rem; padding:3px 8px; font-weight:600;">
                        <?= htmlspecialchars(strtoupper($row['extension'])) ?>
                    </span>
                </div>

                <div class="file-meta">
                    <strong>Hash Identification:</strong> <?= htmlspecialchars($row['file_hash']) ?>
                </div>

                <?php if (!empty($row['description'])): ?>
                    <div class="file-desc">
                        <strong>Description:</strong> <?= htmlspecialchars($row['description']) ?>
                    </div>
                <?php endif; ?>

                <div class="details-grid">
                    <div><strong>File Size:</strong> <?= number_format($row['file_size']) ?> bytes</div>
                    <div><strong>Indexed At:</strong> <?= htmlspecialchars($row['indexed_at']) ?></div>
                    <div><strong>Public Key:</strong> <?= !empty($row['public_key']) ? htmlspecialchars(substr($row['public_key'], 0, 20)) . '...' : 'None' ?></div>
                    <div><strong>Node Info:</strong> <?= htmlspecialchars($row['node_info'] ?: 'None') ?></div>
                </div>

                <div class="hosts-section">
                    <span class="host-label">Download Locations:</span>
                    <?php foreach ($hosts_list as $host): ?>
                        <a href="<?= htmlspecialchars($host) ?>/files/<?= htmlspecialchars($row['server_filename']) ?>" target="_blank" class="host-link">
                            <?= htmlspecialchars(str_replace(['http://', 'https://'], '', $host)) ?> ↗
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="indexer.php?q=<?= urlencode($search) ?>&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-results">
            No files registered or matching your criteria. Try executing "Run Sync Engine".
        </div>
    <?php endif; ?>
</div>

<script>
    function clearSearch() {
        document.getElementById('searchField').value = '';
        window.location.href = 'indexer.php';
    }
</script>
</body>
</html>