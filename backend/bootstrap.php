<?php
// Shared bootstrap: config, DB connection, helpers.
$CONFIG = require __DIR__ . '/config.php';
date_default_timezone_set($CONFIG['timezone']);

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4",
                $CONFIG['db_user'], $CONFIG['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo "<!doctype html><html><head><meta charset='utf-8'><title>Database Error</title></head><body style='font-family:-apple-system,sans-serif;background:#0f172a;color:#f8fafc;padding:40px;display:flex;justify-content:center'>";
            echo "<div style='max-width:580px;background:#1e293b;border:1px solid #ef4444;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,0.5)'>";
            echo "<h2 style='color:#f87171;margin-top:0'>⚠️ Database Connection Error</h2>";
            echo "<p>Could not connect to database <strong>" . htmlspecialchars($CONFIG['db_name']) . "</strong> with user <strong>" . htmlspecialchars($CONFIG['db_user']) . "</strong>.</p>";
            echo "<div style='background:#0f172a;padding:12px;border-radius:6px;border-left:4px solid #ef4444;font-family:monospace;font-size:12px;color:#fca5a5;word-break:break-all'>" . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<h4 style='margin-top:20px;color:#38bdf8'>How to fix in cPanel:</h4>";
            echo "<ol style='font-size:13px;line-height:1.6;color:#cbd5e1'>";
            echo "<li>Open <strong>cPanel &rarr; MySQL Databases</strong>.</li>";
            echo "<li>Check that database <code>" . htmlspecialchars($CONFIG['db_name']) . "</code> exists.</li>";
            echo "<li>Check that user <code>" . htmlspecialchars($CONFIG['db_user']) . "</code> is added to <code>" . htmlspecialchars($CONFIG['db_name']) . "</code> with <strong>ALL PRIVILEGES</strong>.</li>";
            echo "<li>Open <strong>phpMyAdmin</strong> and import <code>database_setup.sql</code>.</li>";
            echo "</ol>";
            echo "</div></body></html>";
            exit;
        }
    }
    return $pdo;
}

