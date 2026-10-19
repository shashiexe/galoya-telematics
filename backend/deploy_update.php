<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/bootstrap.php';

$key = $_GET['key'] ?? '';
$isAdmin = isset($_SESSION['admin_user']);
if ($key !== 'galoya2026' && !$isAdmin) {
    die("Access denied. Please visit: deploy_update.php?key=galoya2026");
}

set_time_limit(300);

$filesToUpdate = [
    'bootstrap.php' => 'backend/bootstrap.php',
    'api.php' => 'backend/api.php',
    'admin/history.php' => 'backend/admin/history.php',
    'admin/photos.php' => 'backend/admin/photos.php',
    'admin/download_photo.php' => 'backend/admin/download_photo.php',
    'admin/index.php' => 'backend/admin/index.php',
    'admin/_common.php' => 'backend/admin/_common.php',
    'admin/geofences.php' => 'backend/admin/geofences.php',
    'admin/reports.php' => 'backend/admin/reports.php',
    'admin/unit_properties.php' => 'backend/admin/unit_properties.php',
    'admin/units.php' => 'backend/admin/units.php',
    'admin/staff.php' => 'backend/admin/staff.php',
    'admin/tasks.php' => 'backend/admin/tasks.php',
    'admin/dashboard.php' => 'backend/admin/dashboard.php',
    'admin/assets/app.js' => 'backend/admin/assets/app.js',
    'admin/assets/style.css' => 'backend/admin/assets/style.css',
    'app/index.html' => 'backend/app/index.html',
    'bootstrap.php' => 'backend/bootstrap.php',
    'api.php' => 'backend/api.php',
    'sync_agriculture_kml.php' => 'backend/sync_agriculture_kml.php',
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

// 1.2 Flush OPcache to guarantee immediate PHP bytecode invalidation
if (function_exists('opcache_reset')) {
    if (@opcache_reset()) {
        $results[] = ["⚡ PHP OPcache successfully cleared!", "color:#10b981;font-weight:bold"];
    } else {
        $results[] = ["ℹ️ OPcache reset skipped or not restricted", "color:#94a3b8"];
    }
}

$apkTargetPath = __DIR__ . '/Galoya-Telematics.apk';
try {
    $token = trim($_GET['gh_token'] ?? ($_POST['gh_token'] ?? ''));
    $repo = 'shashiexe/galoya-telematics';

    // 1. Find latest APK artifact from GitHub Actions
    $ch = curl_init("https://api.github.com/repos/$repo/actions/artifacts?per_page=5");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "User-Agent: GaloyaDeployer"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $artJson = curl_exec($ch);
    curl_close($ch);

    $artData = json_decode($artJson, true);
    $apkArtId = null;
    if (!empty($artData['artifacts'])) {
        foreach ($artData['artifacts'] as $art) {
            if ($art['name'] === 'Galoya-Telematics-APK' && empty($art['expired'])) {
                $apkArtId = $art['id'];
                break;
            }
        }
    }

    if ($apkArtId) {
        $zipTmp = __DIR__ . '/tmp_apk.zip';
        $ch2 = curl_init("https://api.github.com/repos/$repo/actions/artifacts/$apkArtId/zip");
        $fp = fopen($zipTmp, 'w+');
        curl_setopt($ch2, CURLOPT_FILE, $fp);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "User-Agent: GaloyaDeployer"
        ]);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 180);
        $ok2 = curl_exec($ch2);
        curl_close($ch2);
        fclose($fp);

        if ($ok2 && file_exists($zipTmp) && filesize($zipTmp) > 1000000) {
            $zip = new ZipArchive();
            if ($zip->open($zipTmp) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (substr($filename, -4) === '.apk') {
                        copy("zip://".$zipTmp."#".$filename, $apkTargetPath);
                        break;
                    }
                }
                $zip->close();
            }
            @unlink($zipTmp);
            $results[] = ["✅ Live Web APK Updated directly from GitHub Actions: Galoya-Telematics.apk (" . round(filesize($apkTargetPath) / 1048576, 1) . " MB)", "color:#10b981;font-weight:bold"];
        }
    } else {
        $results[] = ["ℹ️ No new APK artifact found on GitHub.", "color:#94a3b8"];
    }
} catch (Throwable $e) {
    $results[] = ["⚠️ Web APK download note: " . $e->getMessage(), "color:#f59e0b"];
}

// 2. Run Database Migrations
$pdo = db();

// Field Photos Table
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
} catch (Throwable $e) {}

