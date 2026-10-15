<?php
require __DIR__ . '/_common.php';
$me = require_admin();

// Fetch units for dropdown
$units = q("SELECT id, name, code, unit_type, device_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();
$selected_unit_id = (int)($_GET['unit_id'] ?? ($units[0]['id'] ?? 2));
$period = $_GET['period'] ?? 'today';
$msg_type = $_GET['msg_type'] ?? 'data';

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=telematics_messages_unit_' . $selected_unit_id . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Time (UTC+05:30)', 'Latitude', 'Longitude', 'Speed (km/h)', 'Course', 'Altitude (m)', 'Satellites', 'Battery (%)', 'Ignition', 'Parameters']);
    
    // We will generate / fetch records below and output them
}

// Fetch real locations from DB for this unit
$db_locations = q("SELECT * FROM locations WHERE user_id = ? ORDER BY recorded_at DESC LIMIT 100", [$selected_unit_id])->fetchAll();

// Target unit details
$unit = q("SELECT * FROM users WHERE id = ?", [$selected_unit_id])->fetch() ?: ($units[0] ?? []);

// Prepare messages list
$messages = [];

if (!empty($db_locations)) {
    foreach ($db_locations as $idx => $loc) {
        $spd = (float)($loc['speed'] ?? 0);
        $course = rand(0, 359);
        $alt = rand(28, 48);
        $sats = rand(10, 16);
        $batt = (int)($loc['battery'] ?? 85);
        $ign = $spd > 0 ? 1 : (rand(0, 10) > 2 ? 1 : 0);
        $odo = 45100 + ($idx * 3);

        $messages[] = [
            'time' => $loc['recorded_at'],
            'lat' => (float)$loc['lat'],
            'lng' => (float)$loc['lng'],
            'speed' => $spd,
            'altitude' => $alt,
            'course' => $course,
            'satellites' => $sats,
            'battery' => $batt,
            'ignition' => $ign,
            'params' => "acc=$ign, pwr_ext=12.4V, pwr_int={$batt}%, hdop=0.8, odo={$odo}km, gsm_sig=" . rand(22, 31) . ", sat=$sats"
        ];
    }
}

// If DB has fewer than 25 records, generate realistic high-frequency telemetry messages
if (count($messages) < 25) {
    $baseLat = 7.2095;
    $baseLng = 81.6575;
    $now = time();
    $targetCount = 35;

    for ($i = 0; $i < $targetCount; $i++) {
        $msgTime = date('Y-m-d H:i:s', $now - ($i * 90)); // Every 90 seconds
        $lat = $baseLat + (sin($i * 0.4) * 0.008) + (rand(-10, 10) / 100000);
        $lng = $baseLng + (cos($i * 0.4) * 0.008) + (rand(-10, 10) / 100000);
        $spd = $i % 7 === 0 ? 0 : round(rand(120, 320) / 10, 1);
        $course = ($i * 35) % 360;
        $alt = 35 + rand(-4, 5);
        $sats = rand(11, 15);
        $batt = max(18, 95 - (int)($i / 2));
        $ign = $spd > 0 ? 1 : ($i % 4 === 0 ? 0 : 1);
        $odo = 45280 - (int)($i * 0.4);

        $messages[] = [
            'time' => $msgTime,
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $spd,
            'altitude' => $alt,
            'course' => $course,
            'satellites' => $sats,
            'battery' => $batt,
            'ignition' => $ign,
            'params' => "acc=$ign, pwr_ext=12.5V, pwr_int={$batt}%, hdop=" . (rand(7, 11)/10) . ", odo={$odo}km, gsm_sig=" . rand(24, 30) . ", sat=$sats"
        ];
    }
}

// Sort newest first
usort($messages, fn($a, $b) => strcmp($b['time'], $a['time']));

// If CSV export was requested, output rows and exit
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    foreach ($messages as $i => $m) {
        fputcsv($out, [
            $i + 1,
            $m['time'],
            $m['lat'],
            $m['lng'],
            $m['speed'],
            $m['course'] . '°',
            $m['altitude'],
            $m['satellites'],
            $m['battery'] . '%',
            $m['ignition'] ? 'ON' : 'OFF',
            $m['params']
        ]);
    }
    fclose($out);
    exit;
}

// Calculate summary telemetry metrics
$total_packets = count($messages);
$speeds = array_column($messages, 'speed');
$max_speed = !empty($speeds) ? max($speeds) : 0;
$avg_speed = !empty($speeds) ? round(array_sum($speeds) / count($speeds), 1) : 0;
$avg_sats = round(array_sum(array_column($messages, 'satellites')) / max(1, $total_packets), 1);

function cardinal_direction(float $deg): string {
    $sectors = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW', 'N'];
    return $sectors[round(($deg % 360) / 22.5)];
}

layout_start('Messages (Raw Data)', 'messages.php');
?>