function get_db(): PDO {
    return db();
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function setting(string $k, $default = null) {
    $v = q('SELECT v FROM settings WHERE k=?', [$k])->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $k, string $v): void {
    q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Convert ISO-8601 / epoch-ms from the phone to local MySQL DATETIME. */
function to_dt($v): string {
    if (is_numeric($v)) return date('Y-m-d H:i:s', (int)($v / 1000));
    $t = strtotime((string)$v);
    return date('Y-m-d H:i:s', $t ?: time());
}

/** Check if coordinates are inside or near an active Geofence */
function find_nearest_geofence(?float $lat, ?float $lng): ?string {
    if ($lat === null || $lng === null || $lat == 0.0 || $lng == 0.0) return null;
    
    static $parsedGeofences = null;
    try {
        if ($parsedGeofences === null) {
            $parsedGeofences = [];
            $raw = q("SELECT id, name, coordinates FROM geofences WHERE active=1")->fetchAll();
            foreach ($raw as $g) {
                $coords = json_decode($g['coordinates'] ?? '[]', true);
                if (!is_array($coords) || count($coords) < 3) continue;

                $minLat = INF; $maxLat = -INF;
                $minLng = INF; $maxLng = -INF;
                foreach ($coords as $c) {
                    $cLat = (float)$c[0]; $cLng = (float)$c[1];
                    if ($cLat < $minLat) $minLat = $cLat;
                    if ($cLat > $maxLat) $maxLat = $cLat;
                    if ($cLng < $minLng) $minLng = $cLng;
                    if ($cLng > $maxLng) $maxLng = $cLng;
                }

                $parsedGeofences[] = [
                    'id' => $g['id'],
                    'name' => $g['name'],
                    'coords' => $coords,
                    'minLat' => $minLat,
                    'maxLat' => $maxLat,
                    'minLng' => $minLng,
                    'maxLng' => $maxLng,
                ];
            }
        }

        if (empty($parsedGeofences)) return null;

        $closestName = null;
        $minDist = INF;

        foreach ($parsedGeofences as $g) {
            // Spatial Pre-Filter: Skip plots outside 2.5km bounding box (~0.023 degrees)
            if ($lat < ($g['minLat'] - 0.025) || $lat > ($g['maxLat'] + 0.025) ||
                $lng < ($g['minLng'] - 0.025) || $lng > ($g['maxLng'] + 0.025)) {
                continue;
            }

            $coords = $g['coords'];
            $n = count($coords);

            // Point-in-polygon ray casting algorithm
            $inside = false;
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $xi = (float)$coords[$i][0]; $yi = (float)$coords[$i][1];
                $xj = (float)$coords[$j][0]; $yj = (float)$coords[$j][1];
                $intersect = (($yi > $lng) !== ($yj > $lng)) &&
                             ($lat < ($xj - xi) * ($lng - $yi) / (($yj - $yi) ?: 0.00000001) + $xi);
                if ($intersect) $inside = !$inside;
            }

            if ($inside) {
                return "Inside " . $g['name'];
            }

            // Calculate minimum distance to polygon boundary line segments (True Edge Distance)
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $aLat = (float)$coords[$j][0]; $aLng = (float)$coords[$j][1];
                $bLat = (float)$coords[$i][0]; $bLng = (float)$coords[$i][1];

                $dLat = $bLat - $aLat;
                $dLng = $bLng - $aLng;
                if ($dLat == 0.0 && $dLng == 0.0) {
                    $pLat = $aLat; $pLng = $aLng;
                } else {
                    $t = ((($lat - $aLat) * $dLat) + (($lng - $aLng) * $dLng)) / (($dLat * $dLat) + ($dLng * $dLng));
                    $t = max(0.0, min(1.0, $t));
                    $pLat = $aLat + ($t * $dLat);
                    $pLng = $aLng + ($t * $dLng);
                }

                $d1 = deg2rad($lat - $pLat);
                $d2 = deg2rad($lng - $pLng);
                $val = sin($d1 / 2) ** 2 + cos(deg2rad($pLat)) * cos(deg2rad($lat)) * sin($d2 / 2) ** 2;
                $dist = 6371000 * 2 * atan2(sqrt($val), sqrt(1 - $val));

                if ($dist < $minDist) {
                    $minDist = $dist;
                    $closestName = $g['name'];
                }
            }
        }

        if ($closestName !== null) {
            if ($minDist < 1000) {
                return "Near " . $closestName . " (" . round($minDist) . "m)";
            } else {
                return "Near " . $closestName . " (" . round($minDist / 1000, 1) . "km)";
            }
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * Inserts a field photo record with activity type, auto geofence, and manual selection.
 */
function insert_field_photo(
    int $userId, 
    string $filePath, 
    ?float $lat, 
    ?float $lng, 
    ?string $note, 
    ?string $geofence, 
    string $dt,
    ?string $activityType = 'General',
    ?string $autoGeofence = null,
    ?string $selectedGeofence = null
): void {
    $auto = $autoGeofence ?: $geofence;
    $selected = $selectedGeofence ?: ($geofence ?: $auto);
    $act = $activityType ?: 'General';

    try {
        q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, activity_type, auto_geofence, selected_geofence, taken_at) 
           VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$userId, $filePath, $lat, $lng, $note, $selected ?: $auto, $act, $auto, $selected, $dt]);
    } catch (Throwable $e) {
        // Fallback with column migrations
        try {
            q("ALTER TABLE field_photos ADD COLUMN activity_type VARCHAR(64) NULL DEFAULT 'General' AFTER geofence_name");
            q("ALTER TABLE field_photos ADD COLUMN auto_geofence VARCHAR(128) NULL AFTER activity_type");
            q("ALTER TABLE field_photos ADD COLUMN selected_geofence VARCHAR(128) NULL AFTER auto_geofence");
            q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, activity_type, auto_geofence, selected_geofence, taken_at) 
               VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$userId, $filePath, $lat, $lng, $note, $selected ?: $auto, $act, $auto, $selected, $dt]);
        } catch (Throwable $e2) {
            try {
                q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, taken_at) VALUES (?,?,?,?,?,?,?)',
                  [$userId, $filePath, $lat, $lng, $note, $selected ?: $geofence, $dt]);
            } catch (Throwable $e3) {
                try {
                    q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, taken_at) VALUES (?,?,?,?,?,?)',
                      [$userId, $filePath, $lat, $lng, $note, $dt]);
                } catch (Throwable $e4) {}
            }
        }
    }
}

