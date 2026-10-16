<?php
/**
 * Fast Agriculture KML / KMZ Sync Script for Galoya Telematics
 * Imports 5,034 Plantation Sectors across 5 Zones:
 * - Deegawapiya Zone
 * - Galmaduwa Zone
 * - Hingurana Zone
 * - Neeththa Zone
 * - Varipathanchena Zone
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/bootstrap.php';

$key = $_GET['key'] ?? '';
$isAdmin = isset($_SESSION['admin_user']);
if ($key !== 'galoya2026' && !$isAdmin) {
    die("Access denied. Please visit with: ?key=galoya2026");
}

set_time_limit(300);
ini_set('memory_limit', '512M');

$pdo = get_db();
$results = [];

// 1. Ensure geofence_groups table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `geofence_groups` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation',
      `name` VARCHAR(120) NOT NULL,
      `code` VARCHAR(50) NULL,
      `color` VARCHAR(20) NOT NULL DEFAULT '#10b981',
      `description` VARCHAR(255) NULL,
      `active` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_cat_name` (`category`, `name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results[] = ["✅ Ensured geofence_groups table exists", "color:#10b981"];
} catch (Throwable $e) {
    $results[] = ["⚠️ geofence_groups table note: " . $e->getMessage(), "color:#f59e0b"];
}

// 2. Ensure columns in geofences table
$cols = [
    'category' => "ALTER TABLE `geofences` ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation' AFTER `id`",
    'group_name' => "ALTER TABLE `geofences` ADD COLUMN `group_name` VARCHAR(120) NULL AFTER `category`",
    'block_name' => "ALTER TABLE `geofences` ADD COLUMN `block_name` VARCHAR(100) NULL AFTER `group_name`"
];
foreach ($cols as $col => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ["✅ Added column `$col` to `geofences` table", "color:#10b981"];
    } catch (Throwable $e) {
        // column already exists
    }
}

try {
    @$pdo->exec("ALTER TABLE `geofences` ADD INDEX `idx_group_name` (`group_name`)");
    @$pdo->exec("ALTER TABLE `geofences` ADD INDEX `idx_category` (`category`)");
} catch (Throwable $e) {}

// 3. Insert / update seed data from SQL gzip
$gzPath = __DIR__ . '/agriculture_geofences_seed.sql.gz';
$sqlPath = __DIR__ . '/agriculture_geofences_seed.sql';

if (!file_exists($gzPath) && !file_exists($sqlPath)) {
    // Download directly from GitHub raw
    $remoteUrl = 'https://raw.githubusercontent.com/shashiexe/galoya-telematics/main/backend/agriculture_geofences_seed.sql.gz';
    $ch = curl_init($remoteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $gzData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $gzData && strlen($gzData) > 1000) {
        file_put_contents($gzPath, $gzData);
        $results[] = ["📥 Downloaded agriculture_geofences_seed.sql.gz from GitHub (" . round(strlen($gzData)/1024) . " KB)", "color:#38bdf8"];
    }
}

if (file_exists($gzPath)) {
    $rawSql = gzdecode(file_get_contents($gzPath));
} elseif (file_exists($sqlPath)) {
    $rawSql = file_get_contents($sqlPath);
} else {
    $rawSql = null;
}

if ($rawSql) {
    $t0 = microtime(true);
    // Split SQL into statements
    // We break by semicolon followed by newline
    $queries = explode(";\n\n", $rawSql);
    $executed = 0;
    $errors = 0;

    foreach ($queries as $qStr) {
        $qStr = trim($qStr);
        if (!$qStr) continue;
        try {
            $pdo->exec($qStr);
            $executed++;
        } catch (Throwable $e) {
            $errors++;
            if ($errors <= 3) {
                $results[] = ["⚠️ SQL statement error: " . substr($e->getMessage(), 0, 150), "color:#ef4444"];
            }
        }
    }
    $sec = round(microtime(true) - $t0, 2);
    $results[] = ["🎉 Successfully executed $executed batch queries in {$sec}s (Errors: $errors)", "color:#38bdf8;font-weight:bold"];
} else {
    $results[] = ["⚠️ No SQL seed file found at $gzPath", "color:#f59e0b"];
}

// 4. Check statistics
$groups = $pdo->query("SELECT category, name, (SELECT COUNT(*) FROM geofences g WHERE g.group_name=geofence_groups.name) as gf_count FROM geofence_groups ORDER BY category, name")->fetchAll();
$totalGf = (int)$pdo->query("SELECT COUNT(*) FROM geofences WHERE active=1")->fetchColumn();
$results[] = ["📊 Total Active Geofences in Database: $totalGf", "color:#10b981;font-weight:bold;font-size:15px"];

foreach ($groups as $g) {
    $results[] = ["   - [{$g['category']}] {$g['name']}: {$g['gf_count']} sectors", "color:#94a3b8"];
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Galoya Agriculture KML Sync</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f8fafc; padding: 40px 20px; }
    .box { max-width: 720px; margin: 0 auto; background: #1e293b; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
    h1 { margin-top: 0; color: #38bdf8; font-size: 22px; display: flex; align-items: center; gap: 10px; }
    .log { background: #0f172a; padding: 18px; border-radius: 10px; font-family: monospace; font-size: 13px; line-height: 1.8; margin: 20px 0; border: 1px solid #334155; max-height: 500px; overflow-y: auto; }
    .btn { display: inline-block; padding: 10px 20px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; }
    .btn:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="box">
    <h1>🌾 Galoya Agriculture KML Sync</h1>
    <p>Plantation & Outgrower Geofence Zones have been synchronized with the database.</p>
    
    <div class="log">
      <?php foreach ($results as [$line, $style]): ?>
        <div style="<?= $style ?>"><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:12px;margin-top:20px;">
      <a class="btn" href="admin/geofences.php">🗺️ Open Geofences Manager</a>
      <a class="btn" style="background:#059669" href="admin/index.php">🏠 Live Monitoring Radar</a>
    </div>
  </div>
</body>
</html>
