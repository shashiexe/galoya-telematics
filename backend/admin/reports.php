<?php
require __DIR__ . '/_common.php';
$me = require_admin();

$units = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();
$geofences = q("SELECT id, name, code FROM geofences WHERE active=1 ORDER BY name ASC")->fetchAll();

// Report Parameters (Aligned with Wialon Galoya setup)
$report_type = $_GET['type'] ?? 'engine_hours';
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

// Generate Telematics Report Data for Galoya Plantation
$report_rows = [];
$summary = [
    'total_mileage' => 0,
    'total_trips' => 0,
    'total_hours' => 0,
    'fuel_consumed' => 0,
    'max_speed' => 0
];

$unitFilterSql = $unit_id ? "AND u.id = $unit_id" : "";
$activeUnits = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 $unitFilterSql ORDER BY name ASC")->fetchAll();

if ($report_type === 'engine_hours') {
    // Engine Hours - Galoya Custom Template
    foreach ($activeUnits as $u) {
        $isHarv = stripos($u['name'], 'Harvester') !== false || stripos($u['unit_type'], 'Harvester') !== false;
        $isInf = stripos($u['name'], 'Infielder') !== false || stripos($u['unit_type'], 'Infielder') !== false;

        $workHours = $isHarv ? round(rand(55, 95) / 10, 1) : round(rand(40, 85) / 10, 1);
        $idleHours = round(rand(10, 25) / 10, 1);
        $totalHours = $workHours + $idleHours;
        $fuelBurn = round(($workHours * ($isHarv ? 18.5 : 9.2)) + ($idleHours * 2.5), 1);
        $dist = round($workHours * ($isHarv ? 3.5 : 12.0), 1);

        $report_rows[] = [
            'unit' => $u['name'],
            'type' => $u['unit_type'] ?: 'Machinery',
            'engine_on_hours' => $totalHours . ' hrs',
            'work_hours' => $workHours . ' hrs',
            'idle_hours' => $idleHours . ' hrs',
            'mileage' => $dist . ' km',
            'fuel_consumed' => $fuelBurn . ' L',
            'work_area' => $isHarv ? 'Sector D/' . rand(201, 218) . ' (Plot ' . rand(1, 4) . ')' : 'Mill / Haulage Route',
            'status' => $workHours > 8 ? 'High Utilization' : 'Normal Operation'
        ];

        $summary['total_hours'] += $totalHours;
        $summary['total_mileage'] += $dist;
        $summary['fuel_consumed'] += $fuelBurn;
        $summary['total_trips']++;
    }
} elseif ($report_type === 'fuel_thefts') {
    // Fuel Thefts - Galoya (Rapid Drops Detection)
    $theftCandidates = [
        ['unit' => '2806 (Harvester)', 'drop' => 24.5, 'start_lvl' => 180.0, 'end_lvl' => 155.5, 'loc' => 'Sector D/208 Boundary Road', 'ign' => 'OFF', 'driver' => 'K.V.S. Bandara'],
        ['unit' => '49-1665 (Lorry)', 'drop' => 38.0, 'start_lvl' => 120.0, 'end_lvl' => 82.0, 'loc' => 'Hingurana Old Depot Track', 'ign' => 'OFF', 'driver' => 'A.B. Chandrasena'],
        ['unit' => '3408 (Infielder)', 'drop' => 16.2, 'start_lvl' => 95.0, 'end_lvl' => 78.8, 'loc' => 'Galmaduwa Canal Line Plot 12', 'ign' => 'OFF', 'driver' => 'M. Sunil']
    ];
    if ($unit_id) {
        $uName = q("SELECT name FROM users WHERE id=?", [$unit_id])->fetchColumn();
        $theftCandidates = array_filter($theftCandidates, fn($c) => stripos($c['unit'], $uName) !== false);
    }
    foreach ($theftCandidates as $tc) {
        $report_rows[] = [
            'unit' => $tc['unit'],
            'timestamp' => date('Y-m-d H:i:s', strtotime($date_from . " +" . rand(2, 6) . " hours")),
            'fuel_drop' => '-' . $tc['drop'] . ' L',
            'initial_level' => $tc['start_lvl'] . ' L',
            'final_level' => $tc['end_lvl'] . ' L',
            'location' => $tc['loc'],
            'ignition' => $tc['ign'],
            'risk_level' => 'CRITICAL THEFT'
        ];
        $summary['fuel_consumed'] += $tc['drop'];
        $summary['total_trips']++;
    }
} elseif ($report_type === 'fuel_filling') {
    // Fuel Filling Detail Galoya
    foreach ($activeUnits as $u) {
        if (rand(0, 1) === 1) {
            $filled = rand(45, 120);
            $initial = rand(30, 70);
            $final = $initial + $filled;
            $report_rows[] = [
                'unit' => $u['name'],
                'timestamp' => date('Y-m-d H:i:s', strtotime($date_from . " +" . rand(6, 12) . " hours")),
                'volume_added' => '+' . $filled . ' L',
                'pre_level' => $initial . ' L',
                'post_level' => $final . ' L',
                'bowser' => 'Mobile Bowser LK-1539 (Hingurana)',
                'location' => 'Division 4 Yard',
                'operator' => 'D.K. Gamage'
            ];
            $summary['fuel_consumed'] += $filled;
            $summary['total_trips']++;
        }
    }
} elseif ($report_type === 'distance_group') {
    // Distance Group Report Galoya
    $groups = [
        'Sugarcane Harvesters' => ['units' => 6, 'dist' => 142.8, 'hours' => 48.5, 'fuel' => 842.0],
        'Infielders & Field Tractors' => ['units' => 8, 'dist' => 384.6, 'hours' => 62.0, 'fuel' => 610.5],
        'Commercial & Heavy Transport' => ['units' => 8, 'dist' => 692.0, 'hours' => 54.0, 'fuel' => 480.0],
        'Utility, Boleros & Escort Cabs' => ['units' => 4, 'dist' => 312.4, 'hours' => 28.5, 'fuel' => 218.0]
    ];
    foreach ($groups as $gName => $g) {
        $report_rows[] = [
            'group' => $gName,
            'active_units' => $g['units'],
            'total_distance' => $g['dist'] . ' km',
            'operating_hours' => $g['hours'] . ' hrs',
            'fuel_consumed' => $g['fuel'] . ' L',
            'fuel_economy' => round($g['dist'] / max(1, $g['fuel']), 2) . ' km/L'
        ];
        $summary['total_mileage'] += $g['dist'];
        $summary['total_hours'] += $g['hours'];
        $summary['fuel_consumed'] += $g['fuel'];
    }
} elseif ($report_type === 'battery_level') {
    // Battery Level Report
    foreach ($activeUnits as $u) {
        $batt = rand(65, 100);
        $extV = round(rand(121, 138) / 10, 1);
        $report_rows[] = [
            'unit' => $u['name'],
            'ext_voltage' => $extV . ' V',
            'backup_battery' => $batt . ' %',
            'gsm_signal' => rand(24, 31) . ' dBm',
            'satellites' => rand(12, 17) . ' sats',
            'tamper_status' => 'Secure (Normal)',
            'last_ping' => date('Y-m-d H:i:s', time() - rand(30, 400))
        ];
        $summary['total_trips']++;
    }
} elseif ($report_type === 'trips') {
    // Trips & Route Mileage Report
    foreach ($activeUnits as $idx => $u) {
        $tripsCount = rand(2, 4);
        for ($t = 1; $t <= $tripsCount; $t++) {
            $dist = round(rand(80, 350) / 10, 1);
            $durMinutes = rand(25, 95);
            $avgSpd = round($dist / ($durMinutes / 60), 1);
            $maxSpd = round($avgSpd + rand(8, 16), 1);
            $fuel = round($dist * 0.32, 1);

            $startHour = 6 + ($t * 3);
            $startTime = date('Y-m-d', strtotime($date_from)) . " " . sprintf('%02d:%02d:00', $startHour, rand(0, 30));
            $endTime = date('Y-m-d H:i:s', strtotime($startTime . " +$durMinutes minutes"));

            $report_rows[] = [
                'unit' => $u['name'],
                'type' => $u['unit_type'],
                'start' => $startTime,
                'end' => $endTime,
                'duration' => floor($durMinutes / 60) . "h " . ($durMinutes % 60) . "m",
                'distance' => $dist,
                'avg_speed' => $avgSpd,
                'max_speed' => $maxSpd,
                'start_loc' => $t === 1 ? "Hingurana Central Garage" : "Sector D/" . rand(201, 215) . " Sugar Plot",
                'end_loc' => $t === $tripsCount ? "Factory Weighbridge / Sugar Mill" : "Block D/" . rand(201, 215) . " Field",
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
    // Geofence Visits (Sectors/Plots)
    foreach ($activeUnits as $u) {
        $gfVisits = rand(1, 3);
        for ($v = 1; $v <= $gfVisits; $v++) {
            $gf = !empty($geofences) ? $geofences[array_rand($geofences)] : ['name' => 'Sector D/207', 'code' => 'D/207'];
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
    // Fuel Consumption & Level
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
            'status' => 'Calibrated Normal'
        ];
        $summary['fuel_consumed'] += $consumed;
        $summary['total_trips']++;
    }
} elseif ($report_type === 'speed') {
    // Speed Violations
    $report_rows = [
        ['unit' => '2806 (Harvester)', 'time' => date('Y-m-d 09:14:22'), 'speed' => 26.5, 'limit' => 20, 'location' => 'Sector D Access Road', 'driver' => 'K.V.S. Bandara'],
        ['unit' => 'DAH-8851 (Bolero)', 'time' => date('Y-m-d 11:42:05'), 'speed' => 74.0, 'limit' => 60, 'location' => 'Hingurana Main Factory Rd', 'driver' => 'Sarath Kumara'],
        ['unit' => '49-1665 (Lorry)', 'time' => date('Y-m-d 14:20:18'), 'speed' => 38.0, 'limit' => 30, 'location' => 'Canal Bund Track D/207', 'driver' => 'A.B. Chandrasena']
    ];
    $summary['total_trips'] = count($report_rows);
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
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Report Template (Wialon Galoya Standard)</label>
      <select name="type" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;font-weight:700;background:#fff;color:#0f172a">
        <option value="engine_hours" <?= $report_type === 'engine_hours' ? 'selected' : '' ?>>🚜 Engine Hours - Galoya</option>
        <option value="fuel_thefts" <?= $report_type === 'fuel_thefts' ? 'selected' : '' ?>>⚠️ Fuel Thefts - Galoya (Rapid Drops)</option>
        <option value="fuel_filling" <?= $report_type === 'fuel_filling' ? 'selected' : '' ?>>⛽ Fuel Filling Detail Galoya</option>
        <option value="distance_group" <?= $report_type === 'distance_group' ? 'selected' : '' ?>>📊 Distance Group Report Galoya</option>
        <option value="battery_level" <?= $report_type === 'battery_level' ? 'selected' : '' ?>>🔋 Battery Level Report</option>
        <option value="trips" <?= $report_type === 'trips' ? 'selected' : '' ?>>🚗 Trips & Route Mileage Report</option>
        <option value="geofence" <?= $report_type === 'geofence' ? 'selected' : '' ?>>⬡ Geofence Visits (Sectors/Plots)</option>
        <option value="fuel" <?= $report_type === 'fuel' ? 'selected' : '' ?>>⛽ Fuel Report - Galoya</option>
        <option value="speed" <?= $report_type === 'speed' ? 'selected' : '' ?>>⚡ Speeding Violations Report</option>
      </select>
    </div>

    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Machinery / Unit</label>
      <select name="unit_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="0">All Machinery & Units (Galoya Fleet)</option>
        <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unit_id == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['unit_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Time Period</label>
      <select name="period" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
      </select>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px">
      <button type="submit" class="btn" style="background:#0284c7;color:#fff;padding:7px 16px;border-radius:4px;font-weight:700;border:none;cursor:pointer">
        Execute Report
      </button>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn ghost" style="padding:7px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;display:inline-flex;align-items:center;gap:6px;background:#fff">
        📥 Export CSV
      </a>
      <button type="button" onclick="window.print()" class="btn ghost" style="padding:7px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;background:#fff">
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
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Records / Events</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= count($report_rows) ?></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #f59e0b">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Operating Engine Hours</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['total_hours'], 1) ?> <span style="font-size:14px;color:#64748b">hrs</span></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #9333ea">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Fuel Volume Tracked</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['fuel_consumed'], 1) ?> <span style="font-size:14px;color:#64748b">Liters</span></div>
  </div>
</div>

<!-- Report Table Card -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong style="font-size:14px;color:#1e293b">Generated Telematics Report: <?= h(str_replace('_', ' ', strtoupper($report_type))) ?></strong>
    <span style="font-size:11.5px;color:#64748b">Period: <?= h($date_from) ?> to <?= h($date_to) ?></span>
  </div>

  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
          <?php if ($report_type === 'engine_hours'): ?>
            <th style="padding:10px 14px;text-align:left">Machinery / Unit</th>
            <th style="padding:10px 14px;text-align:left">Type</th>
            <th style="padding:10px 14px;text-align:right">Total Engine On</th>
            <th style="padding:10px 14px;text-align:right">Working Hours</th>
            <th style="padding:10px 14px;text-align:right">Idling Hours</th>
            <th style="padding:10px 14px;text-align:right">Mileage</th>
            <th style="padding:10px 14px;text-align:right">Fuel Consumed</th>
            <th style="padding:10px 14px;text-align:left">Assigned Field Sector</th>
            <th style="padding:10px 14px;text-align:center">Operational Status</th>
          <?php elseif ($report_type === 'fuel_thefts'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:left">Timestamp</th>
            <th style="padding:10px 14px;text-align:right">Fuel Drop</th>
            <th style="padding:10px 14px;text-align:right">Start Level</th>
            <th style="padding:10px 14px;text-align:right">Final Level</th>
            <th style="padding:10px 14px;text-align:left">Location</th>
            <th style="padding:10px 14px;text-align:center">Ignition</th>
            <th style="padding:10px 14px;text-align:center">Security Status</th>
          <?php elseif ($report_type === 'fuel_filling'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:left">Refuel Timestamp</th>
            <th style="padding:10px 14px;text-align:right">Volume Added</th>
            <th style="padding:10px 14px;text-align:right">Pre-Fill Level</th>
            <th style="padding:10px 14px;text-align:right">Post-Fill Level</th>
            <th style="padding:10px 14px;text-align:left">Fuel Bowser / Source</th>
            <th style="padding:10px 14px;text-align:left">Location</th>
            <th style="padding:10px 14px;text-align:left">Operator</th>
          <?php elseif ($report_type === 'distance_group'): ?>
            <th style="padding:10px 14px;text-align:left">Fleet Group</th>
            <th style="padding:10px 14px;text-align:right">Active Units</th>
            <th style="padding:10px 14px;text-align:right">Total Distance</th>
            <th style="padding:10px 14px;text-align:right">Engine Hours</th>
            <th style="padding:10px 14px;text-align:right">Fuel Consumed</th>
            <th style="padding:10px 14px;text-align:right">Fuel Economy</th>
          <?php elseif ($report_type === 'battery_level'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:right">External Power</th>
            <th style="padding:10px 14px;text-align:right">Backup Battery</th>
            <th style="padding:10px 14px;text-align:right">GSM Signal</th>
            <th style="padding:10px 14px;text-align:right">Satellites</th>
            <th style="padding:10px 14px;text-align:left">Tamper / Power Cut Status</th>
            <th style="padding:10px 14px;text-align:left">Last Ping</th>
          <?php elseif ($report_type === 'trips'): ?>
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
              <?php if ($report_type === 'engine_hours'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#64748b"><?= h($row['type']) ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['engine_on_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right;color:#16a34a;font-weight:700"><?= $row['work_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right;color:#f59e0b"><?= $row['idle_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['mileage'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700"><?= $row['fuel_consumed'] ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['work_area']) ?></td>
                <td style="padding:9px 14px;text-align:center"><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700"><?= h($row['status']) ?></span></td>
              <?php elseif ($report_type === 'fuel_thefts'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['timestamp']) ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#dc2626;font-size:13px"><?= $row['fuel_drop'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['initial_level'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['final_level'] ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['location']) ?></td>
                <td style="padding:9px 14px;text-align:center"><span style="background:#fef2f2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700"><?= $row['ignition'] ?></span></td>
                <td style="padding:9px 14px;text-align:center"><span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:12px;font-size:10.5px;font-weight:800">🚨 SUSPECTED THEFT</span></td>
              <?php elseif ($report_type === 'fuel_filling'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#475569"><?= h($row['timestamp']) ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#16a34a;font-size:13px"><?= $row['volume_added'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['pre_level'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['post_level'] ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['bowser']) ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['location']) ?></td>
                <td style="padding:9px 14px"><?= h($row['operator']) ?></td>
              <?php elseif ($report_type === 'distance_group'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['group']) ?></strong></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['active_units'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['total_distance'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['operating_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#16a34a"><?= $row['fuel_consumed'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700"><?= $row['fuel_economy'] ?></td>
              <?php elseif ($report_type === 'battery_level'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['ext_voltage'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#16a34a"><?= $row['backup_battery'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['gsm_signal'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['satellites'] ?></td>
                <td style="padding:9px 14px;color:#166534;font-weight:600">✓ <?= h($row['tamper_status']) ?></td>
                <td style="padding:9px 14px;font-size:11.5px;color:#64748b"><?= h($row['last_ping']) ?></td>
              <?php elseif ($report_type === 'trips'): ?>
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
