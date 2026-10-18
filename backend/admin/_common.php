<?php
// Admin panel: session auth + Wialon Telematics page layout.
require_once __DIR__ . '/../bootstrap.php';
session_name('stafftrack_admin');
session_start();

function admin(): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    return q("SELECT * FROM users WHERE id=? AND role='admin' AND active=1", [$_SESSION['admin_id']])->fetch() ?: null;
}

function require_admin(): array {
    $a = admin();
    if (!$a) { header('Location: login.php'); exit; }
    return $a;
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

    $nav = [
        'index.php'         => ['Monitoring', '🛰️'],
        'units.php'         => ['Fleet', '🚜'],
        'staff.php'         => ['Staff & Depts', '👥'],
        'photos.php'        => ['Photos', '📷'],
        'history.php'       => ['Tracks', '↝'],
        'messages.php'      => ['Messages', '💬'],
        'reports.php'       => ['Reports', '📋'],
        'geofences.php'     => ['Geofences', '⬡'],
        'drivers.php'       => ['Drivers', '👨‍✈️'],
        'notifications.php' => ['Notifications', '🔔', $unread_notifs > 0 ? $unread_notifs : null],
        'dashboard.php'     => ['Dashboard', '📊'],
        'tasks.php'         => ['Tasks', '✓', $task_count > 0 ? $task_count : null],
        'settings.php'      => ['Settings', '⚙️'],
    ];
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · Galoya Telematics</title>
  <meta name="description" content="Wialon-style Telematics & Staff Tracking Platform">
  <link rel="stylesheet" href="assets/style.css">
  <?= $map ? map_assets() : '' ?>
</head>
<body>
  <!-- Top Telematics Navigation Bar (Wialon Style) -->
  <header class="w-topbar">
    <div class="w-brand">
      <div class="globe-icon">🌐</div>
      <div>
        <span style="color:#29b6f6">GALOYA</span> <span style="font-weight:400;color:#cfd8dc">TELEMATICS</span>
      </div>
    </div>

    <nav class="w-nav">
      <?php foreach ($nav as $file => $item): 
        $label = $item[0];
        $icon  = $item[1];
        $badge = $item[2] ?? null;
        $isActive = ($active === $file);
      ?>
        <a href="<?= $file ?>" class="w-tab <?= $isActive ? 'active' : '' ?>">
          <span><?= $icon ?></span>
          <span><?= h($label) ?></span>
          <?php if ($badge !== null): ?>
            <span class="badge" style="<?= $file === 'notifications.php' ? 'background:#ef4444;color:#fff' : '' ?>"><?= $badge ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="w-user">
      <a href="../Galoya-Telematics.apk" download class="w-tab" style="background:#059669;color:#fff;border-radius:4px;padding:3px 8px;height:26px;margin-right:6px;font-weight:700;font-size:11.5px;text-decoration:none;display:inline-flex;align-items:center;gap:4px" title="Download Official Galoya Telematics Android APK">📥 Download APK</a>
      <a href="../app/" target="_blank" class="w-tab" style="background:#0284c7;color:#fff;border-radius:4px;padding:3px 8px;height:26px;margin-right:6px;font-weight:700;font-size:11.5px" title="Test Mobile Phone App on Laptop">📱 App</a>
      <div class="user-badge" style="padding:2px 8px;font-size:11.5px">
        <span>👤</span>
        <span><?= h($a['name'] ?? 'galoyap.admin') ?></span>
      </div>
      <a href="logout.php" class="logout-link" style="padding:2px 8px;font-size:11.5px">Logout</a>
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
      <span title="Telematics Engine">Wialon Core (Galoya Telematics)</span>
    </div>
    <div style="font-size:11px;color:#64748b">
      © Galoya Telematics · Hingurana Fleet Operations
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