try {
    q("ALTER TABLE field_photos ADD COLUMN geofence_name VARCHAR(128) NULL AFTER note");
} catch (Throwable $e) {}
try {
    q("ALTER TABLE field_photos ADD COLUMN activity_type VARCHAR(64) NULL DEFAULT 'General' AFTER geofence_name");
    q("ALTER TABLE field_photos ADD COLUMN auto_geofence VARCHAR(128) NULL AFTER activity_type");
    q("ALTER TABLE field_photos ADD COLUMN selected_geofence VARCHAR(128) NULL AFTER auto_geofence");
    $results[] = ["✅ Database: Ensured field_photos activity & dual geofence columns exist", "color:#10b981"];
} catch (Throwable $e) {}

// Departments, Sections, and Designations
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `departments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(120) NOT NULL,
        `code` VARCHAR(50) NULL,
        `description` VARCHAR(255) NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_dept_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sections` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `department_id` INT NULL,
        `name` VARCHAR(120) NOT NULL,
        `code` VARCHAR(50) NULL,
        `description` VARCHAR(255) NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (`department_id`),
        UNIQUE KEY `uniq_sec_dept` (`department_id`, `name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `designations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(120) NOT NULL,
        `code` VARCHAR(50) NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_desig_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed defaults if empty
    $dCount = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    if ($dCount === 0) {
        $pdo->exec("INSERT IGNORE INTO `departments` (`id`, `name`, `code`, `description`) VALUES 
            (1, 'Agriculture', 'AGRI', 'Sugarcane Plantation & Agronomy Field Operations'),
            (2, 'Fleet & Transport', 'FLEET', 'Tractors, Harvesters, Infielders & Bowser Transport'),
            (3, 'Factory & Mill', 'MILL', 'Hingurana Sugar Processing Complex & Weighbridge'),
            (4, 'Irrigation & Drainage', 'IRRI', 'Canal Systems, Water Pumps & Drainage Division'),
            (5, 'Civil & Security', 'SEC', 'Estate Security, Gate Controls & Estate Roads')");
        
        $pdo->exec("INSERT IGNORE INTO `sections` (`id`, `department_id`, `name`, `code`) VALUES 
            (1, 1, 'Sector D (Sugarcane)', 'SEC-D'),
            (2, 1, 'Sector C (Sugarcane)', 'SEC-C'),
            (3, 1, 'Sector B (Sugarcane)', 'SEC-B'),
            (4, 1, 'Sector A (Sugarcane)', 'SEC-A'),
            (5, 2, 'Central Workshop & Garage', 'GARAGE'),
            (6, 2, 'Haulage / Transport Yard', 'HAUL'),
            (7, 3, 'Factory Complex & Yard', 'MILL-YD'),
            (8, 4, 'Main Canal Division', 'CANAL')");

        $pdo->exec("INSERT IGNORE INTO `designations` (`id`, `name`, `code`) VALUES 
            (1, 'Field Officer', 'FO'),
            (2, 'Agronomist', 'AGRO'),
            (3, 'Tractor Operator', 'TRAC-OP'),
            (4, 'Harvester Operator', 'HARV-OP'),
            (5, 'Field Supervisor', 'SUP'),
            (6, 'Field Inspector', 'INSP'),
            (7, 'Mechanic / Technician', 'MECH'),
            (8, 'Field Staff', 'STAFF')");
    }
    $results[] = ["✅ Database: Ensured departments, sections, and designations exist", "color:#10b981"];
} catch (Throwable $e) {
    $results[] = ["⚠️ Departments table note: " . $e->getMessage(), "color:#f59e0b"];
}

// Columns on users, tasks, and duty_logs
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `department_id` INT NULL AFTER `role`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `section_id` INT NULL AFTER `department_id`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `designation_id` INT NULL AFTER `section_id`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `designation` VARCHAR(100) NULL AFTER `designation_id`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `department_id` INT NULL AFTER `assigned_to`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `section_id` INT NULL AFTER `department_id`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `duty_logs` ADD COLUMN `battery` TINYINT NULL AFTER `lng`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `duty_logs` ADD COLUMN `event_type` VARCHAR(50) NULL DEFAULT 'duty' AFTER `battery`"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE `duty_logs` ADD COLUMN `note` VARCHAR(255) NULL AFTER `event_type`"); } catch (Throwable $e) {}


// Geofence Groups Table
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
    $results[] = ["✅ Database: Ensured geofence_groups table exists", "color:#10b981"];
} catch (Throwable $e) {
    $results[] = ["⚠️ geofence_groups table note: " . $e->getMessage(), "color:#f59e0b"];
}

// Add columns to geofences
$cols = [
    'category' => "ALTER TABLE `geofences` ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation' AFTER `id`",
    'group_name' => "ALTER TABLE `geofences` ADD COLUMN `group_name` VARCHAR(120) NULL AFTER `category`",
    'block_name' => "ALTER TABLE `geofences` ADD COLUMN `block_name` VARCHAR(100) NULL AFTER `group_name`"
];
foreach ($cols as $col => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ["✅ Added column `$col` to `geofences`", "color:#10b981"];
    } catch (Throwable $e) {}
}
try {
    @$pdo->exec("ALTER TABLE `geofences` ADD INDEX `idx_group_name` (`group_name`)");
    @$pdo->exec("ALTER TABLE `geofences` ADD INDEX `idx_category` (`category`)");
} catch (Throwable $e) {}

