<?php
/**
 * 🗺️ GalOya Track360 – Expansion GIS Importer
 * Safely inserts:
 *   1. Newly added Main Plantation plots (~31 plots) into existing plantation groups.
 *   2. Outgrower (OG) Zones (7 zones, 2,506 plots) with custom distinct colors.
 *   3. Offices Location (18 locations) into "Operational Offices & Base Yards".
 */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/bootstrap.php';

$key = $_GET['key'] ?? '';
$isAdmin = isset($_SESSION['admin_user']);
if ($key !== 'galoya2026' && !$isAdmin) {
    die("Access denied. Please visit with: ?key=galoya2026");
}

set_time_limit(600);
ini_set('memory_limit', '512M');

$pdo = db();
$results = [];

// 1. Ensure geofence_groups and category columns exist
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
} catch (Throwable $e) {}

$colDefs = [
    'category'   => "ALTER TABLE `geofences` ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation' AFTER `id`",
    'group_name' => "ALTER TABLE `geofences` ADD COLUMN `group_name` VARCHAR(120) NULL AFTER `category`",
    'block_name' => "ALTER TABLE `geofences` ADD COLUMN `block_name` VARCHAR(100) NULL AFTER `group_name`",
    'center_lat' => "ALTER TABLE `geofences` ADD COLUMN `center_lat` DECIMAL(10,6) NULL",
    'center_lng' => "ALTER TABLE `geofences` ADD COLUMN `center_lng` DECIMAL(10,6) NULL",
    'min_lat'    => "ALTER TABLE `geofences` ADD COLUMN `min_lat` DECIMAL(10,6) NULL",
    'max_lat'    => "ALTER TABLE `geofences` ADD COLUMN `max_lat` DECIMAL(10,6) NULL",
    'min_lng'    => "ALTER TABLE `geofences` ADD COLUMN `min_lng` DECIMAL(10,6) NULL",
    'max_lng'    => "ALTER TABLE `geofences` ADD COLUMN `max_lng` DECIMAL(10,6) NULL"
];
foreach ($colDefs as $cName => $cSql) {
    try {
        $pdo->exec($cSql);
    } catch (Throwable $e) {}
}

// 2. Define Groups with distinct colors
$groupsToSeed = [
    // Outgrower Groups with Vibrant Colors
    ['category' => 'Outgrower', 'name' => 'OG Siyambalanduwa', 'code' => 'OG-SS', 'color' => '#f59e0b', 'desc' => 'Siyambalanduwa Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Madulla',        'code' => 'OG-MD', 'color' => '#d97706', 'desc' => 'Madulla Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Ampara',         'code' => 'OG-AM', 'color' => '#8b5cf6', 'desc' => 'Ampara Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Mahakalugolla',  'code' => 'OG-MK', 'color' => '#ec4899', 'desc' => 'Mahakalugolla Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Bibila',         'code' => 'OG-BB', 'color' => '#06b6d4', 'desc' => 'Bibila Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Nelliyadda',     'code' => 'OG-NL', 'color' => '#14b8a6', 'desc' => 'Nelliyadda Outgrower Farmer Plots'],
    ['category' => 'Outgrower', 'name' => 'OG Mahaoya',        'code' => 'OG-MO', 'color' => '#eab308', 'desc' => 'Mahaoya Outgrower Farmer Plots'],
    // Office Location Group
    ['category' => 'Other',     'name' => 'Operational Offices & Base Yards', 'code' => 'OFFICES', 'color' => '#3b82f6', 'desc' => 'Zone Offices, Agronomy Centers & Base Yards']
];