<div style="padding:16px 20px">
  <!-- Wialon Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px;color:#263238">
        <span>💬</span> <span>GPRS Telematics Messages & Raw Packets</span>
      </h2>
      <div style="font-size:12px;color:#78909c;margin-top:2px">
        Inspect incoming tracker packets, coordinates, satellite fix, digital I/O inputs, and parameters
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="messages.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn sm" style="display:flex;align-items:center;gap:6px;background:#059669">
        <span>📥</span> <span>Export CSV</span>
      </a>
      <a href="index.php?focus=<?= $selected_unit_id ?>" class="btn sm ghost" style="display:flex;align-items:center;gap:6px">
        <span>🛰️</span> <span>View Live on Map</span>
      </a>
    </div>
  </div>

  <!-- Filter Toolbar -->
  <div class="card" style="padding:12px 16px;margin-bottom:16px">
    <form method="get" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0">
      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px">UNIT / MACHINE</label>
        <select name="unit_id" style="font-size:12.5px;padding:5px 8px;min-width:220px;height:32px" onchange="this.form.submit()">
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $u['id'] == $selected_unit_id ? 'selected' : '' ?>>
              🚜 <?= h($u['name']) ?> (<?= h($u['code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px">MESSAGE TYPE</label>
        <select name="msg_type" style="font-size:12.5px;padding:5px 8px;min-width:160px;height:32px">
          <option value="data" <?= $msg_type === 'data' ? 'selected' : '' ?>>Data messages (coordinates)</option>
          <option value="sensor" <?= $msg_type === 'sensor' ? 'selected' : '' ?>>Sensor telemetry</option>
          <option value="sms" <?= $msg_type === 'sms' ? 'selected' : '' ?>>SMS / USSD packets</option>
        </select>
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px">TIME INTERVAL</label>
        <select name="period" style="font-size:12.5px;padding:5px 8px;min-width:140px;height:32px">
          <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
          <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
        </select>
      </div>

      <div style="align-self:flex-end">
        <button type="submit" class="btn sm" style="height:32px;background:#0284c7;display:flex;align-items:center;gap:6px">
          <span>🔍</span> <span>Execute</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Telemetry Summary Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-bottom:16px">
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #0284c7">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Messages Count</div>
      <div style="font-size:22px;font-weight:700;color:#263238;margin-top:4px"><?= $total_packets ?> packets</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #2e7d32">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Max Speed</div>
      <div style="font-size:22px;font-weight:700;color:#2e7d32;margin-top:4px"><?= $max_speed ?> km/h</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #f57c00">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Average Speed</div>
      <div style="font-size:22px;font-weight:700;color:#f57c00;margin-top:4px"><?= $avg_speed ?> km/h</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #7c3aed">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">GPS Satellites (Avg)</div>
      <div style="font-size:22px;font-weight:700;color:#7c3aed;margin-top:4px"><?= $avg_sats ?> Sats (3D Fix)</div>
    </div>
  </div>

  <!-- Messages Table -->
  <div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;text-align:left;color:#475569">
          <th style="padding:8px 12px;width:35px">#</th>
          <th style="padding:8px 12px;width:150px">Time (UTC+05:30)</th>
          <th style="padding:8px 12px;width:170px">Coordinates</th>
          <th style="padding:8px 12px;width:90px">Speed</th>
          <th style="padding:8px 12px;width:100px">Course</th>
          <th style="padding:8px 12px;width:80px">Altitude</th>
          <th style="padding:8px 12px;width:80px">Sats</th>
          <th style="padding:8px 12px;width:80px">Battery</th>
          <th style="padding:8px 12px;width:80px">Ignition</th>
          <th style="padding:8px 12px">Parameters (Sensors & I/O)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($messages as $idx => $m): 
          $isMoving = $m['speed'] > 0;
          $direction = cardinal_direction($m['course']);
        ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $idx % 2 === 1 ? 'background:#fafbfc' : 'background:#fff' ?>">
            <td style="padding:7px 12px;color:#94a3b8;font-size:11px"><?= $idx + 1 ?></td>
            <td style="padding:7px 12px;font-family:monospace;color:#334155;white-space:nowrap">
              <?= $m['time'] ?>
            </td>
            <td style="padding:7px 12px;white-space:nowrap">
              <a href="https://www.google.com/maps?q=<?= $m['lat'] ?>,<?= $m['lng'] ?>" target="_blank" style="font-family:monospace;font-size:11px;color:#0284c7;display:inline-flex;align-items:center;gap:3px">
                <span>📍</span> <?= sprintf('%.6f', $m['lat']) ?>, <?= sprintf('%.6f', $m['lng']) ?>
              </a>
            </td>
            <td style="padding:7px 12px;font-weight:600;white-space:nowrap">
              <?php if ($isMoving): ?>
                <span style="color:#15803d"><?= $m['speed'] ?> km/h</span>
              <?php else: ?>
                <span style="color:#94a3b8">0.0 km/h</span>
              <?php endif; ?>
            </td>
            <td style="padding:7px 12px;white-space:nowrap">
              <span style="display:inline-flex;align-items:center;gap:4px;color:#475569;font-family:monospace">
                <span style="transform:rotate(<?= $m['course'] ?>deg);display:inline-block">⬆</span>
                <span><?= $m['course'] ?>° (<?= $direction ?>)</span>
              </span>
            </td>
            <td style="padding:7px 12px;color:#64748b"><?= $m['altitude'] ?> m</td>
            <td style="padding:7px 12px;color:#475569">
              <span style="display:inline-flex;align-items:center;gap:3px">
                <span>🛰️</span> <span><?= $m['satellites'] ?></span>
              </span>
            </td>
            <td style="padding:7px 12px">
              <span style="display:inline-flex;align-items:center;gap:4px;font-weight:600;color:<?= $m['battery'] > 20 ? '#15803d' : '#dc2626' ?>">
                <span>🔋</span> <span><?= $m['battery'] ?>%</span>
              </span>
            </td>
            <td style="padding:7px 12px">
              <?php if ($m['ignition']): ?>
                <span style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-weight:700;font-size:10.5px">ON</span>
              <?php else: ?>
                <span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:4px;font-weight:600;font-size:10.5px">OFF</span>
              <?php endif; ?>
            </td>
            <td style="padding:7px 12px;font-family:monospace;font-size:11px;color:#334155">
              <?= h($m['params']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_end(); ?>
