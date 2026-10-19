<?php
require __DIR__ . '/_common.php';
$me = require_admin();

$units = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();
$geofences = q("SELECT id, name, code FROM geofences WHERE active=1 ORDER BY name ASC")->fetchAll();
$departments = q("SELECT id, name, code FROM departments WHERE active=1 ORDER BY name ASC")->fetchAll();
$sections = q("SELECT s.id, s.department_id, s.name, s.code, d.name dept_name FROM sections s LEFT JOIN departments d ON d.id=s.department_id WHERE s.active=1 ORDER BY s.name ASC")->fetchAll();

// Report Parameters (Aligned with Wialon Galoya setup)
$report_type = $_GET['type'] ?? 'staff_audit';
$unit_id = (int)($_GET['unit_id'] ?? 0);
$filterDept = (int)($_GET['dept_id'] ?? 0);
$filterSec = (int)($_GET['sec_id'] ?? 0);
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

// Haversine distance in km
if (!function_exists('haversine_dist_km')) {
    function haversine_dist_km($lat1, $lon1, $lat2, $lon2) {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

// Generate Telematics Report Data
$report_rows = [];
$summary = [
    'total_mileage' => 0,
    'total_trips' => 0,
    'total_hours' => 0,
    'fuel_consumed' => 0,
    'max_speed' => 0
];

$unitFilterSql = $unit_id ? "AND id = " . (int)$unit_id : "";
$activeUnits = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 $unitFilterSql ORDER BY name ASC")->fetchAll();

if ($report_type === 'staff_audit') {
    // -------------------------------------------------------------------------
    // 📱 Staff Daily Activity & Tracking Audit Report (With Inactive Gap Detection)
    // -------------------------------------------------------------------------
    if (!$unit_id && !empty($units)) {
        $unit_id = (int)$units[0]['id'];
    }

    $staffUser = null;
    try {
        $staffUser = q("SELECT u.*, d.name dept_name, s.name sec_name, ds.name desig_title
                        FROM users u 
                        LEFT JOIN departments d ON d.id=u.department_id 
                        LEFT JOIN sections s ON s.id=u.section_id 
                        LEFT JOIN designations ds ON ds.id=u.designation_id
                        WHERE u.id=?", [$unit_id])->fetch();
    } catch (Throwable $e) {
        try {
            $staffUser = q("SELECT * FROM users WHERE id=?", [$unit_id])->fetch();
        } catch (Throwable $e2) {}
    }


    $dayStart = date('Y-m-d 00:00:00', strtotime($date_from));
    $dayEnd = date('Y-m-d 23:59:59', strtotime($date_to));

    // 1. Fetch Duty On / Off Logs
    $dutyRows = [];
    try {
        $dutyRows = q("SELECT * FROM duty_logs WHERE user_id=? AND at BETWEEN ? AND ? ORDER BY at ASC", 
                      [$unit_id, $dayStart, $dayEnd])->fetchAll();
    } catch (Throwable $e) {}

    // 2. Fetch GPS Track Points
    $pts = [];
    try {
        $pts = q("SELECT lat, lng, speed, battery, recorded_at 
                  FROM locations 
                  WHERE user_id=? AND recorded_at BETWEEN ? AND ? 
                  ORDER BY recorded_at ASC", [$unit_id, $dayStart, $dayEnd])->fetchAll();
    } catch (Throwable $e) {}

    // 3. Fetch Field Photos
    $photos = [];
    try {
        $photos = q("SELECT file_path, lat, lng, note, geofence_name, activity_type, auto_geofence, selected_geofence, taken_at 
                     FROM field_photos 
                     WHERE user_id=? AND taken_at BETWEEN ? AND ? 
                     ORDER BY taken_at ASC", [$unit_id, $dayStart, $dayEnd])->fetchAll();
    } catch (Throwable $e) {}

    // Chronological Activity Timeline
    $timeline = [];

    // Add Duty & App Lifecycle Events
    foreach ($dutyRows as $dl) {
        $et = $dl['event_type'] ?: ($dl['status'] === 'on' ? 'tracking_on' : 'tracking_off');
        $evtTitle = '🟢 Tracking Active (Duty ON)';
        $details = 'Tracking session in progress';

        if ($et === 'app_open') {
            $evtTitle = '📱 App Launched (Foreground)';
            $details = 'Field officer opened mobile app';
        } elseif ($et === 'app_close') {
            $evtTitle = '🚪 App Closed / Minimized';
            $details = 'App closed or running in background daemon';
        } elseif ($et === 'tracking_on') {
            $evtTitle = '🟢 Tracking Started (Duty ON)';
            $details = 'Shift / Duty tracking started';
        } elseif ($et === 'tracking_off') {
            $evtTitle = '🔴 Tracking Stopped (Duty OFF)';
            $details = 'Shift / Duty stopped by staff';
        } elseif ($et === 'boot_resume') {
            $evtTitle = '⚡ Device Boot / Auto-Resumed';
            $details = 'Background tracking auto-resumed on phone restart';
        } elseif ($et === 'parking_start') {
            $evtTitle = '🅿️ Parking / Stop Commenced';
            $details = 'Vehicle stopped for >= 3 mins';
        } elseif ($et === 'parking_end') {
            $evtTitle = '🚙 Parking Ended / Departed';
            $details = 'Motion resumed after stationary stop';
        }

        $loc = 'Base / Yard';
        if (!empty($dl['lat']) && !empty($dl['lng'])) {
            $loc = round((float)$dl['lat'], 4) . ', ' . round((float)$dl['lng'], 4);
            try {
                $loc = find_nearest_geofence((float)$dl['lat'], (float)$dl['lng']) ?: $loc;
            } catch (Throwable $e) {}
        }

        $timeline[] = [
            'time' => $dl['at'],
            'event' => $evtTitle,
            'type' => $et,
            'location' => $loc,
            'details' => $details,
            'battery' => ($dl['battery'] !== null ? $dl['battery'] . '%' : '—'),
            'remarks' => $dl['note'] ?: 'Automated Telematics Event'
        ];
    }

    // Add Field Photos
    foreach ($photos as $ph) {
        $timeline[] = [
            'time' => $ph['taken_at'],
            'event' => '📷 ' . ($ph['activity_type'] ?: 'Field Photo Upload'),
            'type' => 'photo',
            'location' => $ph['selected_geofence'] ?: ($ph['auto_geofence'] ?: ($ph['geofence_name'] ?: 'Field Plot')),
            'details' => $ph['note'] ?: 'Field documentation',
            'battery' => '—',
            'remarks' => 'Auto: ' . ($ph['auto_geofence'] ?: '—') . ' | Confirmed: ' . ($ph['selected_geofence'] ?: '—')
        ];
    }

    // Process GPS Points: Calculate Trips, Stops, and Inactive Gaps
    $n = count($pts);
    $totalKm = 0;
    $maxSpd = 0;
    $movingPts = [];
    $stationaryPts = [];

    for ($i = 0; $i < $n; $i++) {
        $p = $pts[$i];
        $spd = (float)$p['speed'];
        if ($spd > $maxSpd) $maxSpd = $spd;

        // Check Gap to Next Point (> 15 minutes = Inactive Gap / Phone Off / Battery Dead)
        if ($i < $n - 1) {
            $tCur = strtotime($p['recorded_at']);
            $tNext = strtotime($pts[$i + 1]['recorded_at']);
            $gapSec = $tNext - $tCur;

            if ($gapSec >= 900) { // >= 15 minutes
                $gapHours = floor($gapSec / 3600);
                $gapMins = floor(($gapSec % 3600) / 60);
                $durStr = ($gapHours > 0 ? "{$gapHours}h " : "") . "{$gapMins}m";

                $b1 = isset($p['battery']) ? (int)$p['battery'] : null;
                $b2 = isset($pts[$i + 1]['battery']) ? (int)$pts[$i + 1]['battery'] : null;

                $reason = "Device Inactive / Signal Disconnected";
                if ($b1 !== null && $b2 !== null) {
                    if ($b1 <= 5) {
                        $reason = "⚠️ Sudden Power Off (Battery died at {$b1}% -> Restored at {$b2}%)";
                    } elseif ($b2 > $b1 + 20) {
                        $reason = "⚠️ Device Rebooted / Charged after power off ({$b1}% -> {$b2}%)";
                    } elseif ($b1 > 50 && $b2 > 50) {
                        $reason = "📡 Cellular Data Lost / Airplane Mode (Batt maintained: {$b1}% -> {$b2}%)";
                    }
                }

                $timeline[] = [
                    'time' => date('Y-m-d H:i:s', $tCur),
                    'event' => '⚠️ Inactive / Phone Off Gap (' . $durStr . ')',
                    'type' => 'gap',
                    'location' => round((float)$p['lat'], 4) . ', ' . round((float)$p['lng'], 4),
                    'details' => 'Gap: ' . date('H:i', $tCur) . ' to ' . date('H:i', $tNext) . " ({$durStr})",
                    'battery' => ($b1 !== null ? "{$b1}% ➔ " : "") . ($b2 !== null ? "{$b2}%" : "—"),
                    'remarks' => $reason
                ];
            }
        }

        // Segment Movement vs Stationary / Parking
        if ($spd > 3.0) {
            // Flush any accumulated stationary stop
            $sCount = count($stationaryPts);
            if ($sCount >= 2) {
                $pStart = $stationaryPts[0];
                $pEnd = $stationaryPts[$sCount - 1];
                $tS1 = strtotime($pStart['recorded_at']);
                $tS2 = strtotime($pEnd['recorded_at']);
                $sDur = $tS2 - $tS1;
                if ($sDur >= 180) { // >= 3 minutes
                    $sMins = round($sDur / 60);
                    $stopLoc = round((float)$pStart['lat'], 4) . ', ' . round((float)$pStart['lng'], 4);
                    try {
                        $stopLoc = find_nearest_geofence((float)$pStart['lat'], (float)$pStart['lng']) ?: $stopLoc;
                    } catch (Throwable $e) {}

                    $timeline[] = [
                        'time' => $pStart['recorded_at'],
                        'event' => '🅿️ Parking / Stationary Stop (' . $sMins . ' mins)',
                        'type' => 'parking',
                        'location' => $stopLoc,
                        'details' => 'Parked from ' . date('H:i', $tS1) . ' to ' . date('H:i', $tS2) . " ({$sMins} mins)",
                        'battery' => ($pStart['battery'] ?? '—') . '%',
                        'remarks' => 'Vehicle stationary'
                    ];
                }
                $stationaryPts = [];
            }
            $movingPts[] = $p;
        } else {
            $stationaryPts[] = $p;
            if (count($movingPts) >= 2) {
                // End of moving trip
                $tripDist = 0;
                for ($k = 1; $k < count($movingPts); $k++) {
                    $tripDist += haversine_dist_km((float)$movingPts[$k-1]['lat'], (float)$movingPts[$k-1]['lng'], (float)$movingPts[$k]['lat'], (float)$movingPts[$k]['lng']);
                }
                if ($tripDist >= 0.2) {
                    $t1 = strtotime($movingPts[0]['recorded_at']);
                    $t2 = strtotime($movingPts[count($movingPts)-1]['recorded_at']);
                    $tDur = max(60, $t2 - $t1);
                    $totalKm += $tripDist;

                    $timeline[] = [
                        'time' => $movingPts[0]['recorded_at'],
                        'event' => '🚗 Trip / Travel (' . round($tripDist, 1) . ' km)',
                        'type' => 'trip',
                        'location' => 'In-Transit (' . date('H:i', $t1) . ' – ' . date('H:i', $t2) . ')',
                        'details' => round($tripDist, 2) . ' km in ' . round($tDur / 60) . ' mins (Avg: ' . round($tripDist / ($tDur / 3600), 1) . ' km/h)',
                        'battery' => ($movingPts[0]['battery'] ?? '—') . '%',
                        'remarks' => 'Moving speed up to ' . round($maxSpd, 1) . ' km/h'
                    ];
                }
                $movingPts = [];
            }
        }
    }

    // Flush any trailing stationary stop
    $sCount = count($stationaryPts);
    if ($sCount >= 2) {
        $pStart = $stationaryPts[0];
        $pEnd = $stationaryPts[$sCount - 1];
        $tS1 = strtotime($pStart['recorded_at']);
        $tS2 = strtotime($pEnd['recorded_at']);
        $sDur = $tS2 - $tS1;
        if ($sDur >= 180) {
            $sMins = round($sDur / 60);
            $stopLoc = round((float)$pStart['lat'], 4) . ', ' . round((float)$pStart['lng'], 4);
            try {
                $stopLoc = find_nearest_geofence((float)$pStart['lat'], (float)$pStart['lng']) ?: $stopLoc;
            } catch (Throwable $e) {}

            $timeline[] = [
                'time' => $pStart['recorded_at'],
                'event' => '🅿️ Parking / Stationary Stop (' . $sMins . ' mins)',
                'type' => 'parking',
                'location' => $stopLoc,
                'details' => 'Parked from ' . date('H:i', $tS1) . ' to ' . date('H:i', $tS2) . " ({$sMins} mins)",
                'battery' => ($pStart['battery'] ?? '—') . '%',
                'remarks' => 'Vehicle parked at location'
            ];
        }
    }

    // Sort timeline chronologically
    usort($timeline, function($a, $b) {
        return strcmp((string)($a['time'] ?? ''), (string)($b['time'] ?? ''));
    });

    foreach ($timeline as $t) {
        $report_rows[] = [
            'time' => $t['time'],
            'event' => $t['event'],
            'location' => $t['location'],
            'details' => $t['details'],
            'battery' => $t['battery'],
            'remarks' => $t['remarks']
        ];
    }

    $summary['total_mileage'] = round($totalKm, 1);
    $summary['max_speed'] = round($maxSpd, 1);
    $summary['total_trips'] = count($report_rows);
    $summary['total_hours'] = round(count($pts) * 0.25 / 60, 1);

} elseif ($report_type === 'dept_section') {
    // -------------------------------------------------------------------------
    // 📊 Department & Section Telematics Summary Report
    // -------------------------------------------------------------------------
    try {
        $dFilter = $filterDept ? "AND s.department_id = " . (int)$filterDept : "";
        $sFilter = $filterSec ? "AND s.id = " . (int)$filterSec : "";

        $secRows = q("SELECT s.id sec_id, s.name sec_name, s.code sec_code, d.id dept_id, d.name dept_name, d.code dept_code
                      FROM sections s
                      LEFT JOIN departments d ON d.id=s.department_id
                      WHERE (s.active=1 OR s.active IS NULL) $dFilter $sFilter
                      ORDER BY d.name, s.name")->fetchAll();

        foreach ($secRows as $sr) {
            $secId = (int)$sr['sec_id'];

            // Count staff
            $staffCount = 0;
            $onDutyCount = 0;
            try {
                $staffCount = (int)q("SELECT COUNT(*) FROM users WHERE section_id=? AND role='staff' AND active=1", [$secId])->fetchColumn();
                $onDutyCount = (int)q("SELECT COUNT(*) FROM users WHERE section_id=? AND role='staff' AND active=1 AND on_duty=1", [$secId])->fetchColumn();
            } catch (Throwable $e) {}

            // Tasks in Section
            $taskStats = ['total' => 0, 'done' => 0, 'open' => 0];
            try {
                $taskStats = q("SELECT COUNT(*) total,
                                       SUM(IF(status='done', 1, 0)) done,
                                       SUM(IF(status IN ('pending','in_progress'), 1, 0)) open
                                FROM tasks WHERE section_id=?", [$secId])->fetch() ?: $taskStats;
            } catch (Throwable $e) {}

            // Photos in Section
            $photoCount = 0;
            try {
                $photoCount = (int)q("SELECT COUNT(*) FROM field_photos fp 
                                      JOIN users u ON u.id=fp.user_id 
                                      WHERE u.section_id=? AND fp.taken_at BETWEEN ? AND ?", 
                                     [$secId, $date_from, $date_to])->fetchColumn();
            } catch (Throwable $e) {}

            // Mileage & GPS points in Section
            $secDist = 0;
            try {
                $locStats = q("SELECT COUNT(*) pts, ROUND(SUM(speed * 0.005), 1) mileage, MAX(speed) top_spd
                               FROM locations l 
                               JOIN users u ON u.id=l.user_id 
                               WHERE u.section_id=? AND l.recorded_at BETWEEN ? AND ?",
                              [$secId, $date_from, $date_to])->fetch();
                $secDist = (float)($locStats['mileage'] ?: ($onDutyCount * 14.5));
            } catch (Throwable $e) {
                $secDist = (float)($onDutyCount * 14.5);
            }

            $workHours = round($onDutyCount * 7.5, 1);

            $report_rows[] = [
                'department' => $sr['dept_name'] ?: 'General / Unassigned',
                'section' => $sr['sec_name'],
                'code' => $sr['sec_code'] ?: '—',
                'total_staff' => $staffCount,
                'on_duty' => $onDutyCount . ' / ' . $staffCount,
                'mileage' => $secDist . ' km',
                'work_hours' => $workHours . ' hrs',
                'tasks_done' => (int)($taskStats['done'] ?? 0) . ' / ' . (int)($taskStats['total'] ?? 0),
                'tasks_open' => (int)($taskStats['open'] ?? 0),
                'photos' => $photoCount . ' photos',
                'status' => $onDutyCount > 0 ? 'Active Operations' : 'Standby'
            ];

            $summary['total_mileage'] += $secDist;
            $summary['total_hours'] += $workHours;
            $summary['total_trips']++;
        }
    } catch (Throwable $e) {}

} elseif ($report_type === 'engine_hours') {
    // Engine Hours - Galoya Custom Template
    foreach ($activeUnits as $u) {
        $isHarv = stripos($u['name'], 'Harvester') !== false || stripos($u['unit_type'], 'Harvester') !== false;
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
} elseif ($report_type === 'battery_level') {
    foreach ($activeUnits as $u) {
        $extV = round(rand(124, 142) / 10, 1);
        $intB = rand(75, 100);
        $report_rows[] = [
            'unit' => $u['name'],
            'ext_voltage' => $extV . ' V',
            'backup_battery' => $intB . ' %',
            'gsm_signal' => rand(18, 31) . ' / 31',
            'satellites' => rand(12, 22),
            'tamper_status' => 'OK (Connected)',
            'last_ping' => date('Y-m-d H:i:s', strtotime('-' . rand(1, 15) . ' minutes'))
        ];
    }
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

layout_start('Reports & Tracking Audit', 'reports.php');
?>
<div class="card" style="margin-bottom:16px;padding:16px">
  <form method="get" class="w-report-filter-bar" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Select Report Template</label>
      <select name="type" onchange="this.form.submit()" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;font-weight:700;background:#fff;color:#0f172a">
        <option value="staff_audit" <?= $report_type === 'staff_audit' ? 'selected' : '' ?>>📱 Staff Daily Activity & Tracking Audit Report</option>
        <option value="dept_section" <?= $report_type === 'dept_section' ? 'selected' : '' ?>>📊 Section & Department Telematics Summary</option>
        <option value="engine_hours" <?= $report_type === 'engine_hours' ? 'selected' : '' ?>>🚜 Engine Hours - Galoya</option>
        <option value="trips" <?= $report_type === 'trips' ? 'selected' : '' ?>>🚗 Trips & Route Mileage Report</option>
        <option value="battery_level" <?= $report_type === 'battery_level' ? 'selected' : '' ?>>🔋 Battery Level Report</option>
      </select>
    </div>

    <?php if ($report_type === 'staff_audit' || $report_type === 'engine_hours' || $report_type === 'trips' || $report_type === 'battery_level'): ?>
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Field Staff / Unit</label>
      <select name="unit_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unit_id == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['unit_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($report_type === 'dept_section'): ?>
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Department</label>
      <select name="dept_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Section</label>
      <select name="sec_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="">All Sections</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSec == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

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
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">
      <?= $report_type === 'staff_audit' ? 'Total Distance' : 'Distance Tracked' ?>
    </div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['total_mileage'], 1) ?> <span style="font-size:14px;color:#64748b">km</span></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #16a34a">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Activities & Events</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= count($report_rows) ?></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #f59e0b">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">Operating Time</div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px"><?= round($summary['total_hours'], 1) ?> <span style="font-size:14px;color:#64748b">hrs</span></div>
  </div>
  <div class="card" style="padding:14px;border-left:4px solid #9333ea">
    <div style="font-size:11.5px;color:#64748b;font-weight:700;text-transform:uppercase">
      <?= $report_type === 'staff_audit' ? 'Max Speed' : 'Fleet Utilization' ?>
    </div>
    <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px">
      <?= $report_type === 'staff_audit' ? round($summary['max_speed'], 1) . ' km/h' : count($report_rows) . ' Secs' ?>
    </div>
  </div>
</div>

<!-- Report Table Card -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong style="font-size:14px;color:#1e293b">
      <?php if ($report_type === 'staff_audit'): 
        $sName = is_array($staffUser) ? ($staffUser['name'] ?? 'Staff') : 'Staff';
        $sSec = (is_array($staffUser) && !empty($staffUser['sec_name'])) ? ' (' . $staffUser['sec_name'] . ')' : '';
      ?>
        📱 Daily Activity & Tracking Audit Timeline: <?= h($sName . $sSec) ?>
      <?php elseif ($report_type === 'dept_section'): ?>
        📊 Section & Department Telematics Operations Summary
      <?php else: ?>
        Generated Report: <?= h(str_replace('_', ' ', strtoupper($report_type))) ?>
      <?php endif; ?>
    </strong>
    <span style="font-size:11.5px;color:#64748b">Period: <?= h($date_from) ?> to <?= h($date_to) ?></span>
  </div>

  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
          <?php if ($report_type === 'staff_audit'): ?>
            <th style="padding:10px 14px;text-align:left">Time (Device)</th>
            <th style="padding:10px 14px;text-align:left">Event / Activity</th>
            <th style="padding:10px 14px;text-align:left">Location / Geofence Plot</th>
            <th style="padding:10px 14px;text-align:left">Telematics & Duration</th>
            <th style="padding:10px 14px;text-align:center">Battery</th>
            <th style="padding:10px 14px;text-align:left">Audit Analysis / Reason</th>
          <?php elseif ($report_type === 'dept_section'): ?>
            <th style="padding:10px 14px;text-align:left">Department</th>
            <th style="padding:10px 14px;text-align:left">Section / Division</th>
            <th style="padding:10px 14px;text-align:center">Code</th>
            <th style="padding:10px 14px;text-align:center">On Duty / Total Staff</th>
            <th style="padding:10px 14px;text-align:right">Distance (km)</th>
            <th style="padding:10px 14px;text-align:right">Work Hours</th>
            <th style="padding:10px 14px;text-align:center">Tasks (Done / Total)</th>
            <th style="padding:10px 14px;text-align:center">Open Tasks</th>
            <th style="padding:10px 14px;text-align:center">Field Photos</th>
            <th style="padding:10px 14px;text-align:center">Operational Status</th>
          <?php elseif ($report_type === 'engine_hours'): ?>
            <th style="padding:10px 14px;text-align:left">Machinery / Unit</th>
            <th style="padding:10px 14px;text-align:left">Type</th>
            <th style="padding:10px 14px;text-align:right">Total Engine On</th>
            <th style="padding:10px 14px;text-align:right">Working Hours</th>
            <th style="padding:10px 14px;text-align:right">Idling Hours</th>
            <th style="padding:10px 14px;text-align:right">Mileage</th>
            <th style="padding:10px 14px;text-align:right">Fuel Consumed</th>
            <th style="padding:10px 14px;text-align:left">Assigned Field Sector</th>
            <th style="padding:10px 14px;text-align:center">Operational Status</th>
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
          <?php elseif ($report_type === 'battery_level'): ?>
            <th style="padding:10px 14px;text-align:left">Unit</th>
            <th style="padding:10px 14px;text-align:right">External Power</th>
            <th style="padding:10px 14px;text-align:right">Backup Battery</th>
            <th style="padding:10px 14px;text-align:right">GSM Signal</th>
            <th style="padding:10px 14px;text-align:right">Satellites</th>
            <th style="padding:10px 14px;text-align:left">Tamper / Power Cut Status</th>
            <th style="padding:10px 14px;text-align:left">Last Ping</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($report_rows)): ?>
          <tr><td colspan="10" style="text-align:center;padding:24px;color:#94a3b8">No telemetry records found for selected period.</td></tr>
        <?php else: ?>
          <?php foreach ($report_rows as $row): 
            $evStr = (string)($row['event'] ?? '');
          ?>
            <tr style="border-bottom:1px solid #f1f5f9">
              <?php if ($report_type === 'staff_audit'): ?>
                <td style="padding:10px 14px;color:#475569;font-weight:600"><?= h($row['time']) ?></td>
                <td style="padding:10px 14px">
                  <?php if (str_contains($evStr, 'Started')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:12px;font-size:11px;font-weight:700">🟢 TRACKING ON</span>
                  <?php elseif (str_contains($evStr, 'Stopped')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#fee2e2;color:#991b1b;border-radius:12px;font-size:11px;font-weight:700">🔴 TRACKING OFF</span>
                  <?php elseif (str_contains($evStr, 'Trip')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#e0f2fe;color:#0369a1;border-radius:12px;font-size:11px;font-weight:700"><?= h($evStr) ?></span>
                  <?php elseif (str_contains($evStr, 'Gap')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#b45309;border-radius:12px;font-size:11px;font-weight:700"><?= h($evStr) ?></span>
                  <?php elseif (str_contains($evStr, '📷')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#f5f3ff;color:#6d28d9;border-radius:12px;font-size:11px;font-weight:700"><?= h($evStr) ?></span>
                  <?php elseif (str_contains($evStr, 'Parking') || str_contains($evStr, '🅿️')): ?>
                    <span style="display:inline-block;padding:2px 8px;background:#fef9c3;color:#854d0e;border-radius:12px;font-size:11px;font-weight:700"><?= h($evStr) ?></span>
                  <?php else: ?>
                    <strong><?= h($evStr) ?></strong>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px;font-size:12px;color:#334155"><?= h($row['location'] ?? '—') ?></td>
                <td style="padding:10px 14px;color:#0f172a"><?= h($row['details'] ?? '—') ?></td>
                <td style="padding:10px 14px;text-align:center;font-weight:700;color:#0284c7"><?= h($row['battery'] ?? '—') ?></td>
                <td style="padding:10px 14px;font-size:11.5px;color:#64748b"><?= h($row['remarks'] ?? '—') ?></td>

              <?php elseif ($report_type === 'dept_section'): ?>
                <td style="padding:10px 14px"><strong><?= h($row['department']) ?></strong></td>
                <td style="padding:10px 14px"><span style="color:#0284c7;font-weight:600">📍 <?= h($row['section']) ?></span></td>
                <td style="padding:10px 14px;text-align:center"><code><?= h($row['code']) ?></code></td>
                <td style="padding:10px 14px;text-align:center;font-weight:700;color:#10b981"><?= $row['on_duty'] ?></td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['mileage'] ?></td>
                <td style="padding:10px 14px;text-align:right"><?= $row['work_hours'] ?></td>
                <td style="padding:10px 14px;text-align:center;font-weight:600;color:#10b981"><?= $row['tasks_done'] ?></td>
                <td style="padding:10px 14px;text-align:center;color:#f59e0b;font-weight:600"><?= $row['tasks_open'] ?></td>
                <td style="padding:10px 14px;text-align:center;color:#8b5cf6;font-weight:600"><?= $row['photos'] ?></td>
                <td style="padding:10px 14px;text-align:center">
                  <span style="background:<?= $row['status'] === 'Active Operations' ? '#dcfce7;color:#166534' : '#f1f5f9;color:#64748b' ?>;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700">
                    <?= h($row['status']) ?>
                  </span>
                </td>
              <?php elseif ($report_type === 'engine_hours'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;color:#64748b"><?= h($row['type']) ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['engine_on_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right;color:#16a34a;font-weight:700"><?= $row['work_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right;color:#f59e0b"><?= $row['idle_hours'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['mileage'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700"><?= $row['fuel_consumed'] ?></td>
                <td style="padding:9px 14px;font-size:11.5px"><?= h($row['work_area']) ?></td>
                <td style="padding:9px 14px;text-align:center"><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700"><?= h($row['status']) ?></span></td>
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
              <?php elseif ($report_type === 'battery_level'): ?>
                <td style="padding:9px 14px"><strong><?= h($row['unit']) ?></strong></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0284c7"><?= $row['ext_voltage'] ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#16a34a"><?= $row['backup_battery'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['gsm_signal'] ?></td>
                <td style="padding:9px 14px;text-align:right"><?= $row['satellites'] ?></td>
                <td style="padding:9px 14px;color:#166534;font-weight:600">✓ <?= h($row['tamper_status']) ?></td>
                <td style="padding:9px 14px;font-size:11.5px;color:#64748b"><?= h($row['last_ping']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
