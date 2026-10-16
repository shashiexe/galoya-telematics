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
    try {
        $geofences = q("SELECT id, name, coordinates FROM geofences WHERE active=1")->fetchAll();
        if (empty($geofences)) return null;

        $closestName = null;
        $minDist = INF;

        foreach ($geofences as $g) {
            $coords = json_decode($g['coordinates'], true);
            if (!$coords || !is_array($coords) || count($coords) < 3) continue;

            // Point-in-polygon ray casting algorithm
            $inside = false;
            $n = count($coords);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $xi = (float)$coords[$i][0]; $yi = (float)$coords[$i][1];
                $xj = (float)$coords[$j][0]; $yj = (float)$coords[$j][1];
                $intersect = (($yi > $lng) !== ($yj > $lng)) &&
                             ($lat < ($xj - $xi) * ($lng - $yi) / (($yj - $yi) ?: 0.00000001) + $xi);
                if ($intersect) $inside = !$inside;
            }

            if ($inside) {
                return "Inside " . $g['name'];
            }

            // Calculate distance to polygon centroid
            $sumLat = 0; $sumLng = 0;
            foreach ($coords as $c) {
                $sumLat += (float)$c[0];
                $sumLng += (float)$c[1];
            }
            $cLat = $sumLat / $n;
            $cLng = $sumLng / $n;

            $dLat = deg2rad($lat - $cLat);
            $dLng = deg2rad($lng - $cLng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($cLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
            $dist = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));

            if ($dist < $minDist) {
                $minDist = $dist;
                $closestName = $g['name'];
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
 * Inserts a field photo record with automatic table creation & column migration fallback.
 */
function insert_field_photo(int $userId, string $filePath, ?float $lat, ?float $lng, ?string $note, ?string $geofence, string $dt): void {
    try {
        q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, taken_at) VALUES (?,?,?,?,?,?,?)',
          [$userId, $filePath, $lat, $lng, $note, $geofence, $dt]);
    } catch (Throwable $e) {
        // If table doesn't exist, create it immediately
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Base table or view not found") !== false) {
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
                q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, taken_at) VALUES (?,?,?,?,?,?,?)',
                  [$userId, $filePath, $lat, $lng, $note, $geofence, $dt]);
                return;
            } catch (Throwable $e2) {}
        }

        try {
            q("ALTER TABLE field_photos ADD COLUMN geofence_name VARCHAR(128) NULL AFTER note");
            q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, geofence_name, taken_at) VALUES (?,?,?,?,?,?,?)',
              [$userId, $filePath, $lat, $lng, $note, $geofence, $dt]);
        } catch (Throwable $e3) {
            try {
                q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, taken_at) VALUES (?,?,?,?,?,?)',
                  [$userId, $filePath, $lat, $lng, $note, $dt]);
            } catch (Throwable $e4) {}
        }
    }
}

// Ensure field_photos table exists globally
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
} catch (Throwable $e) {}

try {
    q("ALTER TABLE task_photos ADD COLUMN geofence_name VARCHAR(128) NULL");
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

