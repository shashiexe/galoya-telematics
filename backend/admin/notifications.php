<?php
require __DIR__ . '/_common.php';
$me = require_admin();

// Handle Actions (Acknowledge, Mark All Read, Trigger Test Alarm)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'read_one') {
            $id = (int)$_POST['id'];
            q("UPDATE notifications SET is_read = 1 WHERE id = ?", [$id]);
            flash("Alarm acknowledged");
        } elseif ($act === 'read_all') {
            q("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
            flash("All alarms marked as read");
        } elseif ($act === 'delete_all_read') {
            q("DELETE FROM notifications WHERE is_read = 1");
            flash("Cleared acknowledged notifications history");
        } elseif ($act === 'trigger_test') {
            $test_type = $_POST['test_type'] ?? 'speed';
            $unit_id = (int)($_POST['unit_id'] ?? 2);
            $u = q("SELECT name FROM users WHERE id = ?", [$unit_id])->fetch();
            $unitName = $u['name'] ?? 'Unit 49-1665';

            $titles = [
                'speed' => 'Overspeeding Alert Triggered',
                'geofence' => 'Geofence Boundary Violation',
                'sos' => '🆘 SOS Panic Button Dispatched',
                'fuel' => 'Fuel Drop / Theft Detection',
                'battery' => 'Low Battery Warning (< 15%)',
                'idle' => 'Excessive Idling Detected (> 25 mins)'
            ];

            $messages = [
                'speed' => "{$unitName} exceeded maximum speed threshold (52.8 km/h recorded on Field Road 4)",
                'geofence' => "{$unitName} departed assigned geofence zone D/207/01 without dispatch clearance",
                'sos' => "Emergency assistance requested by operator on {$unitName} near Sector D/208",
                'fuel' => "Rapid fuel level drop (-18 Liters in 5 min) detected on {$unitName}",
                'battery' => "Internal backup battery voltage low (11.2V) on {$unitName}",
                'idle' => "{$unitName} engine running stationary for 28 minutes near Hingurana weighbridge"
            ];

            $lat = 7.2095 + (rand(-100, 100) / 10000);
            $lng = 81.6575 + (rand(-100, 100) / 10000);

            q("INSERT INTO notifications (unit_id, type, title, message, lat, lng, is_read) VALUES (?,?,?,?,?,?,0)",
              [$unit_id, $test_type, $titles[$test_type] ?? 'Telematics Alert', $messages[$test_type] ?? 'Alert event', $lat, $lng]);
            
            flash("Dispatched test alarm: " . ($titles[$test_type] ?? 'Alert'));
        }
    } catch (Throwable $e) {
        flash("Error: " . $e->getMessage());
    }

    header('Location: notifications.php' . (!empty($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit;
}

// Fetch Units for test dispatch
$units = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();

// Filter Notifications
$type_filter = $_GET['type'] ?? 'all';
$read_filter = $_GET['read'] ?? 'all';

$sql = "SELECT n.*, u.name as unit_name, u.code as unit_code, u.unit_type,
        (SELECT name FROM drivers WHERE assigned_unit_id = u.id LIMIT 1) as driver_name
        FROM notifications n
        LEFT JOIN users u ON n.unit_id = u.id
        WHERE 1=1";
$params = [];

if ($type_filter !== 'all') {
    $sql .= " AND n.type = ?";
    $params[] = $type_filter;
}

if ($read_filter === 'unread') {
    $sql .= " AND n.is_read = 0";
} elseif ($read_filter === 'read') {
    $sql .= " AND n.is_read = 1";
}

$sql .= " ORDER BY n.created_at DESC LIMIT 100";
$notifications = q($sql, $params)->fetchAll();

// Counts
$total_unread = (int)q("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$count_speed = (int)q("SELECT COUNT(*) FROM notifications WHERE type = 'speed'")->fetchColumn();
$count_geofence = (int)q("SELECT COUNT(*) FROM notifications WHERE type = 'geofence'")->fetchColumn();
$count_sos = (int)q("SELECT COUNT(*) FROM notifications WHERE type = 'sos'")->fetchColumn();
$count_fuel = (int)q("SELECT COUNT(*) FROM notifications WHERE type = 'fuel'")->fetchColumn();
$count_battery = (int)q("SELECT COUNT(*) FROM notifications WHERE type = 'battery'")->fetchColumn();

layout_start('Notifications & Alarms', 'notifications.php');
?>

<div style="padding:16px 20px">
  <!-- Wialon Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px;color:#263238">
        <span>🔔</span> <span>Telematics Notifications & Alarms Center</span>
        <?php if ($total_unread > 0): ?>
          <span style="background:#ef4444;color:#fff;font-size:12px;padding:2px 8px;border-radius:12px;font-weight:700">
            <?= $total_unread ?> Unread
          </span>
        <?php endif; ?>
      </h2>
      <div style="font-size:12px;color:#78909c;margin-top:2px">
        Real-time monitoring of geofence incursions, speeding violations, fuel drops, and emergency SOS signals
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($total_unread > 0): ?>
        <form method="post" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="read_all">
          <button type="submit" class="btn sm" style="background:#059669;display:flex;align-items:center;gap:6px">
            <span>✓</span> <span>Acknowledge All</span>
          </button>
        </form>
      <?php endif; ?>
      <button class="btn ghost sm" onclick="document.getElementById('test-modal').style.display='flex'" style="display:flex;align-items:center;gap:6px">
        <span>⚡</span> <span>Trigger Test Alarm</span>
      </button>
      <form method="post" style="margin:0" onsubmit="return confirm('Clear all acknowledged history?')">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="delete_all_read">
        <button type="submit" class="btn ghost sm" style="color:#94a3b8">Clear Old Read</button>
      </form>
    </div>
  </div>

  <!-- Metric Quick Filters -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:10px;margin-bottom:16px">
    <a href="notifications.php?type=all" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #0284c7;<?= $type_filter === 'all' ? 'box-shadow:0 0 0 2px #0284c7' : '' ?>">
        <div style="font-size:11px;color:#64748b;font-weight:600">ALL ALARMS</div>
        <div style="font-size:20px;font-weight:700;color:#0f172a;margin-top:2px"><?= count($notifications) ?></div>
      </div>
    </a>
    <a href="notifications.php?type=sos" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #dc2626;<?= $type_filter === 'sos' ? 'box-shadow:0 0 0 2px #dc2626' : '' ?>">
        <div style="font-size:11px;color:#dc2626;font-weight:700">🆘 SOS PANIC</div>
        <div style="font-size:20px;font-weight:700;color:#dc2626;margin-top:2px"><?= $count_sos ?></div>
      </div>
    </a>
    <a href="notifications.php?type=speed" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #ea580c;<?= $type_filter === 'speed' ? 'box-shadow:0 0 0 2px #ea580c' : '' ?>">
        <div style="font-size:11px;color:#ea580c;font-weight:700">⚡ SPEEDING</div>
        <div style="font-size:20px;font-weight:700;color:#ea580c;margin-top:2px"><?= $count_speed ?></div>
      </div>
    </a>
    <a href="notifications.php?type=geofence" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #7c3aed;<?= $type_filter === 'geofence' ? 'box-shadow:0 0 0 2px #7c3aed' : '' ?>">
        <div style="font-size:11px;color:#7c3aed;font-weight:700">🛑 GEOFENCE</div>
        <div style="font-size:20px;font-weight:700;color:#7c3aed;margin-top:2px"><?= $count_geofence ?></div>
      </div>
    </a>
    <a href="notifications.php?type=fuel" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #059669;<?= $type_filter === 'fuel' ? 'box-shadow:0 0 0 2px #059669' : '' ?>">
        <div style="font-size:11px;color:#059669;font-weight:700">⛽ FUEL THEFT/FILL</div>
        <div style="font-size:20px;font-weight:700;color:#059669;margin-top:2px"><?= $count_fuel ?></div>
      </div>
    </a>
    <a href="notifications.php?type=battery" style="text-decoration:none">
      <div class="card" style="padding:10px 14px;background:#fff;border-left:4px solid #d97706;<?= $type_filter === 'battery' ? 'box-shadow:0 0 0 2px #d97706' : '' ?>">
        <div style="font-size:11px;color:#d97706;font-weight:700">🔋 LOW BATTERY</div>
        <div style="font-size:20px;font-weight:700;color:#d97706;margin-top:2px"><?= $count_battery ?></div>
      </div>
    </a>
  </div>

  <!-- Notification Feed Table -->
  <div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;text-align:left;color:#475569">
          <th style="padding:10px 14px;width:30px">Status</th>
          <th style="padding:10px 14px;width:150px">Timestamp</th>
          <th style="padding:10px 14px;width:160px">Alarm Type</th>
          <th style="padding:10px 14px;width:180px">Unit / Machine</th>
          <th style="padding:10px 14px;width:150px">Operator</th>
          <th style="padding:10px 14px">Alarm Message & Telemetry Context</th>
          <th style="padding:10px 14px;width:110px">Coordinates</th>
          <th style="padding:10px 14px;text-align:right;width:100px">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($notifications)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:36px;color:#94a3b8">
              No alarms recorded under this filter. System operating normally.
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($notifications as $n): 
          $isUnread = !$n['is_read'];
          $typeConfig = match($n['type']) {
              'sos' => ['color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '🆘', 'label' => 'SOS Panic'],
              'speed' => ['color' => '#ea580c', 'bg' => '#ffedd5', 'icon' => '⚡', 'label' => 'Overspeeding'],
              'geofence' => ['color' => '#7c3aed', 'bg' => '#f3e8ff', 'icon' => '🛑', 'label' => 'Geofence In/Out'],
              'fuel' => ['color' => '#059669', 'bg' => '#d1fae5', 'icon' => '⛽', 'label' => 'Fuel Refuel/Drop'],
              'battery' => ['color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '🔋', 'label' => 'Low Battery'],
              'idle' => ['color' => '#ca8a04', 'bg' => '#fef08a', 'icon' => '⏳', 'label' => 'Excess Idling'],
              default => ['color' => '#0284c7', 'bg' => '#e0f2fe', 'icon' => '🔔', 'label' => ucfirst($n['type'])]
          };
        ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $isUnread ? 'background:#fff9f9;font-weight:600;' : 'background:#fff;' ?>">
            <td style="padding:10px 14px;text-align:center">
              <?php if ($isUnread): ?>
                <span title="Unread Alarm" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;box-shadow:0 0 6px rgba(239,68,68,0.7)"></span>
              <?php else: ?>
                <span title="Acknowledged" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#cbd5e1"></span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;color:#64748b;font-family:monospace;font-size:11.5px;white-space:nowrap">
              <?= date('Y-m-d H:i:s', strtotime($n['created_at'])) ?>
            </td>
            <td style="padding:10px 14px">
              <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $typeConfig['bg'] ?>;color:<?= $typeConfig['color'] ?>;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700">
                <span><?= $typeConfig['icon'] ?></span>
                <span><?= $typeConfig['label'] ?></span>
              </span>
            </td>
            <td style="padding:10px 14px">
              <a href="index.php?focus=<?= $n['unit_id'] ?>" style="color:#0284c7;font-weight:700;display:flex;align-items:center;gap:5px">
                <span>🚜</span> <span><?= h($n['unit_name'] ?: 'Unit #' . $n['unit_id']) ?></span>
              </a>
            </td>
            <td style="padding:10px 14px;color:#334155">
              <?= h($n['driver_name'] ?: '—') ?>
            </td>
            <td style="padding:10px 14px">
              <div style="color:#1e293b;font-size:12.5px"><?= h($n['title']) ?></div>
              <div style="color:#64748b;font-size:11.5px;margin-top:2px;font-weight:400"><?= h($n['message']) ?></div>
            </td>
            <td style="padding:10px 14px;white-space:nowrap">
              <?php if ($n['lat'] && $n['lng']): ?>
                <a href="https://www.google.com/maps?q=<?= $n['lat'] ?>,<?= $n['lng'] ?>" target="_blank" style="font-family:monospace;font-size:11px;color:#0284c7;text-decoration:none;display:inline-flex;align-items:center;gap:3px">
                  <span>📍</span> <?= round($n['lat'], 4) ?>, <?= round($n['lng'], 4) ?>
                </a>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">—</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:right">
              <?php if ($isUnread): ?>
                <form method="post" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="read_one">
                  <input type="hidden" name="id" value="<?= $n['id'] ?>">
                  <button class="btn sm" style="padding:3px 8px;font-size:11px;background:#059669">Ack</button>
                </form>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">Ack'd</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Test Alarm Trigger Modal -->
<div id="test-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:440px;max-width:95vw;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden">
    <div style="background:#1e293b;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px">
        <span>⚡</span> <span>Trigger Test Telematics Alarm</span>
      </div>
      <button onclick="document.getElementById('test-modal').style.display='none'" style="background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer">&times;</button>
    </div>
    
    <form method="post" style="padding:16px;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="trigger_test">

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Select Machinery / Unit *</label>
        <select name="unit_id" required style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>">🚜 <?= h($u['name']) ?> (<?= h($u['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Alarm Event Type *</label>
        <select name="test_type" required style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
          <option value="sos">🆘 SOS Emergency Panic Alarm</option>
          <option value="speed">⚡ Overspeeding Violation (> 40 km/h)</option>
          <option value="geofence">🛑 Geofence Exit (Sector D/207/01)</option>
          <option value="fuel">⛽ Fuel Rapid Drop / Drain Detection</option>
          <option value="battery">🔋 Low Battery Voltage (< 15%)</option>
          <option value="idle">⏳ Excessive Engine Idling (> 20 mins)</option>
        </select>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0">
        <button type="button" class="btn ghost" onclick="document.getElementById('test-modal').style.display='none'">Cancel</button>
        <button type="submit" class="btn" style="background:#dc2626">Dispatch Alarm</button>
      </div>
    </form>
  </div>
</div>

<?php layout_end(); ?>