// Ensure users.unit_type supports all roles (field_staff, supervisor, manager, etc.)
try {
    $pdo->exec("ALTER TABLE `users` MODIFY `unit_type` VARCHAR(50) NOT NULL DEFAULT 'staff'");
    $results[] = ["✅ Ensured `users.unit_type` supports all roles (VARCHAR 50)", "color:#10b981"];
} catch (Throwable $e) {}

// Speed Calculation Correction (Convert old m/s to km/h)
try {
    $speedFixCount = $pdo->exec("UPDATE locations SET speed = ROUND(speed * 3.6, 1) WHERE speed > 0 AND speed < 30");
    if ($speedFixCount > 0) {
        $results[] = ["⚡ Converted $speedFixCount historical GPS points from m/s to km/h (speed * 3.6)", "color:#38bdf8"];
    } else {
        $results[] = ["ℹ️ GPS speed telemetry points are already up to date.", "color:#94a3b8"];
    }
} catch (Throwable $e) {
    $results[] = ["⚠️ Speed conversion note: " . $e->getMessage(), "color:#f59e0b"];
}

// Check Geofences Count & Auto-Seed if needed
$totalGf = (int)$pdo->query("SELECT COUNT(*) FROM geofences WHERE active=1")->fetchColumn();
$results[] = ["🗺️ Current Geofences in DB: $totalGf", "color:#38bdf8;font-weight:bold"];

try {
    $u22Count = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE user_id=22 AND DATE(recorded_at)=CURDATE()")->fetchColumn();
    $u22MinMax = $pdo->query("SELECT MIN(recorded_at) as min_t, MAX(recorded_at) as max_t FROM locations WHERE user_id=22 AND DATE(recorded_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
    $latestPt = $pdo->query("SELECT user_id, lat, lng, speed, recorded_at, received_at FROM locations ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $results[] = ["📍 User 22 Points Today: $u22Count points (from {$u22MinMax['min_t']} to {$u22MinMax['max_t']})", "color:#a855f7;font-weight:bold"];
} catch (Throwable $e) {}

try {
    $latestPhotos = $pdo->query("SELECT id, user_id, lat, lng, taken_at, activity_type, geofence_name FROM field_photos ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $results[] = ["📷 Latest Field Photos in DB: " . json_encode($latestPhotos), "color:#ec4899;font-weight:bold"];
} catch (Throwable $e) {}

try {
    $errFiles = [__DIR__ . '/admin/error_log', __DIR__ . '/error_log'];
    foreach ($errFiles as $ef) {
        if (file_exists($ef)) {
            $errContent = file($ef);
            $lastErr = array_slice($errContent, -8);
            $results[] = ["🪵 " . basename(dirname($ef)) . "/" . basename($ef) . ":<br>" . nl2br(htmlspecialchars(implode("", $lastErr))), "color:#f87171"];
        }
    }
} catch (Throwable $e) {}

if ($totalGf < 100) {
    $results[] = ["🌾 Tip: Click 'Fast Agriculture Sync' below to import all 5,034 sectors across the 5 Zones.", "color:#f59e0b;font-weight:bold"];
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Galoya Telematics - 1-Click Server Update</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f8fafc; padding: 40px 20px; }
    .box { max-width: 700px; margin: 0 auto; background: #1e293b; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
    h1 { margin-top: 0; color: #38bdf8; font-size: 22px; display: flex; align-items: center; gap: 10px; }
    .log { background: #0f172a; padding: 16px; border-radius: 10px; font-family: monospace; font-size: 13px; line-height: 1.8; margin: 20px 0; border: 1px solid #334155; max-height: 400px; overflow-y: auto; }
    .btn { display: inline-block; padding: 10px 18px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 13px; }
    .btn:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="box">
    <h1>🚀 Galoya Telematics Server Update</h1>
    <p>All core backend files, agriculture geofences, speed telemetry & database columns have been updated.</p>
    
    <div class="log">
      <?php foreach ($results as [$line, $style]): ?>
        <div style="<?= $style ?>"><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
      <a class="btn" style="background:#0284c7" href="admin/geofences.php">🗺️ Geofences & Groups</a>
      <a class="btn" style="background:#059669" href="sync_agriculture_kml.php?key=galoya2026">🌾 Fast Agriculture Sync</a>
      <a class="btn" style="background:#475569" href="admin/index.php">🏠 Live Monitoring Radar</a>
      <a class="btn" style="background:#8b5cf6" href="admin/history.php">📍 Route History</a>
    </div>
  </div>
</body>
</html>
