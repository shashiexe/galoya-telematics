<?php
/**
 * 1-Click Update Script for Galoya Telematics (track.galoya.lk)
 * Automatically fetches the latest files from GitHub main branch and runs database migrations.
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/bootstrap.php';

$key = $_GET['key'] ?? '';
$isAdmin = isset($_SESSION['admin_user']);
if ($key !== 'galoya2026' && !$isAdmin) {
    die("Access denied. Please visit: deploy_update.php?key=galoya2026");
}

$filesToUpdate = [
    'bootstrap.php' => 'backend/bootstrap.php',
    'api.php' => 'backend/api.php',
    'admin/history.php' => 'backend/admin/history.php',
    'admin/photos.php' => 'backend/admin/photos.php',
    'admin/download_photo.php' => 'backend/admin/download_photo.php',
    'admin/index.php' => 'backend/admin/index.php',
    'admin/_common.php' => 'backend/admin/_common.php',
    'deploy_update.php' => 'backend/deploy_update.php',
];

$githubBase = 'https://raw.githubusercontent.com/shashiexe/galoya-telematics/main/';
$results = [];

// 1. Update files from GitHub
foreach ($filesToUpdate as $localRel => $repoRel) {
    $url = $githubBase . $repoRel . '?t=' . time();
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $targetPath = __DIR__ . '/' . $localRel;
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

    if ($httpCode === 200 && strlen($content) > 10) {
        file_put_contents($targetPath, $content);
        $results[] = ["✅ File updated: $localRel (" . strlen($content) . " bytes)", "color:#10b981"];
    } else {
        $results[] = ["❌ Failed updating $localRel (HTTP $httpCode)", "color:#ef4444"];
    }
}

// 2. Run Database Migrations
try {
    q("CREATE TABLE IF NOT EXISTS field_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        note TEXT NULL,
        geofence_name VARCHAR(128) NULL,
        taken_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ["✅ Database: Ensured field_photos table exists", "color:#10b981"];
} catch (Throwable $e) {
    $results[] = ["⚠️ Database note (field_photos table): " . $e->getMessage(), "color:#f59e0b"];
}

try {
    q("ALTER TABLE field_photos ADD COLUMN geofence_name VARCHAR(128) NULL AFTER note");
    $results[] = ["✅ Database: Added geofence_name column to field_photos", "color:#10b981"];
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $results[] = ["ℹ️ Database: Column geofence_name already exists in field_photos", "color:#3b82f6"];
    }
}

try {
    $fpCount = (int)q("SELECT COUNT(*) FROM field_photos")->fetchColumn();
    $latestFp = q("SELECT * FROM field_photos ORDER BY id DESC LIMIT 5")->fetchAll();
    $results[] = ["📸 Total field_photos in DB: $fpCount", "color:#38bdf8;font-weight:bold"];
    foreach ($latestFp as $p) {
        $results[] = ["   - Photo #{$p['id']}: User {$p['user_id']} | Path: {$p['file_path']} | Geofence: {$p['geofence_name']} | Taken: {$p['taken_at']}", "color:#94a3b8"];
    }
} catch (Throwable $e) {
    $results[] = ["⚠️ Error querying field_photos: " . $e->getMessage(), "color:#ef4444"];
}

// Check uploads directory
$uploadDir = $CONFIG['upload_dir'];
if (is_dir($uploadDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir));
    $fileCount = 0;
    $recentFiles = [];
    foreach ($it as $file) {
        if ($file->isFile()) {
            $fileCount++;
            if (count($recentFiles) < 5) {
                $recentFiles[] = $file->getPathname() . " (" . round($file->getSize()/1024) . " KB)";
            }
        }
    }
    $results[] = ["📁 Uploads folder exists ($uploadDir), total files: $fileCount", "color:#38bdf8"];
    foreach ($recentFiles as $rf) {
        $results[] = ["   - $rf", "color:#94a3b8"];
    }
} else {
    $results[] = ["❌ Uploads folder does NOT exist ($uploadDir)!", "color:#ef4444"];
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Galoya Telematics - 1-Click Server Update</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f8fafc; padding: 40px 20px; }
    .box { max-width: 650px; margin: 0 auto; background: #1e293b; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
    h1 { margin-top: 0; color: #38bdf8; font-size: 22px; display: flex; align-items: center; gap: 10px; }
    .log { background: #0f172a; padding: 16px; border-radius: 10px; font-family: monospace; font-size: 13px; line-height: 1.8; margin: 20px 0; border: 1px solid #334155; }
    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; }
    .btn:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="box">
    <h1>🚀 Galoya Telematics Server Update</h1>
    <p>All core backend files & database columns have been updated to the latest version.</p>
    
    <div class="log">
      <?php foreach ($results as [$line, $style]): ?>
        <div style="<?= $style ?>"><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:12px;margin-top:20px;">
      <a class="btn" href="admin/photos.php">📸 View Photo Gallery</a>
      <a class="btn" style="background:#059669" href="admin/history.php">🗺️ View Route History</a>
      <a class="btn" style="background:#475569" href="admin/index.php">🏠 Live Radar</a>
    </div>
  </div>
</body>
</html>
