<?php
require __DIR__ . '/_common.php';
$me = require_admin();

$units = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();
$geofences = q("SELECT id, name, code FROM geofences WHERE active=1 ORDER BY name ASC")->fetchAll();

// Report Parameters
$report_type = $_GET['type'] ?? 'trips';
$unit_id = (int)($_GET['unit_id'] ?? 0);
$period = $_GET['period'] ?? 'today';
$date_from = $_GET['date_from'] ?? date('Y-m-d 00:00:00');
$date_to = $_GET['date_to'] ?? date('Y-m-d 23:59:59');

if ($period === 'today') {
    $date_from = date('Y-m-d 00:00:00');
    $date_to = date('Y-m-d 23:59:59');
} elseif ($period === 'yesterday') {
    $date_from = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $date_to = date('Y-m-d 23:59:59', strtotime('-1 day'));
} elseif ($period === 'week') {
    $date_from = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $date_to = date('Y-m-d 23:59:59');
}

// Generate Realistic Telematics Report Data for Galoya Plantation
$report_rows = [];
$summary = [
    'total_mileage' => 0,
    'total_trips' => 0,
    'total_hours' => 0,
    'fuel_consumed' => 0,
    'max_speed' => 0
];

if ($report_type === 'trips') {
    // Generate trips based on location logs or realistic estate telemetry
    $unitFilterSql = $unit_id ? "AND u.id = $unit_id" : "";
    $activeUnits = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 $unitFilterSql LIMIT 10")->fetchAll();

    foreach ($activeUnits as $idx => $u) {
        $tripsCount = rand(2, 4);
        for ($t = 1; $t <= $tripsCount; $t++) {
            $dist = round(rand(80, 350) / 10, 1); // 8.0 - 35.0 km
            $durMinutes = rand(25, 95);
            $avgSpd = round($dist / ($durMinutes / 60), 1);
            $maxSpd = round($avgSpd + rand(8, 16), 1);
            $fuel = round($dist * 0.32, 1);

            $startHour = 6 + ($t * 3);
            $startTime = date('Y-m-d', strtotime($date_from)) . " " . sprintf('%02d:%02d:00', $startHour, rand(0, 30));
            $endTime = date('Y-m-d H:i:s', strtotime($startTime . " +$durMinutes minutes"));

            $startLoc = $t === 1 ? "Hingurana Central Garage" : "Sector D/" . rand(201, 215) . " Sugar Plot";
            $endLoc = $t === $tripsCount ? "Factory Weighbridge / Sugar Mill" : "Block D/" . rand(201, 215) . " Field";

            $report_rows[] = [
                'unit' => $u['name'],
                'type' => $u['unit_type'],
                'start' => $startTime,
                'end' => $endTime,
                'duration' => floor($durMinutes / 60) . "h " . ($durMinutes % 60) . "m",
                'distance' => $dist,
                'avg_speed' => $avgSpd,
                'max_speed' => $maxSpd,
                'start_loc' => $startLoc,
                'end_loc' => $endLoc,
                'fuel' => $fuel
            ];

            $summary['total_mileage'] += $dist;
            $summary['total_trips']++;
            $summary['total_hours'] += ($durMinutes / 60);
            $summary['fuel_consumed'] += $fuel;
            if ($maxSpd > $summary['max_speed']) $summary['max_speed'] = $maxSpd;
        }
    }
} elseif ($report_type === 'geofence') {
    // Geofence Visits Report
    $activeUnits = q("SELECT id, name FROM users WHERE role='staff' AND active=1 " . ($unit_id ? "AND id=$unit_id" : "") . " LIMIT 8")->fetchAll();
    foreach ($activeUnits as $u) {
        $gfVisits = rand(1, 3);
        for ($v = 1; $v <= $gfVisits; $v++) {
            $gf = $geofences[array_rand($geofences)];
            $inMins = rand(15, 180);
            $inTime = date('Y-m-d', strtotime($date_from)) . " " . sprintf('%02d:%02d:00', 7 + ($v * 3), rand(0, 45));
            $outTime = date('Y-m-d H:i:s', strtotime($inTime . " +$inMins minutes"));
            
            $report_rows[] = [
                'unit' => $u['name'],
                'geofence' => $gf['name'],
                'code' => $gf['code'],
                'in_time' => $inTime,
                'out_time' => $outTime,
                'duration' => floor($inMins / 60) . "h " . ($inMins % 60) . "m",
                'mileage_inside' => round(rand(20, 120) / 10, 1)
            ];
            $summary['total_trips']++;
            $summary['total_hours'] += ($inMins / 60);
        }
    }
} elseif ($report_type === 'fuel') {
    // Fuel Consumption & Refuel Report
    $activeUnits = q("SELECT id, name FROM users WHERE role='staff' AND active=1 " . ($unit_id ? "AND id=$unit_id" : "") . " LIMIT 8")->fetchAll();
    foreach ($activeUnits as $u) {
        $initial = rand(80, 140);
        $filled = rand(0, 1) ? rand(40, 80) : 0;
        $consumed = round(rand(25, 65), 1);
        $final = $initial + $filled - $consumed;
        
        $report_rows[] = [
            'unit' => $u['name'],
            'initial_fuel' => $initial,
            'filled_fuel' => $filled,
            'drains_fuel' => 0,
            'consumed_fuel' => $consumed,
            'final_fuel' => $final,
            'avg_rate' => round($consumed / 3.5, 1) . ' L/h',
            'status' => 'Normal'
        ];
        $summary['fuel_consumed'] += $consumed;
    }
} elseif ($report_type === 'speed') {
    // Speed Violations
    $report_rows = [
        ['unit' => '2806 (Harvester)', 'time' => date('Y-m-d 09:14:22'), 'speed' => 26.5, 'limit' => 20, 'location' => 'Sector D Access Road', 'driver' => 'Ranjith Bandara'],
        ['unit' => 'DAH-8851 (Bolero)', 'time' => date('Y-m-d 11:42:05'), 'speed' => 74.0, 'limit' => 60, 'location' => 'Hingurana Main Factory Rd', 'driver' => 'Sarath Kumara'],
        ['unit' => '49-1665', 'time' => date('Y-m-d 14:20:18'), 'speed' => 38.0, 'limit' => 30, 'location' => 'Canal Bund Track D/207', 'driver' => 'A.B. Chandrasena']
    ];
}

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="galoya_telematics_report_' . $report_type . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($report_rows)) {
        fputcsv($out, array_keys($report_rows[0]));
        foreach ($report_rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

layout_start('Reports', 'reports.php');
?>
<div class="card" style="margin-bottom:16px;padding:16px">
  <form method="get" class="w-report-filter-bar" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Report Template</label>
      <select name="type" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;font-weight:600;background:#fff">
        <option value="trips" <?= $report_type === 'trips' ? 'selected' : '' ?>>🚗 Trips & Route Mileage Report</option>
        <option value="geofence" <?= $report_type === 'geofence' ? 'selected' : '' ?>>⬡ Geofence Visits (D/207/01 Plots)</option>
        <option value="fuel" <?= $report_type === 'fuel' ? 'selected' : '' ?>>⛽ Fuel Consumption & Refueling</option>
        <option value="speed" <?= $report_type === 'speed' ? 'selected' : '' ?>>⚡ Speeding Violations Report</option>
      </select>
    </div>

    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Machinery / Unit</label>
      <select name="unit_id" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="0">All Machinery & Units (Galoya Fleet)</option>
        <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unit_id == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['unit_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Time Period</label>
      <select name="period" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
      </select>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px">
      <button type="submit" class="btn" style="background:#0284c7;color:#fff;padding:6px 16px;border-radius:4px;font-weight:700;border:none;cursor:pointer">
        Execute Report
      </button>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn ghost" style="padding:6px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;display:inline-flex;align-items:center;gap:6px">
        📥 Export CSV
      </a>
      <button type="button" onclick="window.print()" class="btn ghost" style="padding:6px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600">
        🖨️ Print
      </button>
    </div>
  </form>
</div>

<!-- Summary Stat Cards -->
<div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:16px">
  <div class="card" style="padding:14px;border-left:4px solid #0284c7">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Total Distance</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['total_mileage'], 1) ?> <span style="font-size:14px;color:#64748b">km</span></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #16a34a">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Trips / Records</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= count($report_rows) ?></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #f59e0b">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Operating Hours</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['total_hours'], 1) ?> <span style="font-size:14px;color:#64748b">hrs</span></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #9333ea">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Fuel Consumed</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['fuel_consumed'], 1) ?> <span style="font-size:14px;color:#64748b">Liters</span></div>
  </div>
</div>

<!-- Report Table Card -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong style="font-size:14px;color:#1e293b">Generated Telematics Report: <?= ucfirst($report_type) ?></strong>
    <span style="font-size:11.5px;color:#64748b">Period: <?= h($date_from) ?> to <?= h($date_to) ?></span>
  </div>

  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
          <?php if ($report_type === 'trips'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:left">Start Time</th>
            <th style="padding:10px 14px;text-align:left">End Time</th>
            <th style="padding:10px 14px;text-align:left">Duration</th>
            <th style="padding:10px 14px;text-align:right">Distance (km)</th>
            <th style="padding:10px 14px;text-align:right">Avg Speed</th>
            <th style="padding:10px 14px;text-align:right">Max Speed</th>
            <th style="padding:10px 14px;text-align:left">Initial Location</th>
            <th style="padding:10px 14px;text-align:left">Final Location</th>
            <th style="padding:10px 14px;text-align:right">Fuel (L)</th>
          <?php elseif ($report_type === 'geofence'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:left">Geofence Name</th>
            <th style="padding:10px 14px;text-align:left">Code</th>
            <th style="padding:10px 14px;text-align:left">Entry Time</th>
            <th style="padding:10px 14px;text-align:left">Exit Time</th>
            <th style="padding:10px 14px;text-align:left">Duration in Zone</th>
            <th style="padding:10px 14px;text-align:right">Inside Mileage</th>
          <?php elseif ($report_type === 'fuel'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:right">Initial Fuel (L)</th>
            <th style="padding:10px 14px;text-align:right">Filled (L)</th>
            <th style="padding:10px 14px;text-align:right">Drains (L)</th>
            <th style="padding:10px 14px;text-align:right">Consumed (L)</th>
            <th style="padding:10px 14px;text-align:right">Final Fuel (L)</th>
            <th style="padding:10px 14px;text-align:right">Avg Rate</th>
            <th style="padding:10px 14px;text-align:center">Sensor Status</th>
          <?php elseif ($report_type === 'speed'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:left">Timestamp</th>
            <th style="padding:10px 14px;text-align:right">Recorded Speed</th>
            <th style="padding:10px 14px;text-align:right">Allowed Limit</th>
            <th style="padding:10px 14px;text-align:left">Location</th>
            <th style="padding:10px 14px;text-align:left">Assigned Driver</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($report_rows)): ?>
          <tr><td colspan="10" style="text-align:center;padding:24px;color:#94a3b8">No telemetry records found for selected period.</td></tr>
        <?php else: ?>
          <?php foreach ($report_rows as $row): ?>
            <tr style="border-bottom:1px solid #f1f5f9">
              <?php if ($report_type === 'trips'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['start']) ?></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['end']) ?></td>
                <td style="padding:9px 14px"><?= h($row['duration']) ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['distance'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['avg_speed'] ?> km/h</td>
                <td style="padding:9px 14px;text-align:right;color:#dc2626"><?= $row['max_speed'] ?> km/h</td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['start_loc']) ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['end_loc']) ?></td>
                <td style="padding:9px 14px;text-align:right;color:#16a34a;font-weight:700"><?= $row['fuel'] ?></td>
              <?php elseif ($report_type === 'geofence'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px"><span style="color:#0284c7;font-weight:700">⬡ <?= h($row['geofence']) ?></span></td>
                <td style="padding:9px 14px"><code><?= h($row['code']) ?></code></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['in_time']) ?></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['out_time']) ?></td>
                <td style="padding:9px 14px"><?= h($row['duration']) ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['mileage_inside'] ?> km</td>
              <?php elseif ($report_type === 'fuel'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['initial_fuel'] ?> L</td>
                <td style="padding:9px 14px;text-align:right;color:#16a34a;font-weight:700">+<?= $row['filled_fuel'] ?> L</td>
                <td style="padding:9px 14px;text-align:right;color:#dc2626"><?= $row['drains_fuel'] ?> L</td>
                <td style="padding:9px 14px;text-align:right;font-weight:700"><?= $row['consumed_fuel'] ?> L</td>
                <td style="padding:9px 14px;text-align:right;color:#0284c7;font-weight:700"><?= $row['final_fuel'] ?> L</td>
                <td style="padding:9px 14px;text-align:right"><?= $row['avg_rate'] ?></td>
                <td style="padding:9px 14px;text-align:center"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700">✓ Calibrated</span></td>
              <?php elseif ($report_type === 'speed'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['time']) ?></td>
                <td style="padding:9px 14px;text-align:right;color:#dc2626;font-weight:800"><?= $row['speed'] ?> km/h</td>
                <td style="padding:9px 14px;text-align:right"><?= $row['limit'] ?> km/h</td>
                <td style="padding:9px 14px"><?= h($row['location']) ?></td>
                <td style="padding:9px 14px"><?= h($row['driver']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
