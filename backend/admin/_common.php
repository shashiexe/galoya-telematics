<?php
// Admin panel: session auth + Wialon Telematics page layout.
require_once __DIR__ . '/../bootstrap.php';
session_name('stafftrack_admin');
session_start();

function ensure_web_user_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = db();
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'web_access'")->fetchAll();
        if (empty($cols)) {
            @$pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff'");
            @$pdo->exec("ALTER TABLE users ADD COLUMN web_access TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
            @$pdo->exec("ALTER TABLE users ADD COLUMN permissions_json LONGTEXT NULL");
            @$pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL");
            @$pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL");
            @$pdo->exec("UPDATE users SET web_access=1 WHERE role='admin'");
        }
        @$pdo->exec("ALTER TABLE users ADD COLUMN section_ids VARCHAR(255) NULL AFTER section_id");
    } catch (Throwable $e) {}
}

function admin(): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    ensure_web_user_columns();
    return q("SELECT * FROM users WHERE id=? AND active=1 AND (role='admin' OR web_access=1)", [$_SESSION['admin_id']])->fetch() ?: null;
}

function require_admin(): array {
    $a = admin();
    if (!$a) { header('Location: login.php'); exit; }
    return $a;
}

function is_admin(): bool {
    $u = admin();
    return $u && ($u['role'] ?? '') === 'admin';
}

function user_permissions(): array {
    $u = admin();
    if (!$u) return [];
    if (($u['role'] ?? '') === 'admin') return ['*' => true];

    if (isset($_SESSION['admin_perms']) && is_array($_SESSION['admin_perms'])) {
        return $_SESSION['admin_perms'];
    }

    $perms = !empty($u['permissions_json']) ? json_decode($u['permissions_json'], true) : [];
    $_SESSION['admin_perms'] = is_array($perms) ? $perms : [];
    return $_SESSION['admin_perms'];
}

function can(string $module, string $action = 'view'): bool {
    $u = admin();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'admin') return true;

    $perms = user_permissions();
    if (!empty($perms['*'])) return true;
    if (empty($perms[$module])) return false;
    if ($perms[$module] === true || !empty($perms[$module]['*']) || !empty($perms[$module]['all'])) return true;
    return !empty($perms[$module][$action]);
}

/**
 * Returns array of section IDs accessible to the current user, or NULL if global access.
 */
function user_accessible_section_ids(): ?array {
    $u = admin();
    if (!$u) return [];
    if (($u['role'] ?? '') === 'admin') return null;

    $rawIds = trim((string)($u['section_ids'] ?? ''));
    if ($rawIds !== '') {
        $parts = array_map('intval', explode(',', $rawIds));
        $parts = array_values(array_filter($parts, fn($id) => $id > 0));
        if (!empty($parts)) return $parts;
    }

    if (!empty($u['section_id'])) {
        return [(int)$u['section_id']];
    }

    return null; // Global access
}

/**
 * Returns assigned department ID if user is scoped to a specific department, or NULL if global.
 */
function user_accessible_department_id(): ?int {
    $u = admin();
    if (!$u) return null;
    if (($u['role'] ?? '') === 'admin') return null;
    return !empty($u['department_id']) ? (int)$u['department_id'] : null;
}

/**
 * Returns SQL condition for filtering records by user's assigned sections/department.
 * Example outputs: "users.section_id IN (1,2)", "users.department_id = 3", or "1=1"
 */
function user_section_scope_sql(string $alias = 'users'): string {
    $secIds = user_accessible_section_ids();
    if ($secIds !== null) {
        if (empty($secIds)) return "1=0"; // Restricted but has no sections assigned
        $in = implode(',', $secIds);
        return "({$alias}.section_id IN ($in))";
    }

    $deptId = user_accessible_department_id();
    if ($deptId !== null) {
        return "({$alias}.department_id = $deptId)";
    }

    return "1=1";
}

