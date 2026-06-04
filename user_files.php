<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  USER ARCHIVES — Plugin & UI for Mesh Node
//  Acts as a hook when included, and a Web UI when accessed directly.
// ═══════════════════════════════════════════════════════════════════════════════

define('USERS_DIR', __DIR__ . '/users');

// ── 1. BACKGROUND HOOK (Runs silently during a peer push) ─────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_FILES['file'], $_POST['peer_push']) &&
    $_POST['peer_push'] === '1'
) {
    $pk = trim($_POST['public_key'] ?? '');

    // Validate: only letters, numbers, underscore, max 512 chars
    if ($pk !== '' && preg_match('/^[a-zA-Z0-9_]{1,512}$/', $pk)) {
        $upload = $_FILES['file'];

        if ($upload['error'] === UPLOAD_ERR_OK) {
            $tmpPath      = $upload['tmp_name'];
            $originalName = $upload['name'];
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $ext          = preg_replace('/[^a-z0-9]/', '', $ext);
            $blocked      = ['php','php3','php4','php5','php7','phtml','phar'];

            if (!in_array($ext, $blocked, true)) {
                $hash     = hash_file('sha256', $tmpPath);
                $baseName = $ext !== '' ? "{$hash}.{$ext}" : $hash;
                $userDir  = USERS_DIR . '/' . $pk;

                if (!is_dir($userDir)) {
                    @mkdir($userDir, 0755, true);
                }

                $destFile = $userDir . '/' . $baseName;
                $destInfo = $userDir . '/' . $hash . '.json';

                // Save exact copy of binary -- do not overwrite if exists
                if (!file_exists($destFile)) {
                    @copy($tmpPath, $destFile);
                }

                // Save JSON info -- do not overwrite if exists
                if (!file_exists($destInfo)) {
                    $info = [
                        'original_filename' => $originalName,
                        'size'              => (int) filesize($tmpPath),
                        'extension'         => $ext,
                        'public_key'        => $pk,
                        'node_info'         => trim($_POST['node_info'] ?? ''),
                        'description'       => substr(trim($_POST['description'] ?? ''), 0, 1024)
                    ];
                    @file_put_contents($destInfo, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
    // Do NOT exit here. We yield control back to index.php so it can do its own storage.
}

// ── 2. STANDALONE UI (Runs ONLY when accessed directly in the browser) ─────────
$isDirectAccess = (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME']));
if (!$isDirectAccess) {
    return; // Exit the plugin logic smoothly and return to index.php
}

// --- Direct Access Routing ---
if (!is_dir(USERS_DIR)) @mkdir(USERS_DIR, 0755, true);

$errorMsg = '';
$viewPk   = null;
$files    = [];

// Handle requests (View or ZIP)
if (isset($_GET['pk'])) {
    $pk = trim($_GET['pk']);
    if (!preg_match('/^[a-zA-Z0-9_]{1,512}$/', $pk)) {
        $errorMsg = "Invalid public key format.";
    } else {
        $targetDir = USERS_DIR . '/' . $pk;
        if (!is_dir($targetDir)) {
            $errorMsg = "No archive found for this public key.";
        } else {
            // Only list non-JSON files for display and download
            $allFiles = array_values(array_diff(scandir($targetDir), ['.', '..']));
            $files    = array_values(array_filter($allFiles, fn($f) => substr($f, -5) !== '.json'));

            if (isset($_GET['action']) && $_GET['action'] === 'zip') {
                if (empty($files)) {
                    die('Folder is empty.');
                }
                if (!class_exists('ZipArchive')) {
                    die('ZipArchive extension is missing on this server.');
                }

                $zipFile = sys_get_temp_dir() . '/mesh_' . $pk . '_' . time() . '.zip';
                $zip     = new ZipArchive();

                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $usedNames = [];

                    foreach ($files as $file) {
                        $filePath  = $targetDir . '/' . $file;
                        $entryName = $file;

                        $hashBase = pathinfo($file, PATHINFO_FILENAME);
                        $jsonPath = $targetDir . '/' . $hashBase . '.json';

                        if (file_exists($jsonPath)) {
                            $meta = json_decode(file_get_contents($jsonPath), true);
                            if (!empty($meta['original_filename'])) {
                                $entryName = basename($meta['original_filename']);
                            }
                        }

                        if (isset($usedNames[$entryName])) {
                            $usedNames[$entryName]++;
                            $basePart  = pathinfo($entryName, PATHINFO_FILENAME);
                            $extPart   = pathinfo($entryName, PATHINFO_EXTENSION);
                            $entryName = $extPart !== ''
                                ? $basePart . '_' . $usedNames[$entryName] . '.' . $extPart
                                : $basePart . '_' . $usedNames[$entryName];
                        } else {
                            $usedNames[$entryName] = 1;
                        }

                        $zip->addFile($filePath, $entryName);
                    }

                    $zip->close();

                    if (!file_exists($zipFile) || filesize($zipFile) === 0) {
                        @unlink($zipFile);
                        $errorMsg = "Failed to write ZIP file to disk.";
                    } else {
                        // Discard any buffered output that would corrupt the binary stream
                        while (ob_get_level()) ob_end_clean();

                        $zipSize = filesize($zipFile);
                        header('Content-Type: application/zip');
                        header('Content-Disposition: attachment; filename="archive_' . $pk . '.zip"');
                        header('Content-Length: ' . $zipSize);
                        header('Cache-Control: no-cache, must-revalidate');
                        header('Pragma: no-cache');
                        header('Expires: 0');

                        // Stream in chunks to avoid memory limits on large archives
                        $fp = fopen($zipFile, 'rb');
                        while (!feof($fp)) {
                            echo fread($fp, 8192);
                            flush();
                        }
                        fclose($fp);
                        @unlink($zipFile);
                        exit;
                    }
                } else {
                    $errorMsg = "Failed to create ZIP file.";
                }
            } else {
                // Set flag to render the folder view instead of the home list
                $viewPk = $pk;
            }
        }
    }
}

// Gather maximum of 100 folders for the main page
$folders        = glob(USERS_DIR . '/*', GLOB_ONLYDIR) ?: [];
usort($folders, fn($a, $b) => filemtime($b) - filemtime($a)); // Sort newest first
$displayFolders = array_slice($folders, 0, 100);

// ═══════════════════════════════════════════════════════════════════════════════
//  HTML FRONT-END (Minimalist)
// ═══════════════════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Archives - Mesh Node</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#fff;color:#111;padding:2rem 1.5rem;display:flex;flex-direction:column;align-items:center}
.page{max-width:40rem;width:100%}
h1{font-size:1.4rem;font-weight:700;margin-bottom:1.5rem;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.error{background:#fff0f0;color:#d00;border:1px solid #e0c0c0;padding:0.8rem;border-radius:4px;margin-bottom:1.5rem;font-size:0.9rem}
.search-box{display:flex;gap:0.5rem;margin-bottom:2rem;background:#fafafa;padding:1rem;border-radius:6px;border:1px solid #eee;flex-wrap:wrap}
input[type="text"]{flex:1;min-width:200px;padding:0.6rem;border:1px solid #ccc;border-radius:4px;font-family:inherit}
button{padding:0.6rem 1rem;background:#0044cc;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;font-size:0.85rem;transition:opacity 0.2s}
button:hover{opacity:0.9}
button[name="action"][value="view"]{background:#eee;color:#333}
button[name="action"][value="view"]:hover{background:#e0e0e0}
.list-head{font-size:0.8rem;color:#777;margin-bottom:0.8rem;border-bottom:1px solid #eee;padding-bottom:0.5rem}
.folder-list{display:flex;flex-direction:column;gap:0.5rem}
.folder{display:flex;justify-content:space-between;align-items:center;padding:0.8rem 1rem;border:1px solid #ddd;border-radius:4px;flex-wrap:wrap;gap:1rem}
.folder-name{font-family:monospace;font-size:0.9rem;font-weight:bold;color:#0044cc;word-break:break-all}
.folder-meta{font-size:0.75rem;color:#777;margin-top:0.2rem}
.actions{display:flex;gap:0.5rem}
.btn-link{padding:0.4rem 0.8rem;text-decoration:none;font-size:0.8rem;border-radius:4px;background:#f0f4ff;color:#0044cc;border:1px solid #cce0ff;font-weight:500;transition:background 0.15s}
.btn-link:hover{background:#e0edff}
.file-list{border:1px solid #eee;border-radius:6px;overflow:hidden}
.file-item{display:flex;justify-content:space-between;padding:0.8rem 1rem;border-bottom:1px solid #eee;font-family:monospace;font-size:0.85rem;background:#fafafa}
.file-item:last-child{border-bottom:none}
.file-item a{color:#0044cc;text-decoration:none}
.file-item a:hover{text-decoration:underline}
.file-item .orig-name{font-size:0.75rem;color:#888;margin-top:0.2rem;font-family:system-ui,-apple-system,sans-serif}
.back-link{display:inline-block;margin-bottom:1.5rem;color:#0044cc;text-decoration:none;font-size:0.9rem;font-weight:500}
.back-link:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="page">
    <?php if ($errorMsg): ?>
        <div class="error">✗ <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($viewPk): ?>
        <a href="?" class="back-link">← Back to Archives</a>
        <h1>Folder: <em><?= htmlspecialchars($viewPk) ?></em></h1>

        <div style="margin-bottom:1rem">
            <a href="?pk=<?= urlencode($viewPk) ?>&action=zip" class="btn-link">⬇ Download All as ZIP</a>
        </div>

        <div class="file-list">
            <?php if (empty($files)): ?>
                <div style="text-align:center;color:#999;padding:2rem 0;font-size:0.9rem;">No files found.</div>
            <?php endif; ?>
            <?php foreach ($files as $f):
                $fPath    = 'users/' . urlencode($viewPk) . '/' . rawurlencode($f);
                $size     = round(filesize(USERS_DIR . '/' . $viewPk . '/' . $f) / 1024, 1) . ' KB';

                // Resolve original filename from JSON sidecar for display
                $origName = null;
                $hashBase = pathinfo($f, PATHINFO_FILENAME);
                $jsonSide = USERS_DIR . '/' . $viewPk . '/' . $hashBase . '.json';
                if (file_exists($jsonSide)) {
                    $meta = json_decode(file_get_contents($jsonSide), true);
                    if (!empty($meta['original_filename'])) {
                        $origName = $meta['original_filename'];
                    }
                }
            ?>
            <div class="file-item" style="flex-direction:column;align-items:flex-start">
                <div style="display:flex;justify-content:space-between;width:100%">
                    <a href="<?= $fPath ?>" target="_blank" rel="noopener"><?= htmlspecialchars($f) ?></a>
                    <span style="color:#777"><?= $size ?></span>
                </div>
                <?php if ($origName): ?>
                    <div class="orig-name">Original: <?= htmlspecialchars($origName) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <h1>User <em>Archives</em></h1>

        <form class="search-box" method="GET" action="?">
            <input type="text" name="pk" pattern="[a-zA-Z0-9_]{1,512}" required placeholder="Enter Public Key...">
            <button type="submit" name="action" value="view">Open</button>
            <button type="submit" name="action" value="zip">Download ZIP</button>
        </form>

        <div class="list-head">Recent Archives (Showing max 100)</div>

        <div class="folder-list">
            <?php if (empty($displayFolders)): ?>
                <div style="text-align:center;color:#999;padding:2rem 0;font-size:0.9rem;">No archives found yet.</div>
            <?php endif; ?>

            <?php foreach ($displayFolders as $dir):
                $pkName   = basename($dir);
                $allItems = array_diff(scandir($dir), ['.', '..']);
                // Count only non-JSON files as real uploaded items
                $count    = count(array_filter($allItems, fn($f) => substr($f, -5) !== '.json'));
            ?>
            <div class="folder">
                <div>
                    <div class="folder-name"><?= htmlspecialchars($pkName) ?></div>
                    <div class="folder-meta"><?= $count ?> item(s)</div>
                </div>
                <div class="actions">
                    <a href="?pk=<?= urlencode($pkName) ?>&action=view" class="btn-link" style="background:#f9f9f9;border-color:#ddd;color:#333">Open</a>
                    <a href="?pk=<?= urlencode($pkName) ?>&action=zip" class="btn-link">ZIP</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>