foreach ($groupsToSeed as $g) {
    try {
        $st = $pdo->prepare("INSERT INTO `geofence_groups` (`category`, `name`, `code`, `color`, `description`, `active`)
                             VALUES (?, ?, ?, ?, ?, 1)
                             ON DUPLICATE KEY UPDATE `color`=VALUES(`color`), `code`=VALUES(`code`), `description`=VALUES(`description`)");
        $st->execute([$g['category'], $g['name'], $g['code'], $g['color'], $g['desc']]);
    } catch (Throwable $e) {}
}

// 3. Load payload
$payloadFile = __DIR__ . '/expansion_geofences_payload.json';
if (!file_exists($payloadFile)) {
    die("Payload file not found: $payloadFile. Please run build_expansion_payload.php first.");
}
$payload = json_decode(file_get_contents($payloadFile), true);

$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM geofences")->fetchColumn();

// 4. Insert Prepared Items
$insertStmt = $pdo->prepare("
    INSERT INTO `geofences` (
        `category`, `group_name`, `block_name`, `name`, `code`, `type`, `color`,
        `coordinates`, `center_lat`, `center_lng`, `min_lat`, `max_lat`, `min_lng`, `max_lng`, `active`
    ) VALUES (?, ?, ?, ?, ?, 'polygon', ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE
        `category`=VALUES(`category`),
        `group_name`=VALUES(`group_name`),
        `color`=VALUES(`color`),
        `coordinates`=VALUES(`coordinates`),
        `center_lat`=VALUES(`center_lat`),
        `center_lng`=VALUES(`center_lng`)
");

$plantAdded = 0;
$ogAdded = 0;
$officeAdded = 0;

$pdo->beginTransaction();

// A. New Plantation Plots
foreach ($payload['plantation_new'] as $p) {
    // Double check it's not already in DB
    $exists = (int)q("SELECT COUNT(*) FROM geofences WHERE name=?", [$p['name']])->fetchColumn();
    if ($exists > 0) continue;
    
    $stats = $p['stats'];
    $insertStmt->execute([
        'Plantation',
        $p['group_name'],
        $p['block_name'],
        $p['name'],
        $p['code'],
        $p['color'],
        json_encode($p['coordinates']),
        $stats['center_lat'],
        $stats['center_lng'],
        $stats['min_lat'],
        $stats['max_lat'],
        $stats['min_lng'],
        $stats['max_lng']
    ]);
    $plantAdded++;
}

// B. Outgrower Plots
foreach ($payload['outgrower'] as $p) {
    $stats = $p['stats'];
    $insertStmt->execute([
        'Outgrower',
        $p['group_name'],
        $p['block_name'],
        $p['name'],
        $p['code'],
        $p['color'],
        json_encode($p['coordinates']),
        $stats['center_lat'],
        $stats['center_lng'],
        $stats['min_lat'],
        $stats['max_lat'],
        $stats['min_lng'],
        $stats['max_lng']
    ]);
    $ogAdded++;
}

// C. Offices
foreach ($payload['offices'] as $p) {
    $coords = $p['coordinates'];
    $minLat = $p['center_lat'] - 0.0015;
    $maxLat = $p['center_lat'] + 0.0015;
    $minLng = $p['center_lng'] - 0.0015;
    $maxLng = $p['center_lng'] + 0.0015;
    
    $insertStmt->execute([
        'Other',
        $p['group_name'],
        $p['block_name'],
        $p['name'],
        $p['code'],
        $p['color'],
        json_encode($coords),
        $p['center_lat'],
        $p['center_lng'],
        $minLat,
        $maxLat,
        $minLng,
        $maxLng
    ]);
    $officeAdded++;
}

$pdo->commit();

$afterCount = (int)$pdo->query("SELECT COUNT(*) FROM geofences")->fetchColumn();

// Auto update spatial Polygon column if available
try {
    $pdo->exec("UPDATE `geofences` 
                SET `polygon` = ST_GeomFromText(CONCAT('POLYGON((', 
                    REPLACE(REPLACE(REPLACE(REPLACE(`coordinates`, '],[', '#'), '[[', ''), ']]', ''), ',', ' '),
                    '))'))
                WHERE `polygon` IS NULL AND `coordinates` LIKE '[[%' AND category IN ('Outgrower', 'Other')");
} catch (Throwable $e) {}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GalOya Track360 – Expansion GIS Sync Complete</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f8fafc; padding: 40px 20px; }
    .box { max-width: 720px; margin: 0 auto; background: #1e293b; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
    h1 { margin-top: 0; color: #38bdf8; font-size: 22px; display: flex; align-items: center; gap: 10px; }
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 20px 0; }
    .stat-card { background: #0f172a; padding: 16px; border-radius: 10px; border: 1px solid #334155; }
    .stat-val { font-size: 24px; font-weight: 800; color: #10b981; }
    .stat-lbl { font-size: 11.5px; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-top: 4px; }
    .btn { display: inline-block; padding: 10px 18px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 13px; }
    .btn:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="box">
    <h1>🚀 Expansion GIS Sync Completed Successfully</h1>
    <p>Newly discovered Main Plantation plots, 7 Outgrower Zones, and Office Locations have been imported into the database.</p>
    
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-val"><?= $plantAdded ?></div>
        <div class="stat-lbl">New Plantation Plots Added</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#f59e0b"><?= $ogAdded ?></div>
        <div class="stat-lbl">Outgrower Plots Added</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#38bdf8"><?= $officeAdded ?></div>
        <div class="stat-lbl">Offices Added (Other)</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#a855f7"><?= $afterCount ?></div>
        <div class="stat-lbl">Total Geofences in DB</div>
      </div>
    </div>

    <div style="background:#0f172a;padding:14px;border-radius:8px;border-left:4px solid #10b981;font-size:12.5px;line-height:1.6;margin-bottom:20px">
      <strong>Verification Summary:</strong><br>
      • Before import total: <strong><?= $beforeCount ?></strong> geofences<br>
      • After import total: <strong><?= $afterCount ?></strong> geofences (+<?= $afterCount - $beforeCount ?> net added)<br>
      • Color Palette: Outgrower zones mapped to Amber, Orange, Violet, Pink, Teal, Cyan and Yellow.<br>
      • Administrative Offices mapped to Sapphire Blue with 120m circular spatial radius.
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn" style="background:#0284c7" href="admin/geofences.php">🗺️ View Geofences & Groups</a>
      <a class="btn" style="background:#059669" href="admin/index.php">🏠 Live Monitoring Radar</a>
    </div>
  </div>
</body>
</html>

