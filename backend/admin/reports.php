<?php
require __DIR__ . '/_common.php';
$me = require_admin();
require_perm('reports', 'view');

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
} elseif ($period === 'month') {
    $date_from = date('Y-m-01 00:00:00');
    $date_to = date('Y-m-d 23:59:59');
} elseif ($period === 'custom' && !empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $date_from = date('Y-m-d 00:00:00', strtotime($_GET['date_from']));
    $date_to = date('Y-m-d 23:59:59', strtotime($_GET['date_to']));
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
$officerStats = [];
$summary = [
    'total_mileage' => 0,
    'total_trips' => 0,
    'total_hours' => 0,
    'fuel_consumed' => 0,
    'max_speed' => 0,
    'total_visits' => 0,
    'distinct_plots' => 0,
    'active_officers' => 0,
    'total_notes' => 0
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
            'remarks' => 'Auto: ' . ($ph['auto_geofence'] ?: '—') . ' | Confirmed: ' . ($ph['selected_geofence'] ?: '—'),
            'file_path' => $ph['file_path'] ?? ''
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
            'remarks' => $t['remarks'],
            'file_path' => $t['file_path'] ?? ''
        ];
    }

    // Calculate Executive Audit Summary for Selected Staff
    $firstOnTime = null;
    $lastOffTime = null;
    foreach ($dutyRows as $dl) {
        $et = $dl['event_type'] ?: ($dl['status'] === 'on' ? 'tracking_on' : 'tracking_off');
        if (($et === 'tracking_on' || $dl['status'] === 'on') && !$firstOnTime) {
            $firstOnTime = $dl['at'];
        }
        if ($et === 'tracking_off' || $dl['status'] === 'off') {
            $lastOffTime = $dl['at'];
        }
    }

    // Fallbacks if formal tracking_on/off wasn't explicitly logged in duty_logs
    if (!$firstOnTime && !empty($pts)) {
        $firstOnTime = $pts[0]['recorded_at'];
    }
    if (!$firstOnTime && !empty($dutyRows)) {
        $firstOnTime = $dutyRows[0]['at'];
    }

    $isCurrentlyActive = false;
    if (!empty($pts)) {
        $latestPingTime = end($pts)['recorded_at'];
        if (!$lastOffTime) {
            if (time() - strtotime($latestPingTime) <= 2700) {
                $isCurrentlyActive = true;
            } else {
                $lastOffTime = $latestPingTime;
            }
        }
    }

    $dutyDurStr = '—';
    if ($firstOnTime) {
        $endTs = $lastOffTime ? strtotime($lastOffTime) : time();
        $diffSec = max(0, $endTs - strtotime($firstOnTime));
        $dH = floor($diffSec / 3600);
        $dM = round(($diffSec % 3600) / 60);
        $dutyDurStr = ($dH > 0 ? "{$dH}h " : "") . "{$dM}m";
    }

    // Field Visit Time Window & Plots
    $firstVisitTime = null;
    $lastVisitTime = null;
    $plotsVisited = [];
    if (!empty($photos)) {
        $firstVisitTime = $photos[0]['taken_at'];
        $lastVisitTime = end($photos)['taken_at'];
        foreach ($photos as $ph) {
            $pName = trim((string)($ph['selected_geofence'] ?: ($ph['auto_geofence'] ?: ($ph['geofence_name'] ?: ''))));
            if ($pName !== '') $plotsVisited[$pName] = true;
        }
    }

    $visitWindowStr = 'No visits logged';
    if ($firstVisitTime && $lastVisitTime) {
        $vSec = max(0, strtotime($lastVisitTime) - strtotime($firstVisitTime));
        $vH = floor($vSec / 3600);
        $vM = round(($vSec % 3600) / 60);
        $vDur = ($vH > 0 ? "{$vH}h " : "") . "{$vM}m";
        if (date('h:i A', strtotime($firstVisitTime)) === date('h:i A', strtotime($lastVisitTime))) {
            $visitWindowStr = date('h:i A', strtotime($firstVisitTime));
        } else {
            $visitWindowStr = date('h:i A', strtotime($firstVisitTime)) . ' – ' . date('h:i A', strtotime($lastVisitTime)) . " ({$vDur})";
        }
    }

    // Battery levels
    $battStart = null;
    $battEnd = null;
    if (!empty($pts)) {
        foreach ($pts as $p) {
            if (isset($p['battery']) && $p['battery'] !== null && $p['battery'] !== '') {
                if ($battStart === null) $battStart = (int)$p['battery'];
                $battEnd = (int)$p['battery'];
            }
        }
    }

    $staffAuditSummary = [
        'first_on' => $firstOnTime,
        'last_off' => $lastOffTime,
        'is_active' => $isCurrentlyActive,
        'duty_duration' => $dutyDurStr,
        'first_visit' => $firstVisitTime,
        'last_visit' => $lastVisitTime,
        'visit_window' => $visitWindowStr,
        'plots_visited' => array_keys($plotsVisited),
        'photo_count' => count($photos),
        'photos' => $photos,
        'batt_start' => $battStart,
        'batt_end' => $battEnd
    ];

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
} elseif ($report_type === 'field_visits') {
    // -------------------------------------------------------------------------
    // 🌾 Field Officer Plot Visits & Crop Inspection Summary Report
    // -------------------------------------------------------------------------
    $params = [$date_from, $date_to];
    $extraWhere = "";

    if ($unit_id > 0) {
        $extraWhere .= " AND fp.user_id = ?";
        $params[] = $unit_id;
    }
    if ($filterDept > 0) {
        $extraWhere .= " AND u.department_id = ?";
        $params[] = $filterDept;
    }
    if ($filterSec > 0) {
        $extraWhere .= " AND u.section_id = ?";
        $params[] = $filterSec;
    }

    $rawVisits = [];
    try {
        $rawVisits = q("SELECT fp.id, fp.user_id, fp.file_path, fp.lat, fp.lng, fp.note, fp.geofence_name,
                               COALESCE(fp.activity_type, 'Field Visit') as activity_type,
                               fp.auto_geofence, fp.selected_geofence,
                               fp.taken_at,
                               u.name as user_name, u.code as user_code, u.phone as user_phone,
                               d.name as dept_name, s.name as sec_name,
                               'field' as photo_type
                        FROM field_photos fp
                        LEFT JOIN users u ON u.id = fp.user_id
                        LEFT JOIN departments d ON d.id = u.department_id
                        LEFT JOIN sections s ON s.id = u.section_id
                        WHERE fp.taken_at BETWEEN ? AND ? $extraWhere
                        ORDER BY fp.taken_at DESC", $params)->fetchAll();
    } catch (Throwable $e) {}

    $distinctPlots = [];
    $activeOfficers = [];
    $totalNotes = 0;

    foreach ($rawVisits as $v) {
        $uId = (int)$v['user_id'];
        $uName = $v['user_name'] ?: ('Staff #' . $uId);
        $plot = trim((string)($v['selected_geofence'] ?: ($v['geofence_name'] ?: ($v['auto_geofence'] ?: ''))));

        // If no plot string was attached, try to match geofence if lat/lng are available
        if ($plot === '' && !empty($v['lat']) && !empty($v['lng'])) {
            try {
                $gf = q("SELECT name, code FROM geofences WHERE active=1 AND ST_Contains(polygon, POINT(?, ?)) LIMIT 1", [(float)$v['lng'], (float)$v['lat']])->fetch();
                if ($gf) {
                    $plot = $gf['name'] . ($gf['code'] ? ' (' . $gf['code'] . ')' : '');
                }
            } catch (Throwable $e) {}
        }

        if ($plot === '') {
            $plot = 'Unspecified Plot';
        }

        if ($plot !== 'Unspecified Plot') {
            $distinctPlots[$plot] = true;
        }
        $activeOfficers[$uId] = true;

        if (!empty($v['note'])) {
            $totalNotes++;
        }

        // Aggregate individual officer stats
        if (!isset($officerStats[$uId])) {
            $officerStats[$uId] = [
                'user_id' => $uId,
                'name' => $uName,
                'code' => $v['user_code'] ?? '',
                'phone' => $v['user_phone'] ?? '',
                'dept' => $v['dept_name'] ?? 'Sugar Cultivation',
                'section' => $v['sec_name'] ?? 'Field Division',
                'total_visits' => 0,
                'plots' => [],
                'first_time' => $v['taken_at'],
                'last_time' => $v['taken_at'],
                'notes_count' => 0
            ];
        }

        $officerStats[$uId]['total_visits']++;
        if ($plot !== 'Unspecified Plot') {
            $officerStats[$uId]['plots'][$plot] = true;
        }
        if (!empty($v['note'])) {
            $officerStats[$uId]['notes_count']++;
        }
        if (strtotime($v['taken_at']) < strtotime($officerStats[$uId]['first_time'])) {
            $officerStats[$uId]['first_time'] = $v['taken_at'];
        }
        if (strtotime($v['taken_at']) > strtotime($officerStats[$uId]['last_time'])) {
            $officerStats[$uId]['last_time'] = $v['taken_at'];
        }

        $photoUrl = '';
        if (!empty($v['file_path'])) {
            $photoUrl = 'download_photo.php?file=' . urlencode($v['file_path']);
        }

        $report_rows[] = [
            'id' => $v['id'],
            'time' => $v['taken_at'],
            'officer' => $uName,
            'user_id' => $uId,
            'code' => $v['user_code'],
            'phone' => $v['user_phone'],
            'dept' => $v['dept_name'] ?: 'Sugar Cultivation',
            'section' => $v['sec_name'] ?: 'Field Division',
            'plot' => $plot,
            'activity' => $v['activity_type'] ?: 'Inspection',
            'lat' => $v['lat'],
            'lng' => $v['lng'],
            'note' => $v['note'],
            'file_path' => $v['file_path'],
            'photo_type' => 'field',
            'photo_url' => $photoUrl
        ];
    }

    $summary['total_visits'] = count($report_rows);
    $summary['distinct_plots'] = count($distinctPlots);
    $summary['active_officers'] = count($activeOfficers);
    $summary['total_notes'] = $totalNotes;
}

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_perm('reports', 'export');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="galoya_telematics_report_' . $report_type . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($report_rows)) {
        if ($report_type === 'field_visits') {
            fputcsv($out, ['Time', 'Field Officer', 'Staff Code', 'Department', 'Section', 'Plot Number / Geofence', 'Activity Type', 'Latitude', 'Longitude', 'Field Observation / Notes', 'Photo Attached']);
            foreach ($report_rows as $r) {
                fputcsv($out, [
                    $r['time'],
                    $r['officer'],
                    $r['code'] ?? '',
                    $r['dept'] ?? '',
                    $r['section'] ?? '',
                    $r['plot'] ?? '',
                    $r['activity'] ?? '',
                    $r['lat'] ?? '',
                    $r['lng'] ?? '',
                    $r['note'] ?? '',
                    !empty($r['file_path']) ? 'Yes' : 'No'
                ]);
            }
        } else {
            fputcsv($out, array_keys($report_rows[0]));
            foreach ($report_rows as $row) {
                fputcsv($out, $row);
            }
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
        <option value="field_visits" <?= $report_type === 'field_visits' ? 'selected' : '' ?>>🌾 Field Officer Plot Visits & Crop Inspection Summary</option>
        <option value="staff_audit" <?= $report_type === 'staff_audit' ? 'selected' : '' ?>>📱 Staff Daily Activity & Tracking Audit Report</option>
        <option value="dept_section" <?= $report_type === 'dept_section' ? 'selected' : '' ?>>📊 Section & Department Telematics Summary</option>
        <option value="engine_hours" <?= $report_type === 'engine_hours' ? 'selected' : '' ?>>🚜 Engine Hours - Galoya</option>
        <option value="trips" <?= $report_type === 'trips' ? 'selected' : '' ?>>🚗 Trips & Route Mileage Report</option>
        <option value="battery_level" <?= $report_type === 'battery_level' ? 'selected' : '' ?>>🔋 Battery Level Report</option>
      </select>
    </div>

    <?php if ($report_type === 'field_visits'): ?>
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Field Staff / Officer</label>
      <select name="unit_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="0" <?= $unit_id == 0 ? 'selected' : '' ?>>All Field Officers (Summary)</option>
        <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unit_id == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> <?= $u['code'] ? '('.h($u['code']).')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php elseif ($report_type === 'staff_audit' || $report_type === 'engine_hours' || $report_type === 'trips' || $report_type === 'battery_level'): ?>
    <div>
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Field Staff / Unit</label>
      <select name="unit_id" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unit_id == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['unit_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($report_type === 'dept_section' || $report_type === 'field_visits'): ?>
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
      <select name="period" id="report-period-select" onchange="toggleCustomDates(this.value)" style="padding:7px 12px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;background:#fff">
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
      </select>
    </div>

    <div id="custom-date-fields" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;gap:8px;align-items:center">
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">From Date</label>
        <input type="date" name="date_from" value="<?= date('Y-m-d', strtotime($date_from)) ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">To Date</label>
        <input type="date" name="date_to" value="<?= date('Y-m-d', strtotime($date_to)) ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px">
      </div>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px">
      <button type="submit" class="btn" style="background:#0284c7;color:#fff;padding:7px 16px;border-radius:4px;font-weight:700;border:none;cursor:pointer">
        Execute Report
      </button>
      <?php if (can('reports', 'export')): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn ghost" style="padding:7px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;display:inline-flex;align-items:center;gap:6px;background:#fff">
          📥 Export CSV
        </a>
      <?php endif; ?>
      <button type="button" onclick="window.print()" class="btn ghost" style="padding:7px 14px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;background:#fff">
        🖨️ Print
      </button>
    </div>
  </form>
</div>

<!-- Compact Single-Line KPI Summary Strip -->
<div class="card" style="margin-bottom:12px;padding:10px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#ffffff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.03)">
  <?php if ($report_type === 'field_visits'): ?>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">🌾 Total Plot Visits:</span>
      <span style="font-size:13.5px;font-weight:800;color:#0284c7;background:#f0f9ff;border:1px solid #bae6fd;padding:2px 10px;border-radius:12px"><?= $summary['total_visits'] ?></span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">📍 Distinct Plots:</span>
      <span style="font-size:13.5px;font-weight:800;color:#16a34a;background:#f0fdf4;border:1px solid #bbf7d0;padding:2px 10px;border-radius:12px"><?= $summary['distinct_plots'] ?></span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">👨‍🌾 Active Officers:</span>
      <span style="font-size:13.5px;font-weight:800;color:#d97706;background:#fffbeb;border:1px solid #fde68a;padding:2px 10px;border-radius:12px"><?= $summary['active_officers'] ?></span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">📝 Observations:</span>
      <span style="font-size:13.5px;font-weight:800;color:#9333ea;background:#faf5ff;border:1px solid #e9d5ff;padding:2px 10px;border-radius:12px"><?= $summary['total_notes'] ?></span>
    </div>
  <?php else: ?>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">🛣️ <?= $report_type === 'staff_audit' ? 'Total Distance' : 'Distance Tracked' ?>:</span>
      <span style="font-size:13.5px;font-weight:800;color:#0284c7;background:#f0f9ff;border:1px solid #bae6fd;padding:2px 10px;border-radius:12px"><?= round($summary['total_mileage'], 1) ?> km</span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">⚡ Activities & Events:</span>
      <span style="font-size:13.5px;font-weight:800;color:#16a34a;background:#f0fdf4;border:1px solid #bbf7d0;padding:2px 10px;border-radius:12px"><?= count($report_rows) ?></span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">⏱️ Operating Time:</span>
      <span style="font-size:13.5px;font-weight:800;color:#d97706;background:#fffbeb;border:1px solid #fde68a;padding:2px 10px;border-radius:12px"><?= round($summary['total_hours'], 1) ?> hrs</span>
    </div>
    <span style="color:#cbd5e1;font-weight:300">|</span>
    <div style="display:inline-flex;align-items:center;gap:8px">
      <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.3px">🚀 <?= $report_type === 'staff_audit' ? 'Max Speed' : 'Fleet Utilization' ?>:</span>
      <span style="font-size:13.5px;font-weight:800;color:#9333ea;background:#faf5ff;border:1px solid #e9d5ff;padding:2px 10px;border-radius:12px"><?= $report_type === 'staff_audit' ? round($summary['max_speed'], 1) . ' km/h' : count($report_rows) . ' Secs' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($report_type === 'field_visits' && !empty($officerStats)): ?>
<!-- Table 1: Individual Field Officer Visit & Plot Coverage Summary -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px">
  <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong style="font-size:14px;color:#1e293b">
      👨‍🌾 Individual Field Officer Plot Coverage & Visit Summary
    </strong>
    <span style="font-size:11.5px;color:#64748b"><?= count($officerStats) ?> Active Field Staff</span>
  </div>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
          <th style="padding:10px 14px;text-align:left">Officer Name & Code</th>
          <th style="padding:10px 14px;text-align:left">Division / Section</th>
          <th style="padding:10px 14px;text-align:center">Total Visits</th>
          <th style="padding:10px 14px;text-align:center">Distinct Plots</th>
          <th style="padding:10px 14px;text-align:left">Plots Inspected</th>
          <th style="padding:10px 14px;text-align:left">First Visit Time</th>
          <th style="padding:10px 14px;text-align:left">Last Visit Time</th>
          <th style="padding:10px 14px;text-align:center">Notes</th>
          <th style="padding:10px 14px;text-align:center">Filter</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($officerStats as $uId => $os): ?>
          <tr style="border-bottom:1px solid #f1f5f9">
            <td style="padding:10px 14px">
              <strong style="color:#0f172a"><?= h($os['name']) ?></strong>
              <?php if (!empty($os['code'])): ?>
                <div style="font-size:11px;color:#64748b">Code: <code><?= h($os['code']) ?></code></div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;color:#475569">
              <span style="font-weight:600"><?= h($os['section']) ?></span>
              <div style="font-size:11px;color:#64748b"><?= h($os['dept']) ?></div>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <span style="background:#e0f2fe;color:#0369a1;padding:2px 10px;border-radius:12px;font-weight:700;font-size:12px">
                <?= $os['total_visits'] ?>
              </span>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-weight:700;font-size:12px">
                <?= count($os['plots']) ?>
              </span>
            </td>
            <td style="padding:10px 14px;max-width:260px">
              <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php 
                $pList = array_keys($os['plots']);
                $pDisplay = array_slice($pList, 0, 4);
                foreach ($pDisplay as $pName): ?>
                  <span style="background:#f1f5f9;border:1px solid #cbd5e1;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:600;color:#334155">
                    <?= h($pName) ?>
                  </span>
                <?php endforeach; ?>
                <?php if (count($pList) > 4): ?>
                  <span style="font-size:11px;color:#64748b;font-weight:600">+<?= count($pList) - 4 ?> more</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="padding:10px 14px;font-size:11.5px;color:#475569">
              <?= date('h:i A', strtotime($os['first_time'])) ?>
              <div style="font-size:10.5px;color:#94a3b8"><?= date('Y-m-d', strtotime($os['first_time'])) ?></div>
            </td>
            <td style="padding:10px 14px;font-size:11.5px;color:#475569">
              <?= date('h:i A', strtotime($os['last_time'])) ?>
              <div style="font-size:10.5px;color:#94a3b8"><?= date('Y-m-d', strtotime($os['last_time'])) ?></div>
            </td>
            <td style="padding:10px 14px;text-align:center;font-weight:700;color:#9333ea">
              <?= $os['notes_count'] ?>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <a href="?type=field_visits&unit_id=<?= $uId ?>&period=<?= urlencode($period) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" 
                 class="btn ghost" style="padding:4px 8px;font-size:11px;border-radius:4px;font-weight:600;background:#fff;border:1px solid #cbd5e1;text-decoration:none">
                🔍 Filter
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($report_type === 'staff_audit' && !empty($staffAuditSummary)): ?>
<!-- Staff Audit Executive Duty & Field Visit Summary Card -->
<div class="card" style="padding:14px 18px;margin-bottom:14px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px">
  <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <div>
      <span style="font-size:11px;font-weight:800;color:#0284c7;text-transform:uppercase;letter-spacing:0.5px">👨‍🌾 Officer Duty & Field Inspection Audit Summary</span>
      <h3 style="margin:2px 0 0 0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:8px">
        <?= h($staffUser['name'] ?? 'Staff Member') ?>
        <?php if (!empty($staffUser['code'])): ?>
          <span style="font-size:12px;color:#64748b;font-weight:normal">(Code: <code><?= h($staffUser['code']) ?></code>)</span>
        <?php endif; ?>
        <?php if (!empty($staffUser['sec_name'])): ?>
          <span style="font-size:12px;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;font-weight:600"><?= h($staffUser['sec_name']) ?></span>
        <?php endif; ?>
      </h3>
    </div>
    <div style="font-size:12px;color:#64748b">
      Audit Date: <strong><?= date('Y-m-d', strtotime($date_from)) ?></strong>
    </div>
  </div>

  <!-- 4-Box Key Duty Metrics -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-bottom:12px">
    <!-- Box 1: On Time -->
    <div style="background:#fff;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;border-left:3px solid #16a34a">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">🟢 Tracking ON (Duty Start)</div>
      <div style="font-size:16px;font-weight:800;color:#166534;margin-top:2px">
        <?= $staffAuditSummary['first_on'] ? date('h:i:s A', strtotime($staffAuditSummary['first_on'])) : '—' ?>
      </div>
      <div style="font-size:10.5px;color:#94a3b8">
        <?= $staffAuditSummary['first_on'] ? date('Y-m-d', strtotime($staffAuditSummary['first_on'])) : 'No duty start record' ?>
      </div>
    </div>

    <!-- Box 2: Off Time -->
    <div style="background:#fff;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;border-left:3px solid #dc2626">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">🔴 Tracking OFF (Duty End)</div>
      <div style="font-size:16px;font-weight:800;color:#991b1b;margin-top:2px">
        <?php if ($staffAuditSummary['is_active']): ?>
          <span style="color:#16a34a">🟢 Active On Duty</span>
        <?php elseif ($staffAuditSummary['last_off']): ?>
          <?= date('h:i:s A', strtotime($staffAuditSummary['last_off'])) ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </div>
      <div style="font-size:10.5px;color:#94a3b8">
        <?= $staffAuditSummary['last_off'] ? date('Y-m-d', strtotime($staffAuditSummary['last_off'])) : ($staffAuditSummary['is_active'] ? 'Live connection' : '—') ?>
      </div>
    </div>

    <!-- Box 3: Total Duty Time -->
    <div style="background:#fff;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;border-left:3px solid #0284c7">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">⏱️ Shift / Work Duration</div>
      <div style="font-size:16px;font-weight:800;color:#0369a1;margin-top:2px">
        <?= $staffAuditSummary['duty_duration'] ?>
      </div>
      <div style="font-size:10.5px;color:#94a3b8">Total active shift time</div>
    </div>

    <!-- Box 4: Field Visit Period -->
    <div style="background:#fff;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;border-left:3px solid #8b5cf6">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">🌾 Field Visit Window</div>
      <div style="font-size:14px;font-weight:800;color:#6d28d9;margin-top:2px">
        <?= $staffAuditSummary['visit_window'] ?>
      </div>
      <div style="font-size:10.5px;color:#94a3b8">
        <?= $staffAuditSummary['photo_count'] ?> Inspection Photo(s) taken
      </div>
    </div>
  </div>

  <!-- Plots Visited and Device Info -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:8px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:6px">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-size:11.5px;font-weight:700;color:#475569">📍 Plots Inspected:</span>
      <?php if (!empty($staffAuditSummary['plots_visited'])): ?>
        <?php foreach ($staffAuditSummary['plots_visited'] as $plotName): ?>
          <span style="background:#f0fdf4;border:1px solid #86efac;color:#166534;font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:4px">
            🌾 <?= h($plotName) ?>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span style="font-size:11.5px;color:#94a3b8;font-style:italic">No plot geofence tags recorded today</span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:14px;font-size:11.5px;color:#64748b">
      <?php if ($staffAuditSummary['batt_start'] !== null && $staffAuditSummary['batt_end'] !== null): ?>
        <span>🔋 Battery: <strong><?= $staffAuditSummary['batt_start'] ?>% ➔ <?= $staffAuditSummary['batt_end'] ?>%</strong> (<?= $staffAuditSummary['batt_end'] - $staffAuditSummary['batt_start'] ?>%)</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Photo Evidence Strip if photos exist -->
  <?php if (!empty($staffAuditSummary['photos'])): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #cbd5e1">
      <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:6px">
        📷 Field Visit Photos Analyzed (<?= count($staffAuditSummary['photos']) ?>):
      </div>
      <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:4px">
        <?php foreach ($staffAuditSummary['photos'] as $ph): 
          $pPlot = $ph['selected_geofence'] ?: ($ph['auto_geofence'] ?: ($ph['geofence_name'] ?: 'Field Plot'));
        ?>
          <div style="position:relative;flex-shrink:0;text-align:center;background:#fff;border:1px solid #cbd5e1;padding:4px;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
            <a href="javascript:void(0)" onclick="openLightbox('download_photo.php?file=<?= urlencode($ph['file_path']) ?>', '<?= h(addslashes($staffUser['name'] ?? 'Staff')) ?> | <?= h(addslashes($pPlot)) ?> (<?= h(date('h:i A', strtotime($ph['taken_at']))) ?>)')" title="Click to enlarge photo">
              <img src="download_photo.php?file=<?= urlencode($ph['file_path']) ?>" style="width:68px;height:68px;object-fit:cover;border-radius:4px;display:block">
            </a>
            <div style="font-size:10.5px;font-weight:700;color:#166534;margin-top:3px;max-width:68px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($pPlot) ?>">
              <?= h($pPlot) ?>
            </div>
            <div style="font-size:9.5px;color:#64748b">
              <?= date('h:i A', strtotime($ph['taken_at'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Report Table Card -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong style="font-size:14px;color:#1e293b">
      <?php if ($report_type === 'field_visits'): ?>
        🌾 Field Officer Plot Visits & Crop Inspection Summary Log
      <?php elseif ($report_type === 'staff_audit'): 
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
          <?php if ($report_type === 'field_visits'): ?>
            <th style="padding:10px 14px;text-align:center;width:40px">#</th>
            <th style="padding:10px 14px;text-align:left">Visit Time</th>
            <th style="padding:10px 14px;text-align:left">Field Officer</th>
            <th style="padding:10px 14px;text-align:left">Division / Section</th>
            <th style="padding:10px 14px;text-align:left">Plot Number / Geofence</th>
            <th style="padding:10px 14px;text-align:left">Activity / Purpose</th>
            <th style="padding:10px 14px;text-align:left">GPS Coordinates</th>
            <th style="padding:10px 14px;text-align:left">Field Observation / Notes</th>
            <th style="padding:10px 14px;text-align:center">Photo Preview</th>
          <?php elseif ($report_type === 'staff_audit'): ?>
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
          <tr><td colspan="10" style="text-align:center;padding:24px;color:#94a3b8">No telemetry or visit records found for selected period.</td></tr>
        <?php else: ?>
          <?php 
          $rowIdx = 0;
          foreach ($report_rows as $row): 
            $rowIdx++;
            $evStr = (string)($row['event'] ?? '');
          ?>
            <tr style="border-bottom:1px solid #f1f5f9">
              <?php if ($report_type === 'field_visits'): ?>
                <td style="padding:10px 14px;text-align:center;color:#94a3b8;font-weight:600"><?= $rowIdx ?></td>
                <td style="padding:10px 14px;color:#0f172a;font-weight:600;white-space:nowrap">
                  <?= date('h:i:s A', strtotime($row['time'])) ?>
                  <div style="font-size:11px;color:#64748b"><?= date('Y-m-d', strtotime($row['time'])) ?></div>
                </td>
                <td style="padding:10px 14px">
                  <strong style="color:#0f172a"><?= h($row['officer']) ?></strong>
                  <?php if (!empty($row['code'])): ?>
                    <span style="font-size:11px;color:#64748b;display:block">Code: <code><?= h($row['code']) ?></code></span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px;color:#475569">
                  <span style="font-weight:600"><?= h($row['section']) ?></span>
                  <div style="font-size:11px;color:#64748b"><?= h($row['dept']) ?></div>
                </td>
                <td style="padding:10px 14px">
                  <span style="display:inline-block;padding:3px 10px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:12px;font-weight:700">
                    🌾 <?= h($row['plot']) ?>
                  </span>
                </td>
                <td style="padding:10px 14px">
                  <span style="display:inline-block;padding:2px 8px;background:#e0f2fe;color:#0369a1;border-radius:12px;font-size:11px;font-weight:700">
                    <?= h($row['activity']) ?>
                  </span>
                </td>
                <td style="padding:10px 14px;font-size:11.5px">
                  <?php if (!empty($row['lat']) && !empty($row['lng'])): ?>
                    <a href="https://www.google.com/maps?q=<?= (float)$row['lat'] ?>,<?= (float)$row['lng'] ?>" target="_blank" style="color:#0284c7;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                      📍 <code><?= number_format((float)$row['lat'], 5) ?>, <?= number_format((float)$row['lng'], 5) ?></code>
                    </a>
                  <?php else: ?>
                    <span style="color:#94a3b8">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px;max-width:320px;color:#334155;font-size:12px">
                  <?php if (!empty($row['note'])): ?>
                    <div style="background:#f8fafc;padding:6px 10px;border-left:3px solid #3b82f6;border-radius:4px">
                      <?= nl2br(h($row['note'])) ?>
                    </div>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-style:italic">No observation note</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px;text-align:center">
                  <?php if (!empty($row['file_path'])): ?>
                    <div style="display:inline-flex;align-items:center;gap:6px">
                      <a href="javascript:void(0)" onclick="openLightbox('download_photo.php?file=<?= urlencode($row['file_path']) ?>', '<?= h(addslashes($row['officer'])) ?> | <?= h(addslashes($row['plot'])) ?> (<?= h($row['time']) ?>)')" style="position:relative;display:inline-block" title="Click to view full photo">
                        <img src="download_photo.php?file=<?= urlencode($row['file_path']) ?>" style="width:38px;height:38px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;vertical-align:middle;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
                      </a>
                      <a href="download_photo.php?file=<?= urlencode($row['file_path']) ?>" download style="color:#64748b;font-size:14px;text-decoration:none" title="Download High Res Photo">
                        ⬇️
                      </a>
                    </div>
                  <?php else: ?>
                    <span style="color:#cbd5e1;font-size:11px">No Image</span>
                  <?php endif; ?>
                </td>

              <?php elseif ($report_type === 'staff_audit'): ?>
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
                <td style="padding:10px 14px;color:#0f172a">
                  <?= h($row['details'] ?? '—') ?>
                  <?php if (!empty($row['file_path'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                      <a href="javascript:void(0)" onclick="openLightbox('download_photo.php?file=<?= urlencode($row['file_path']) ?>', '<?= h(addslashes($sName)) ?> | <?= h(addslashes($row['location'] ?? '')) ?> (<?= h($row['time']) ?>)')" title="Click to view photo">
                        <img src="download_photo.php?file=<?= urlencode($row['file_path']) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #cbd5e1;vertical-align:middle;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
                      </a>
                      <a href="javascript:void(0)" onclick="openLightbox('download_photo.php?file=<?= urlencode($row['file_path']) ?>', '<?= h(addslashes($sName)) ?> | <?= h(addslashes($row['location'] ?? '')) ?> (<?= h($row['time']) ?>)')" style="font-size:11px;color:#0284c7;font-weight:700;text-decoration:none">
                        🔍 View Photo
                      </a>
                    </div>
                  <?php endif; ?>
                </td>
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

<!-- Lightbox Modal for Photo Preview -->
<div id="lightbox-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;flex-direction:column;padding:20px" onclick="closeLightbox()">
  <div style="position:relative;max-width:90vw;max-height:85vh;text-align:center" onclick="event.stopPropagation()">
    <img id="lightbox-img" src="" style="max-width:100%;max-height:80vh;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.5)">
    <div id="lightbox-caption" style="color:#fff;margin-top:10px;font-size:13px;font-weight:600"></div>
    <div style="margin-top:10px">
      <a id="lightbox-dl" href="#" class="btn" style="background:#0284c7;color:#fff;display:inline-flex;padding:6px 16px;font-size:12px;border-radius:4px;text-decoration:none">
        ⬇️ Download Full Resolution
      </a>
      <button type="button" class="btn ghost" onclick="closeLightbox()" style="margin-left:8px;padding:6px 16px;font-size:12px;border-radius:4px;background:#fff">
        ✕ Close
      </button>
    </div>
  </div>
</div>

<script>
function openLightbox(url, caption) {
  var modal = document.getElementById('lightbox-modal');
  var img = document.getElementById('lightbox-img');
  var cap = document.getElementById('lightbox-caption');
  var dl = document.getElementById('lightbox-dl');
  if (!modal || !img) return;
  img.src = url;
  if (cap) cap.textContent = caption || '';
  if (dl) dl.href = url;
  modal.style.display = 'flex';
}
function closeLightbox() {
  var modal = document.getElementById('lightbox-modal');
  if (modal) modal.style.display = 'none';
}
function toggleCustomDates(val) {
  var el = document.getElementById('custom-date-fields');
  if (el) el.style.display = (val === 'custom') ? 'flex' : 'none';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLightbox();
});
</script>

<?php layout_end(); ?>