function require_perm(string $module, string $action = 'view'): void {
    if (!can($module, $action)) {
        http_response_code(403);
        ?>
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <title>403 Access Restricted · GalOya Track360</title>
          <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 90vh; background: #0f172a; color: #f8fafc; margin: 0; }
            .box { background: #1e293b; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); max-width: 440px; text-align: center; border: 1px solid #334155; }
            .badge { display: inline-block; padding: 4px 10px; background: rgba(239, 68, 68, 0.2); color: #f87171; border-radius: 6px; font-weight: 700; font-size: 11px; margin-bottom: 12px; }
            h2 { margin: 0 0 10px; color: #f87171; font-size: 20px; }
            p { color: #94a3b8; font-size: 13.5px; line-height: 1.6; margin-bottom: 24px; }
            .btn { display: inline-block; padding: 10px 24px; background: #10b981; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 13px; }
          </style>
        </head>
        <body>
          <div class="box">
            <div style="font-size: 48px; margin-bottom: 12px">🛡️</div>
            <div class="badge">PERMISSION RESTRICTED</div>
            <h2>Access Restricted</h2>
            <p>Your user account does not have permission to access <strong><?= htmlspecialchars(strtoupper($module)) ?> (<?= htmlspecialchars($action) ?>)</strong>.<br>Please contact a GalOya Super Administrator to request permission.</p>
            <a href="dashboard.php" class="btn">Return to Dashboard</a>
          </div>
        </body>
        </html>
        <?php
        exit;
    }
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }

function check_csrf(): void {
    if (!hash_equals(csrf(), $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid request'); }
}

function flash(?string $msg = null) {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}

function photo_url(?string $p): string { 
    global $CONFIG; 
    return $p ? '../' . $CONFIG['upload_url'] . '/' . $p : ''; 
}

function map_assets(): string {
    global $CONFIG;
    if ($CONFIG['map_provider'] === 'google' && !empty($CONFIG['google_maps_key'])) {
        return '<script>window.MAP_PROVIDER="google"</script>'
             . '<script src="https://maps.googleapis.com/maps/api/js?key=' . h($CONFIG['google_maps_key']) . '"></script>'
             . '<script src="assets/app.js"></script>';
    }
    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
         . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>'
         . '<script>window.MAP_PROVIDER="osm"</script>'
         . '<script src="assets/app.js"></script>';
}

function layout_start(string $title, string $active, bool $map = false): void {
    $a = require_admin();
    
    // Count active tasks for badge
    $task_count = 0;
    try {
        $task_count = (int)q("SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress')")->fetchColumn();
    } catch (Throwable $e) {}

    // Count unread notifications
    $unread_notifs = 0;
    try {
        $unread_notifs = (int)q("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
    } catch (Throwable $e) {}

    // Count online units (transmitting within timeout)
    global $CONFIG;
    $off_min = (int)($CONFIG['offline_after_min'] ?? 10);
    $online_units = 0;
    $total_units = 0;
    try {
        $total_units = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1")->fetchColumn();
        $online_units = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1 AND on_duty=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$off_min])->fetchColumn();
    } catch (Throwable $e) {}

    // Full Telematics Nav with Granular RBAC Permission Filtering
    $nav_items = [
        'index.php'         => ['Monitoring', '🛰️', null, 'monitoring'],
        'dashboard.php'     => ['Dashboard', '📊', null, 'dashboard'],
        'history.php'       => ['Tracks', '↝', null, 'history'],
        'photos.php'        => ['Photos', '📷', null, 'photos'],
        'units.php'         => ['Fleet', '🚜', null, 'units'],
        'geofences.php'     => ['Geofences', '⬡', null, 'geofences'],
        'reports.php'       => ['Reports', '📋', null, 'reports'],
        'tasks.php'         => ['Tasks', '✓', $task_count > 0 ? $task_count : null, 'tasks'],
        'drivers.php'       => ['Drivers', '👨‍✈️', null, 'drivers'],
        'messages.php'      => ['Messages', '💬', null, 'messages'],
        'notifications.php' => ['Alarms', '🔔', $unread_notifs > 0 ? $unread_notifs : null, 'notifications'],
        'staff.php'         => ['Staff', '👥', null, 'staff'],
        'users.php'         => ['Users', '👤', null, 'users'],
        'settings.php'      => ['Settings', '⚙️', null, 'settings'],
    ];
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · GalOya Track360</title>
  <meta name="description" content="GalOya Track360 · People • Field • Fleet Telematics Platform">
  <link rel="icon" type="image/png" href="assets/favicon.png">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/ai_widget.css">
  <?= $map ? map_assets() : '' ?>
</head>
<body>
  <!-- Top Telematics Navigation Bar (Wialon Style) -->
  <header class="w-topbar">
    <div class="w-brand" style="display:flex;align-items:center;gap:10px;padding:0 8px">
      <a href="index.php" style="display:flex;align-items:center;gap:10px;text-decoration:none">
        <img src="assets/logo.png" alt="GalOya Track360" style="height:32px;width:32px;border-radius:6px;object-fit:contain;background:#ffffff;padding:2px;box-shadow:0 1px 3px rgba(0,0,0,0.3)">
        <div style="line-height:1.15">
          <div style="font-weight:900;font-size:14px;letter-spacing:0.2px">
            <span style="color:#22c55e">GalOya</span> <span style="color:#f8fafc">Track</span><span style="color:#eab308;font-weight:900">360</span>
          </div>
          <div style="font-size:9px;color:#94a3b8;font-weight:600;letter-spacing:0.4px">People • Field • Fleet</div>
        </div>
      </a>
    </div>

    <nav class="w-nav">
      <?php foreach ($nav_items as $file => $item): 
        $module = $item[3] ?? '';
        if ($module && !can($module, 'view')) continue;
        $label = $item[0];
        $icon  = $item[1];
        $badge = $item[2] ?? null;
        $isActive = ($active === $file);
      ?>
        <a href="<?= $file ?>" class="w-tab <?= $isActive ? 'active' : '' ?>">
          <span><?= $icon ?></span>
          <span><?= h($label) ?></span>
          <?php if ($badge !== null): ?>
            <span class="badge" style="<?= $file === 'notifications.php' ? 'background:#ef4444;color:#fff' : 'background:#2563eb;color:#fff' ?>"><?= $badge ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="w-user">
      <a href="../Galoya-Telematics.apk" download class="w-tab" style="background:#059669;color:#fff;border-radius:4px;padding:3px 8px;height:26px;margin-right:6px;font-weight:700;font-size:11px;text-decoration:none;display:inline-flex;align-items:center;gap:4px" title="Download Official Galoya Telematics Android APK">📥 APK</a>
      <a href="../app/" target="_blank" class="w-tab" style="background:#0284c7;color:#fff;border-radius:4px;padding:3px 8px;height:26px;margin-right:6px;font-weight:700;font-size:11px" title="Test Mobile Phone App on Laptop">📱 App</a>
      <div class="user-badge" style="padding:2px 8px;font-size:11px" title="Role: <?= h(ucfirst($a['role'] ?? 'user')) ?>">
        <span>👤</span>
        <span><?= h($a['name'] ?? 'galoyap.admin') ?></span>
        <span style="font-size:9.5px;opacity:0.85;background:rgba(255,255,255,0.22);padding:1px 5px;border-radius:3px;margin-left:4px"><?= strtoupper($a['role'] ?? 'USER') ?></span>
      </div>
      <a href="logout.php" class="logout-link" style="padding:2px 8px;font-size:11px">Logout</a>
    </div>
  </header>

  <!-- Flash Message -->
  <?php if ($m = flash()): ?>
    <div style="background:#e8f5e9;border-bottom:1px solid #c8e6c9;color:#2e7d32;padding:8px 16px;font-size:12px;font-weight:600">
      <?= h($m) ?>
    </div>
  <?php endif; ?>

  <main class="w-content">
<?php
}

function layout_end(): void {
    global $CONFIG;
    $off_min = (int)($CONFIG['offline_after_min'] ?? 10);
    $online_units = 0;
    $total_units = 0;
    $unread_notifs = 0;
    try {
        $total_units = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1")->fetchColumn();
        $online_units = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$off_min])->fetchColumn();
        $unread_notifs = (int)q("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
    } catch (Throwable $e) {}
    ?>
  </main>

  <!-- Wialon Bottom Status Bar -->
  <footer class="w-statusbar" style="display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:32px;background:#1e293b;color:#94a3b8;font-size:11.5px;border-top:1px solid #334155;z-index:900">
    <div class="w-statusbar-left" style="display:flex;align-items:center;gap:14px">
      <span style="display:inline-flex;align-items:center;gap:5px;color:<?= $online_units > 0 ? '#22c55e' : '#94a3b8' ?>;font-weight:600">
        <span id="w-online-dot" style="width:7px;height:7px;border-radius:50%;background:<?= $online_units > 0 ? '#22c55e' : '#94a3b8' ?>;box-shadow:<?= $online_units > 0 ? '0 0 6px #22c55e' : 'none' ?>;display:inline-block"></span>
        <span id="w-online-units-count"><?= $online_units ?> <?= $online_units === 1 ? 'Unit' : 'Units' ?> Online</span>
      </span>
      <span>•</span>
      <span style="color:#64748b"><?= $total_units ?> Fleet Total</span>
      <span>•</span>
      <a href="notifications.php" style="color:<?= $unread_notifs > 0 ? '#f87171' : '#94a3b8' ?>;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
        <span>🔔</span> <span><?= $unread_notifs ?> Alarms</span>
      </a>
      <span>•</span>
      <span title="Telematics Engine">GalOya Track360 (GOPL IT)</span>
    </div>
    <div style="font-size:11px;color:#64748b">
      © GalOya Track360 · Hingurana Field & Fleet Operations · <strong>Created by GOPL IT</strong>
    </div>
    <div class="w-statusbar-right" style="display:flex;align-items:center;gap:10px">
      <span id="w-live-clock" style="font-family:monospace;font-weight:700;color:#e2e8f0;background:#0f172a;padding:2px 8px;border-radius:4px;border:1px solid #334155">
        --:--:-- (+05:30)
      </span>
    </div>
  </footer>

  <script>
  function updateLiveClock() {
    var d = new Date();
    var utc = d.getTime() + (d.getTimezoneOffset() * 60000);
    var slTime = new Date(utc + (3600000 * 5.5));
    var h = String(slTime.getHours()).padStart(2, '0');
    var m = String(slTime.getMinutes()).padStart(2, '0');
    var s = String(slTime.getSeconds()).padStart(2, '0');
    var el = document.getElementById('w-live-clock');
    if (el) el.textContent = h + ':' + m + ':' + s + ' (UTC+05:30)';
  }
  setInterval(updateLiveClock, 1000);
  updateLiveClock();
  </script>

  <script src="assets/app.js"></script>
  <?php if (can('reports', 'view') || is_super_admin()): ?>
    <script src="assets/ai_widget.js"></script>
  <?php endif; ?>
</body>
</html>
<?php
}

function status_badge(string $s): string {
    $class = match($s) {
        'pending'     => 'w-badge-pending',
        'in_progress' => 'w-badge-progress',
        'done'        => 'w-badge-done',
        'cancelled'   => 'w-badge-cancelled',
        default       => ''
    };
    $label = match($s) {
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'done'        => 'Completed',
        'cancelled'   => 'Cancelled',
        default       => $s
    };
    return "<span class=\"w-badge $class\">$label</span>";
}