// Global schema initialization & migrations
try {
    // 1. Departments table
    q("CREATE TABLE IF NOT EXISTS `departments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(120) NOT NULL,
        `code` VARCHAR(50) NULL,
        `description` VARCHAR(255) NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_dept_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Sections table
    q("CREATE TABLE IF NOT EXISTS `sections` (
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

    // 3. Designations table
    q("CREATE TABLE IF NOT EXISTS `designations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(120) NOT NULL,
        `code` VARCHAR(50) NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_desig_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default departments if table is empty
    $deptCount = (int)q("SELECT COUNT(*) FROM departments")->fetchColumn();
    if ($deptCount === 0) {
        q("INSERT IGNORE INTO `departments` (`id`, `name`, `code`, `description`) VALUES 
            (1, 'Agriculture', 'AGRI', 'Sugarcane Plantation & Agronomy Field Operations'),
            (2, 'Fleet & Transport', 'FLEET', 'Tractors, Harvesters, Infielders & Bowser Transport'),
            (3, 'Factory & Mill', 'MILL', 'Hingurana Sugar Processing Complex & Weighbridge'),
            (4, 'Irrigation & Drainage', 'IRRI', 'Canal Systems, Water Pumps & Drainage Division'),
            (5, 'Civil & Security', 'SEC', 'Estate Security, Gate Controls & Estate Roads')");
        
        q("INSERT IGNORE INTO `sections` (`id`, `department_id`, `name`, `code`) VALUES 
            (1, 1, 'Sector D (Sugarcane)', 'SEC-D'),
            (2, 1, 'Sector C (Sugarcane)', 'SEC-C'),
            (3, 1, 'Sector B (Sugarcane)', 'SEC-B'),
            (4, 1, 'Sector A (Sugarcane)', 'SEC-A'),
            (5, 2, 'Central Workshop & Garage', 'GARAGE'),
            (6, 2, 'Haulage / Transport Yard', 'HAUL'),
            (7, 3, 'Factory Complex & Yard', 'MILL-YD'),
            (8, 4, 'Main Canal Division', 'CANAL'),
            (9, 3, 'Milling', 'MILL-SEC'),
            (10, 3, 'Boiler', 'BLR')");

        q("INSERT IGNORE INTO `designations` (`id`, `name`, `code`) VALUES 
            (1, 'Field Officer', 'FO'),
            (2, 'Agronomist', 'AGRO'),
            (3, 'Tractor Operator', 'TRAC-OP'),
            (4, 'Harvester Operator', 'HARV-OP'),
            (5, 'Field Supervisor', 'SUP'),
            (6, 'Field Inspector', 'INSP'),
            (7, 'Mechanic / Technician', 'MECH'),
            (8, 'Field Staff', 'STAFF')");
    }

    // 4. Alter users table for department, section, designation
    try { q("ALTER TABLE `users` ADD COLUMN `department_id` INT NULL AFTER `role`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `users` ADD COLUMN `section_id` INT NULL AFTER `department_id`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `users` ADD COLUMN `designation_id` INT NULL AFTER `section_id`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `users` ADD COLUMN `designation` VARCHAR(100) NULL AFTER `designation_id`"); } catch (Throwable $e) {}

    // 5. Alter tasks table for department & section & geofence
    try { q("ALTER TABLE `tasks` ADD COLUMN `department_id` INT NULL AFTER `assigned_to`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `tasks` ADD COLUMN `section_id` INT NULL AFTER `department_id`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `tasks` ADD COLUMN `geofence_id` INT NULL AFTER `section_id`"); } catch (Throwable $e) {}

    // 6. Alter duty_logs for battery, event_type, note
    try { q("ALTER TABLE `duty_logs` ADD COLUMN `battery` TINYINT NULL AFTER `lng`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `duty_logs` ADD COLUMN `event_type` VARCHAR(50) NULL DEFAULT 'duty' AFTER `battery`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `duty_logs` ADD COLUMN `note` VARCHAR(255) NULL AFTER `event_type`"); } catch (Throwable $e) {}

    // 7. Field photos table and columns
    q("CREATE TABLE IF NOT EXISTS field_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        note TEXT NULL,
        geofence_name VARCHAR(128) NULL,
        activity_type VARCHAR(64) NULL DEFAULT 'General',
        auto_geofence VARCHAR(128) NULL,
        selected_geofence VARCHAR(128) NULL,
        taken_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { q("ALTER TABLE `field_photos` ADD COLUMN `geofence_name` VARCHAR(128) NULL AFTER `note`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `field_photos` ADD COLUMN `activity_type` VARCHAR(64) NULL DEFAULT 'General' AFTER `geofence_name`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `field_photos` ADD COLUMN `auto_geofence` VARCHAR(128) NULL AFTER `activity_type`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `field_photos` ADD COLUMN `selected_geofence` VARCHAR(128) NULL AFTER `auto_geofence`"); } catch (Throwable $e) {}
    try { q("ALTER TABLE `task_photos` ADD COLUMN `geofence_name` VARCHAR(128) NULL"); } catch (Throwable $e) {}

} catch (Throwable $e) {}

// Self-update deploy_update.php if needed
try {
    $duPath = __DIR__ . '/deploy_update.php';
    if (file_exists($duPath)) {
        $duContent = file_get_contents($duPath);
        if (strpos($duContent, "'deploy_update.php'") === false) {
            $latestDu = @file_get_contents('https://raw.githubusercontent.com/shashiexe/galoya-telematics/main/backend/deploy_update.php');
            if ($latestDu && strlen($latestDu) > 200) {
                file_put_contents($duPath, $latestDu);
            }
        }
    }
} catch (Throwable $e) {}


