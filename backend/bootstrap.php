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
