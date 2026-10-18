<?php
require __DIR__ . '/_common.php';
require_admin();

// Fetch all units for dropdown
$staff = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY (code IS NOT NULL) DESC, name ASC")->fetchAll();

$default_user = $staff[0]['id'] ?? 0;
// If 49-1633 exists, default to it (matching user's screenshot)
foreach ($staff as $s) {
    if (strpos($s['name'], '49-1633') !== false || $s['code'] === '49-1633') {
        $default_user = $s['id'];
        break;
    }
}

$uid = (int)($_GET['user'] ?? $default_user);
$from_dt = $_GET['from'] ?? date('Y-m-d 00:00:00');
$to_dt = $_GET['to'] ?? date('Y-m-d 23:59:59');
$color_mode = $_GET['color_mode'] ?? 'trips';
$thickness = (int)($_GET['thickness'] ?? 3);
$show_annotations = isset($_GET['show_annotations']) ? (bool)$_GET['show_annotations'] : true;

// Fetch track points
$pts = q("SELECT lat, lng, speed, battery, recorded_at 
          FROM locations 
          WHERE user_id=? AND recorded_at BETWEEN ? AND ? 
          ORDER BY recorded_at ASC",
         [$uid, $from_dt, $to_dt])->fetchAll();

// Fetch photos taken during this track interval
$track_photos = [];
try {
    $fp = q("SELECT id, user_id, file_path, lat, lng, note, geofence_name, taken_at, 'field' as type 
             FROM field_photos 
             WHERE user_id=? AND taken_at BETWEEN ? AND ? AND lat IS NOT NULL AND lng IS NOT NULL",
            [$uid, $from_dt, $to_dt])->fetchAll();
    
    $tp = q("SELECT tp.id, tp.user_id, tp.file_path, tp.lat, tp.lng, 
                    CONCAT('Task #', tp.task_id, ' (', tp.type, ')', IF(t.title IS NOT NULL, CONCAT(' - ', t.title), '')) as note, 
                    NULL as geofence_name,
                    tp.taken_at, 'task' as type 
             FROM task_photos tp 
             LEFT JOIN tasks t ON t.id = tp.task_id
             WHERE tp.user_id=? AND tp.taken_at BETWEEN ? AND ? AND tp.lat IS NOT NULL AND tp.lng IS NOT NULL",
            [$uid, $from_dt, $to_dt])->fetchAll();
    
    $track_photos = array_merge($fp, $tp);
    usort($track_photos, function($a, $b) { return strcmp($a['taken_at'], $b['taken_at']); });
} catch (Throwable $e) {}

// Wialon Standard Trip & Parking Detection Algorithm
$km = 0;
$max_speed = 0;
$speed_sum = 0;
$stops = [];
$trips = [];
$trip_colors = ['#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2', '#e11d48', '#4f46e5'];

function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// 1. Filter stationary jitter & calculate realistic displacement speed
$clean_pts = [];
for ($i = 0; $i < count($pts); $i++) {
    $p = $pts[$i];
    $spd = (float)$p['speed'];

    if ($i > 0) {
        $prev_p = $pts[$i - 1];
        $t_gap = max(1, strtotime($p['recorded_at']) - strtotime($prev_p['recorded_at']));
        $dist_km = haversine_km((float)$prev_p['lat'], (float)$prev_p['lng'], (float)$p['lat'], (float)$p['lng']);
        $dist_m = $dist_km * 1000.0;

        // If moved less than 15 meters and time gap is >= 10s:
        // Reported speed is Doppler GPS noise from being indoors / stationary!
        if ($dist_m < 15.0 && $t_gap >= 10) {
            $spd = 0.0;
        } elseif ($spd < 4.0) {
            $spd = 0.0;
        }
    } else {
        if ($spd < 4.0) $spd = 0.0;
    }

    $p['speed'] = $spd;
    $clean_pts[] = $p;
}

// 2. Segment into Trips (moving) and Stops/Gaps (Wialon / Telematics Industry Standard)
$min_stop_sec = 600; // 10 minutes parking threshold
$max_gap_sec = 600;  // 10 minutes offline gap threshold -> Automatically split trips!
$trips = [];
$stops = [];
$timeline = []; // Chronological list of trips and parking periods

function build_history_trip($pts, $idx, $trip_colors) {
    if (empty($pts) || count($pts) < 2) return null;
    $trip_dist = 0;
    $trip_spd_sum = 0;
    $trip_max_spd = 0;
    for ($j = 1; $j < count($pts); $j++) {
        $trip_dist += haversine_km((float)$pts[$j-1]['lat'], (float)$pts[$j-1]['lng'], (float)$pts[$j]['lat'], (float)$pts[$j]['lng']);
        $s = (float)$pts[$j]['speed'];
        $trip_spd_sum += $s;
        if ($s > $trip_max_spd) $trip_max_spd = $s;
    }

    // Ignore tiny GPS jitter (< 0.15 km / 150m) to prevent breaking parking sessions
    if ($trip_dist < 0.15) {
        return null;
    }

    $t_start = strtotime($pts[0]['recorded_at']);
    $t_end = strtotime($pts[count($pts) - 1]['recorded_at']);
    $t_dur = max(60, $t_end - $t_start);

    return [
        'trip_num' => $idx + 1,
        'color' => $trip_colors[$idx % count($trip_colors)],
        'start_time' => substr($pts[0]['recorded_at'], 11, 8),
        'end_time' => substr($pts[count($pts) - 1]['recorded_at'], 11, 8),
        'distance_km' => round($trip_dist, 2),
        'duration_str' => floor($t_dur / 3600) > 0 ? (floor($t_dur / 3600).'h '.floor(($t_dur % 3600) / 60).'m') : (round($t_dur / 60).' min'),
        'avg_speed' => round($trip_spd_sum / max(1, count($pts) - 1), 1),
        'max_speed' => round($trip_max_spd, 1),
        'points' => $pts
    ];
}

$cur_trip_pts = [];
$cur_stop_pts = [];

for ($i = 0; $i < count($clean_pts); $i++) {
    $p = $clean_pts[$i];
    $spd = (float)$p['speed'];
    if ($spd > $max_speed) $max_speed = $spd;

    // Check for offline gap or sudden teleportation jump between points
    if ($i > 0) {
        $prev_p = $clean_pts[$i - 1];
        $gap_sec = strtotime($p['recorded_at']) - strtotime($prev_p['recorded_at']);
        $dist_jump_km = haversine_km((float)$prev_p['lat'], (float)$prev_p['lng'], (float)$p['lat'], (float)$p['lng']);
        $implied_spd = $gap_sec > 0 ? ($dist_jump_km / ($gap_sec / 3600.0)) : 0;

        if ($gap_sec >= $max_gap_sec || ($gap_sec > 0 && $implied_spd > 140.0)) {
            // Gap detected! Close previous trip cleanly
            if (!empty($cur_trip_pts)) {
                $tr = build_history_trip($cur_trip_pts, count($trips), $trip_colors);
                if ($tr) {
                    $trip_idx = count($trips);
                    $trips[] = $tr;
                    $timeline[] = ['type' => 'trip', 'index' => $trip_idx, 'data' => $tr];
                    $km += $tr['distance_km'];
                }
                $cur_trip_pts = [];
            }
            if (!empty($cur_stop_pts)) {
                $s_first = strtotime($cur_stop_pts[0]['recorded_at']);
                $s_last = strtotime($cur_stop_pts[count($cur_stop_pts) - 1]['recorded_at']);
                $stop_dur = max(0, $s_last - $s_first);
                if ($stop_dur >= $min_stop_sec) {
                    $stop_obj = [
                        'stop_num' => count($stops) + 1,
                        'lat' => (float)$cur_stop_pts[0]['lat'],
                        'lng' => (float)$cur_stop_pts[0]['lng'],
                        'start_time' => date('H:i:s', $s_first),
                        'end_time' => date('H:i:s', $s_last),
                        'duration_sec' => $stop_dur,
                        'duration_str' => floor($stop_dur / 3600) > 0 ? (floor($stop_dur / 3600).'h '.floor(($stop_dur % 3600) / 60).'m') : (round($stop_dur / 60).' min')
                    ];
                    $stops[] = $stop_obj;
                    $timeline[] = ['type' => 'stop', 'data' => $stop_obj];
                }
                $cur_stop_pts = [];
            }

            // Register the gap as an offline / inactive parking session
            $gap_stop = [
                'stop_num' => count($stops) + 1,
                'lat' => (float)$prev_p['lat'],
                'lng' => (float)$prev_p['lng'],
                'start_time' => substr($prev_p['recorded_at'], 11, 8),
                'end_time' => substr($p['recorded_at'], 11, 8),
                'duration_sec' => $gap_sec,
                'duration_str' => floor($gap_sec / 3600) > 0 ? (floor($gap_sec / 3600).'h '.floor(($gap_sec % 3600) / 60).'m') : (round($gap_sec / 60).' min'),
                'note' => 'Offline / Inactivity Gap'
            ];
            $stops[] = $gap_stop;
            $timeline[] = ['type' => 'stop', 'data' => $gap_stop];
        }
    }

    if ($spd > 0) {
        // Moving point
        if (!empty($cur_stop_pts)) {
            $s_first = strtotime($cur_stop_pts[0]['recorded_at']);
            $s_last = strtotime($cur_stop_pts[count($cur_stop_pts) - 1]['recorded_at']);
            $stop_dur = max(0, $s_last - $s_first);

            if ($stop_dur >= $min_stop_sec) {
                // Legitimate parking (>= 10 minutes)!
                if (!empty($cur_trip_pts)) {
                    $tr = build_history_trip($cur_trip_pts, count($trips), $trip_colors);
                    if ($tr) {
                        $trip_idx = count($trips);
                        $trips[] = $tr;
                        $timeline[] = ['type' => 'trip', 'index' => $trip_idx, 'data' => $tr];
                        $km += $tr['distance_km'];
                        $cur_trip_pts = [];
                    } else {
                        // Drift < 150m: discard trip points so it merges into parking
                        $cur_trip_pts = [];
                    }
                }

                // If previous item in timeline was already a stop, extend it!
                if (!empty($timeline) && $timeline[count($timeline) - 1]['type'] === 'stop') {
                    $last_idx = count($timeline) - 1;
                    $prev_s_start = strtotime($stops[count($stops) - 1]['start_time']);
                    $ext_dur = max(0, $s_last - $prev_s_start);
                    $stops[count($stops) - 1]['end_time'] = date('H:i:s', $s_last);
                    $stops[count($stops) - 1]['duration_sec'] = $ext_dur;
                    $stops[count($stops) - 1]['duration_str'] = floor($ext_dur / 3600) > 0 ? (floor($ext_dur / 3600).'h '.floor(($ext_dur % 3600) / 60).'m') : (round($ext_dur / 60).' min');
                    $timeline[$last_idx]['data'] = $stops[count($stops) - 1];
                } else {
                    $stop_obj = [
                        'stop_num' => count($stops) + 1,
                        'lat' => (float)$cur_stop_pts[0]['lat'],
                        'lng' => (float)$cur_stop_pts[0]['lng'],
                        'start_time' => date('H:i:s', $s_first),
                        'end_time' => date('H:i:s', $s_last),
                        'duration_sec' => $stop_dur,
                        'duration_str' => floor($stop_dur / 3600) > 0 ? (floor($stop_dur / 3600).'h '.floor(($stop_dur % 3600) / 60).'m') : (round($stop_dur / 60).' min')
                    ];
                    $stops[] = $stop_obj;
                    $timeline[] = ['type' => 'stop', 'data' => $stop_obj];
                }
            } else {
                // Brief stop (< 10 min, traffic light / momentary pause). Keep inside current trip!
                foreach ($cur_stop_pts as $sp) {
                    $cur_trip_pts[] = $sp;
                }
            }
            $cur_stop_pts = [];
        }

        $cur_trip_pts[] = $p;
    } else {
        // Stationary point ($spd == 0)
        $cur_stop_pts[] = $p;
    }
}

// After loop: check trailing stationary points
if (!empty($cur_stop_pts)) {
    $s_first = strtotime($cur_stop_pts[0]['recorded_at']);
    $s_last = strtotime($cur_stop_pts[count($cur_stop_pts) - 1]['recorded_at']);
    $stop_dur = max(0, $s_last - $s_first);

    if ($stop_dur >= $min_stop_sec) {
        if (!empty($cur_trip_pts)) {
            $tr = build_history_trip($cur_trip_pts, count($trips), $trip_colors);
            if ($tr) {
                $trip_idx = count($trips);
                $trips[] = $tr;
                $timeline[] = ['type' => 'trip', 'index' => $trip_idx, 'data' => $tr];
                $km += $tr['distance_km'];
                $cur_trip_pts = [];
            } else {
                $cur_trip_pts = [];
            }
        }

        if (!empty($timeline) && $timeline[count($timeline) - 1]['type'] === 'stop') {
            $last_idx = count($timeline) - 1;
            $prev_s_start = strtotime($stops[count($stops) - 1]['start_time']);
            $ext_dur = max(0, $s_last - $prev_s_start);
            $stops[count($stops) - 1]['end_time'] = date('H:i:s', $s_last);
            $stops[count($stops) - 1]['duration_sec'] = $ext_dur;
            $stops[count($stops) - 1]['duration_str'] = floor($ext_dur / 3600) > 0 ? (floor($ext_dur / 3600).'h '.floor(($ext_dur % 3600) / 60).'m') : (round($ext_dur / 60).' min');
            $timeline[$last_idx]['data'] = $stops[count($stops) - 1];
        } else {
            $stop_obj = [
                'stop_num' => count($stops) + 1,
                'lat' => (float)$cur_stop_pts[0]['lat'],
                'lng' => (float)$cur_stop_pts[0]['lng'],
                'start_time' => date('H:i:s', $s_first),
                'end_time' => date('H:i:s', $s_last),
                'duration_sec' => $stop_dur,
                'duration_str' => floor($stop_dur / 3600) > 0 ? (floor($stop_dur / 3600).'h '.floor(($stop_dur % 3600) / 60).'m') : (round($stop_dur / 60).' min')
            ];
            $stops[] = $stop_obj;
            $timeline[] = ['type' => 'stop', 'data' => $stop_obj];
        }
    } else {
        foreach ($cur_stop_pts as $sp) {
            $cur_trip_pts[] = $sp;
        }
    }
}

// Trailing moving trip
if (!empty($cur_trip_pts)) {
    $tr = build_history_trip($cur_trip_pts, count($trips), $trip_colors);
    if ($tr) {
        $trip_idx = count($trips);
        $trips[] = $tr;
        $timeline[] = ['type' => 'trip', 'index' => $trip_idx, 'data' => $tr];
        $km += $tr['distance_km'];
    }
}

// Check for stationary day (e.g. Chamika sitting inside Nihal Stores all day)
$is_stationary_day = (empty($trips) && count($pts) > 0);
$duration_str = '0 min';
if (count($pts) >= 2) {
    $secs = strtotime($pts[count($pts) - 1]['recorded_at']) - strtotime($pts[0]['recorded_at']);
    $hrs = floor($secs / 3600);
    $mins = floor(($secs % 3600) / 60);
    $duration_str = ($hrs > 0 ? $hrs . 'h ' : '') . $mins . 'm';
}

if ($is_stationary_day) {
    $km = 0.0;
    $max_speed = 0.0;
    $avg_speed = 0.0;
    if (empty($stops) && count($pts) >= 2) {
        $dur_all = strtotime($pts[count($pts) - 1]['recorded_at']) - strtotime($pts[0]['recorded_at']);
        $stops[] = [
            'stop_num' => 1,
            'lat' => (float)$pts[0]['lat'],
            'lng' => (float)$pts[0]['lng'],
            'start_time' => substr($pts[0]['recorded_at'], 11, 8),
            'end_time' => substr($pts[count($pts) - 1]['recorded_at'], 11, 8),
            'duration_sec' => $dur_all,
            'duration_str' => floor($dur_all / 3600) > 0 ? (floor($dur_all / 3600).'h '.floor(($dur_all % 3600) / 60).'m') : (round($dur_all / 60).' min')
        ];
    }
} else {
    $tot_pts = count($pts);
    $avg_speed = $tot_pts > 0 ? round(array_sum(array_column($clean_pts, 'speed')) / $tot_pts, 1) : 0;
}

// Fetch a limited sample of active geofences near Hingurana/tracks to avoid browser freeze
$geofences = [];
try {
    $geofences = q("SELECT id, name, coordinates, color FROM geofences WHERE active=1 LIMIT 30")->fetchAll();
    foreach ($geofences as &$g) {
        $g['coords'] = json_decode($g['coordinates'], true) ?: [];
    }
} catch (Throwable $e) {}

layout_start('Tracks', 'history.php', true);
?>
<style>
  /* Trip Playback Simulation HUD */
  .w-playback-hud {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(15, 23, 42, 0.94);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    padding: 10px 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    z-index: 999;
    width: min(650px, 92%);
    color: #f8fafc;
    font-size: 12px;
  }
  .w-pb-row1 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    flex-wrap: wrap;
    gap: 10px;
  }
  .w-pb-controls {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .w-pb-btn {
    background: #0284c7;
    color: #fff;
    border: none;
    padding: 5px 12px;
    border-radius: 4px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s;
  }
  .w-pb-btn:hover { background: #0369a1; }
  .w-pb-speeds {
    display: flex;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    padding: 2px;
    gap: 2px;
    margin-left: 4px;
  }
  .w-pb-speed {
    padding: 2px 7px;
    font-size: 10.5px;
    font-weight: 600;
    cursor: pointer;
    border-radius: 3px;
    color: #cbd5e1;
  }
  .w-pb-speed.active {
    background: #0284c7;
    color: #fff;
  }
  .w-pb-telemetry {
    display: flex;
    gap: 14px;
    font-size: 11.5px;
  }
  .w-pb-slider-wrap input[type=range] {
    width: 100%;
    accent-color: #0284c7;
    cursor: pointer;
    margin: 0;
  }

  /* Point Tooltips */
  .leaflet-tooltip.w-point-tooltip {
    background: rgba(15, 23, 42, 0.92);
    color: #f8fafc;
    border: 1px solid #38bdf8;
    border-radius: 4px;
    font-size: 11px;
    padding: 3px 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    white-space: nowrap;
  }
  .leaflet-tooltip.w-point-tooltip::before {
    border-top-color: rgba(15, 23, 42, 0.92);
  }

  /* Photo Marker Pin */
  .w-photo-pin {
    background: #0284c7;
    color: #fff;
    border: 2px solid #fff;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.4);
    cursor: pointer;
    transition: transform 0.15s, background 0.15s;
  }
  .w-photo-pin:hover {
    transform: scale(1.25);
    background: #0369a1;
  }

  /* Animated Playback Vehicle Marker */
  .w-playback-car-pin {
    background: #16a34a;
    color: #fff;
    border: 2px solid #fff;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.6);
    transition: transform 0.15s ease-out;
  }
  .w-trip-item-row:hover {
    background: #f1f5f9 !important;
    border-color: #cbd5e1 !important;
  }
  .w-trip-item-row.active-trip {
    background: #f0f9ff !important;
    border-color: #0284c7 !important;
    border-left: 4px solid #0284c7 !important;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.15) !important;
  }
  .w-master-header {
    transition: all 0.15s ease;
  }
  .w-master-header:hover {
    background: #e2e8f0 !important;
  }
  .w-master-header.active-all {
    background: #e0f2fe !important;
    border-left: 4px solid #0284c7 !important;
  }
  .w-timeline-stop {
    transition: background 0.15s ease;
  }
  .w-timeline-stop:hover {
    background: #e2e8f0 !important;
  }
</style>
<div class="w-monitoring-layout">
  <!-- Left Tracks Controller (Exact Wialon Screenshot Layout) -->
  <aside class="w-units-sidebar" style="width:340px;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px">
    
    <!-- Collapsible Unit & Date Selector -->
    <details class="w-track-filter-box" <?= empty($pts) ? 'open' : '' ?> style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;font-size:12px;margin-bottom:2px">
      <summary style="font-weight:700;font-size:12px;color:#0f172a;cursor:pointer;display:flex;align-items:center;justify-content:space-between">
        <span>⚙️ Unit &amp; Date Parameters</span>
        <span style="font-size:11px;color:#0284c7;background:#e0f2fe;padding:2px 6px;border-radius:4px"><?= h($u['name'] ?? 'Select') ?></span>
      </summary>

      <form method="get" id="track-form" style="margin-top:10px">
        <!-- Unit Selection -->
        <div style="margin-bottom:8px">
          <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:600">Unit:</label>
          <div style="display:flex;gap:6px">
            <select name="user" style="flex:1;padding:5px 7px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px">
              <?php foreach ($staff as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $s['id'] == $uid ? 'selected' : '' ?>>
                  <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <a href="staff.php" class="w-icon-btn" title="Unit Properties">🔧</a>
          </div>
        </div>

        <!-- Color Scheme & Line Thickness in 2 columns -->
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <div style="flex:1">
            <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:600">Color:</label>
            <select name="color_mode" style="width:100%;padding:4px 6px;border:1px solid #cbd5e1;border-radius:3px;font-size:11.5px">
              <option value="trips" <?= $color_mode === 'trips' ? 'selected' : '' ?>>By trips</option>
              <option value="single" <?= $color_mode === 'single' ? 'selected' : '' ?>>Single color</option>
              <option value="speed" <?= $color_mode === 'speed' ? 'selected' : '' ?>>By speed</option>
            </select>
          </div>
          <div style="flex:1">
            <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:600">Thickness:</label>
            <select name="thickness" style="width:100%;padding:4px 6px;border:1px solid #cbd5e1;border-radius:3px;font-size:11.5px">
              <option value="1" <?= $thickness === 1 ? 'selected' : '' ?>>1 px</option>
              <option value="2" <?= $thickness === 2 ? 'selected' : '' ?>>2 px</option>
              <option value="3" <?= $thickness === 3 ? 'selected' : '' ?>>3 px</option>
              <option value="4" <?= $thickness === 4 ? 'selected' : '' ?>>4 px</option>
              <option value="5" <?= $thickness === 5 ? 'selected' : '' ?>>5 px</option>
            </select>
          </div>
        </div>

        <!-- Checkboxes -->
        <div style="margin-bottom:8px;background:#ffffff;border:1px solid #e2e8f0;padding:6px 8px;border-radius:4px">
          <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:#334155;cursor:pointer;margin-bottom:4px">
            <input type="checkbox" name="show_annotations" value="1" <?= $show_annotations ? 'checked' : '' ?> style="width:auto;margin:0">
            Show speed annotations
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:#1e293b;cursor:pointer;font-weight:600">
            <input type="checkbox" id="chk-snap-roads" checked style="width:auto;margin:0">
            🛣️ Snap track to roads (OSRM)
          </label>
        </div>

        <!-- Date Presets Row -->
        <div class="w-btn-group" style="width:100%;margin-bottom:8px;display:flex">
          <button type="button" class="btn-preset active" data-preset="today" style="flex:1;font-size:11px;padding:4px">Today</button>
          <button type="button" class="btn-preset" data-preset="yesterday" style="flex:1;font-size:11px;padding:4px">Yesterday</button>
          <button type="button" class="btn-preset" data-preset="week" style="flex:1;font-size:11px;padding:4px">Week</button>
          <button type="button" class="btn-preset" data-preset="month" style="flex:1;font-size:11px;padding:4px">Month</button>
        </div>

        <!-- From / To Date Inputs -->
        <div style="display:flex;gap:6px;margin-bottom:10px">
          <div style="flex:1">
            <label style="font-size:10.5px;color:#475569;display:block;margin-bottom:2px">From:</label>
            <input type="datetime-local" name="from" id="dt-from" value="<?= date('Y-m-d\TH:i', strtotime($from_dt)) ?>" style="width:100%;padding:4px 6px;border:1px solid #cbd5e1;border-radius:3px;font-size:11px;box-sizing:border-box">
          </div>
          <div style="flex:1">
            <label style="font-size:10.5px;color:#475569;display:block;margin-bottom:2px">To:</label>
            <input type="datetime-local" name="to" id="dt-to" value="<?= date('Y-m-d\TH:i', strtotime($to_dt)) ?>" style="width:100%;padding:4px 6px;border:1px solid #cbd5e1;border-radius:3px;font-size:11px;box-sizing:border-box">
          </div>
        </div>

        <!-- Show Track Button -->
        <button type="submit" class="w-icon-btn primary" style="width:100%;justify-content:center;padding:7px;font-size:12px;font-weight:700;border-radius:4px">
          🔍 Load Track
        </button>
      </form>
    </details>

    <!-- Wialon Trip Breakdown Table (Screenshot 4) -->
    <?php if (count($pts) > 0): ?>
      <div class="w-wialon-track-table" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);flex:1;display:flex;flex-direction:column">
        <!-- Master header with Object name & total mileage -->
        <div id="master-trip-header" class="w-master-header" onclick="selectFullDay()" style="background:#f1f5f9;padding:8px 10px;border-bottom:1px solid #cbd5e1;display:flex;align-items:center;justify-content:space-between;cursor:pointer" title="Click to view full day route & stats">
          <label style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:12.5px;color:#0f172a;cursor:pointer" onclick="event.stopPropagation()">
            <input type="checkbox" id="chk-master-trips" checked onchange="toggleAllTrips(this.checked)" style="accent-color:#2563eb">
            <span><?= htmlspecialchars($u['name'] ?? 'Fleet Unit') ?></span>
          </label>
          <div style="text-align:right">
            <span id="header-selected-label" style="display:block;font-size:9.5px;color:#64748b;font-weight:600">FULL DAY (<?= count($trips) ?> TRIPS)</span>
            <div style="font-weight:800;color:#0284c7;font-size:13px">
              <?= number_format($km, 2) ?> km
            </div>
          </div>
        </div>

        <!-- Playback Actions [⏮] [▶ Play All] [⏭] [⛶ Fit All] -->
        <div style="padding:6px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;gap:4px">
            <button type="button" class="w-wb-btn" onclick="playbackFirstPoint()" title="Beginning of track">⏮</button>
            <button type="button" class="w-wb-btn primary" id="btn-toggle-playback" onclick="toggleRoutePlayback()" title="Play track animation">▶ Play All</button>
            <button type="button" class="w-wb-btn" onclick="playbackLastPoint()" title="End of track">⏭</button>
          </div>
          <button type="button" class="w-wb-btn" onclick="selectFullDay()" title="View and fit entire day on map">⛶ Full Day</button>
        </div>

        <!-- Trips & Stops Timeline -->
        <div style="max-height:480px;overflow-y:auto;padding:6px" id="timeline-container">
          <?php if ($is_stationary_day): ?>
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:10px;font-size:11.5px;color:#92400e">
              <strong style="display:block;margin-bottom:4px;color:#b45309">🅿️ Stationary All Day (0 km/h)</strong>
              <div>Duration: <strong><?= $stops[0]['duration_str'] ?? $duration_str ?></strong></div>
              <div style="margin-top:2px;color:#78350f">Location: Nihal Stores / Hingurana Area</div>
              <div style="font-size:10.5px;color:#047857;margin-top:6px;background:#ecfdf5;padding:4px 6px;border-radius:4px;border:1px solid #a7f3d0">
                ✓ Stationary GPS jitter filtered. No road movement detected. Fake loops prevented.
              </div>
            </div>
          <?php elseif (!empty($timeline)): ?>
            <?php foreach ($timeline as $item): ?>
              <?php if ($item['type'] === 'trip'): 
                $i = $item['index'];
                $tr = $item['data'];
              ?>
                <div class="w-trip-item-row" id="trip-row-<?= $i ?>" data-trip-idx="<?= $i ?>" onclick="selectTrip(<?= $i ?>)" style="padding:8px 10px;border-radius:5px;cursor:pointer;margin-bottom:5px;background:#ffffff;border:1px solid #e2e8f0;transition:all 0.15s">
                  <div style="display:flex;align-items:center;justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:6px">
                      <span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:<?= $tr['color'] ?>"></span>
                      <input type="checkbox" class="chk-single-trip" data-trip-idx="<?= $i ?>" checked onclick="event.stopPropagation(); toggleTrip(<?= $i ?>, this.checked)" style="accent-color:<?= $tr['color'] ?>">
                      <strong style="font-size:12.5px;color:#0f172a">Trip <?= $tr['trip_num'] ?></strong>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px">
                      <span style="font-weight:800;font-size:12.5px;color:<?= $tr['color'] ?>"><?= $tr['distance_km'] ?> km</span>
                      <button type="button" onclick="event.stopPropagation(); playSpecificTrip(<?= $i ?>)" title="Play this trip only" style="background:#0284c7;color:#fff;border:none;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer">▶ Play</button>
                    </div>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:11px;color:#475569;margin-top:4px">
                    <span>🕒 <?= $tr['start_time'] ?> &rarr; <?= $tr['end_time'] ?></span>
                    <span><strong><?= $tr['duration_str'] ?></strong></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:10.5px;color:#64748b;margin-top:2px">
                    <span>Avg: <strong><?= $tr['avg_speed'] ?> km/h</strong></span>
                    <span>Max: <strong style="color:#dc2626"><?= $tr['max_speed'] ?> km/h</strong></span>
                  </div>
                </div>
              <?php elseif ($item['type'] === 'stop'): 
                $st = $item['data'];
              ?>
                <div class="w-timeline-stop" style="padding:6px 10px;margin-bottom:5px;font-size:11px;color:#475569;background:#f8fafc;border-left:3px solid #0284c7;border-radius:3px;display:flex;align-items:center;justify-content:space-between">
                  <div style="display:flex;align-items:center;gap:6px">
                    <span>🅿️</span>
                    <span style="font-weight:700;color:#1e293b">Parking: <?= $st['start_time'] ?> &rarr; <?= $st['end_time'] ?></span>
                  </div>
                  <span style="font-weight:700;color:#0284c7;font-size:11px"><?= $st['duration_str'] ?></span>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Telemetry Summary Footer -->
        <div style="padding:8px 10px;background:#f1f5f9;border-top:1px solid #cbd5e1;font-size:11px;color:#475569;display:flex;justify-content:space-between">
          <span>Trips: <strong><?= count($trips) ?></strong></span>
          <span>Stops: <strong><?= count($stops) ?></strong></span>
          <span>Max: <strong style="color:#dc2626"><?= number_format($max_speed, 1) ?> km/h</strong></span>
        </div>
      </div>
    <?php else: ?>
      <div style="padding:12px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:11.5px;color:#8d6e63">
        No GPS track points found for the selected interval. Click "Load Track" or choose "Today".
      </div>
    <?php endif; ?>
  </aside>

  <!-- Right Map View -->
  <div class="w-map-wrapper">
    <div id="map"></div>

    <!-- Live Coordinate HUD at bottom right -->
    <div class="w-coord-hud" id="w-coord-hud">N 07° 12.3380' &nbsp; E 081° 45.3522'</div>

    <!-- Trip Playback Simulation HUD -->
    <?php if (count($pts) >= 2): ?>
      <div class="w-playback-hud" id="w-playback-hud">
        <div class="w-pb-row1">
          <div class="w-pb-controls">
            <button type="button" class="w-pb-btn" id="btn-pb-play" title="Play trip simulation">▶️ Play</button>
            <button type="button" class="w-pb-btn" id="btn-pb-stop" title="Restart to start point">⏮️ Restart</button>
            <div class="w-pb-speeds">
              <span class="w-pb-speed active" data-speed="1">1x</span>
              <span class="w-pb-speed" data-speed="2">2x</span>
              <span class="w-pb-speed" data-speed="5">5x</span>
              <span class="w-pb-speed" data-speed="10">10x</span>
            </div>
          </div>

          <div class="w-pb-telemetry">
            <span class="w-pb-stat" id="pb-target-badge" style="background:#1e293b;padding:2px 8px;border-radius:4px;border:1px solid #334155;color:#38bdf8;font-weight:700">Full Day</span>
            <span class="w-pb-stat">🚗 <strong id="pb-speed" style="color:#16a34a">0 km/h</strong></span>
            <span class="w-pb-stat">🕒 <strong id="pb-time"><?= substr($pts[0]['recorded_at'], 11, 8) ?></strong></span>
            <span class="w-pb-stat">📍 <span id="pb-dist">0.00 km</span> / <span id="pb-total-dist"><?= number_format($km, 2) ?> km</span></span>
          </div>
        </div>

        <div class="w-pb-slider-wrap">
          <input type="range" id="pb-slider" min="0" max="<?= count($pts) - 1 ?>" value="0" step="1">
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const mapElement = document.getElementById('map');
  const defaultCenter = <?= json_encode($CONFIG['map_center']) ?>;
  const defaultZoom = <?= (int)$CONFIG['map_zoom'] ?>;

  let map;
  if (typeof StMap !== 'undefined' && StMap.create) {
    map = StMap.create(mapElement, defaultCenter, defaultZoom);
  } else {
    map = L.map(mapElement, { zoomControl: false }).setView(defaultCenter, defaultZoom);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.control.scale({ imperial: true, metric: true, position: 'bottomleft' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
  }
  setTimeout(() => { if (map && map.invalidateSize) map.invalidateSize(); }, 250);

  // Coordinate HUD formatting
  map.on('mousemove', (e) => {
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    const fLat = (lat >= 0 ? 'N ' : 'S ') + Math.floor(Math.abs(lat)).toString().padStart(2, '0') + '° ' + ((Math.abs(lat) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    const fLng = (lng >= 0 ? 'E ' : 'W ') + Math.floor(Math.abs(lng)).toString().padStart(3, '0') + '° ' + ((Math.abs(lng) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    document.getElementById('w-coord-hud').innerHTML = `${fLat} &nbsp; ${fLng}`;
  });

  // Render nearby Geofences in background safely
  const geofences = <?= json_encode($geofences) ?>;
  if (Array.isArray(geofences)) {
    geofences.forEach(gf => {
      try {
        if (!gf.coords || gf.coords.length < 3) return;
        const poly = L.polygon(gf.coords, {
          color: gf.color || '#4caf50',
          fillColor: gf.color || '#4caf50',
          fillOpacity: 0.15,
          weight: 1.5
        }).addTo(map);

        poly.bindTooltip(gf.name, {
          direction: 'center',
          className: 'sector-label'
        });
      } catch (e) {}
    });
  }

  // Track points from PHP
  const rawPts = <?= json_encode($pts) ?>;
  const trackPhotos = <?= json_encode($track_photos) ?>;
  const stops = <?= json_encode($stops) ?>;
  const colorMode = "<?= $color_mode ?>";
  const thickness = <?= $thickness ?>;

  const trips = <?= json_encode($trips) ?>;
  const isStationaryDay = <?= json_encode($is_stationary_day) ?>;
  const tripPolylines = [];
  const tripPointLayers = [];
  let allBounds = null;

  if (rawPts.length > 0) {
    const latlngs = rawPts.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);

    if (isStationaryDay) {
      // 🅿️ Chamika's stationary day: sitting at Nihal Stores all day.
      // Filter jitter, do NOT snap to roads, render a single clean parking marker!
      const pLat = parseFloat(rawPts[0].lat);
      const pLng = parseFloat(rawPts[0].lng);
      allBounds = L.latLngBounds([[pLat, pLng], [pLat, pLng]]);

      const parkMarker = L.marker([pLat, pLng], {
        icon: L.divIcon({
          className: 'w-marker-bubble',
          html: `<div style="background:#d97706;color:#fff;padding:4px 9px;border-radius:4px;font-size:12px;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,0.3);white-space:nowrap">🅿️ Parked / Stationary (0 km/h)</div>`,
          iconSize: [180, 28],
          iconAnchor: [90, 14]
        })
      }).addTo(map);

      parkMarker.bindPopup(`
        <div style="font-size:12px;line-height:1.4">
          <strong style="color:#d97706;font-size:13px">🅿️ Stationary All Day</strong><br>
          Vehicle: <strong><?= htmlspecialchars($u['name'] ?? '') ?></strong><br>
          Location: <strong>Nihal Stores / Hingurana Area</strong><br>
          Duration: <strong><?= $stops[0]['duration_str'] ?? $duration_str ?></strong><br>
          Speed: <strong>0 km/h (Stationary jitter filtered)</strong><br>
          <span style="color:#16a34a;font-size:11px">✓ No fake road loops created</span>
        </div>
      `);
      map.setView([pLat, pLng], 16);
    } else {
      // 🚗 Moving trips exist: Prepare discrete trip layers (isolated per-trip)
      trips.forEach((tr, idx) => {
        const trPts = tr.points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
        const tripPoly = L.polyline(trPts, {
          color: tr.color || '#2563eb',
          weight: thickness || 4,
          opacity: 0.9,
          lineJoin: 'round'
        }); // Decoupled from map: added strictly via selectTrip(idx) or selectFullDay()!

        tripPolylines.push(tripPoly);

        if (!allBounds) allBounds = tripPoly.getBounds();
        else allBounds.extend(tripPoly.getBounds());

        // Create interactive points layer for this specific trip
        const ptGroup = L.layerGroup();
        const step = Math.max(1, Math.floor(tr.points.length / 80));
        for (let pIdx = 0; pIdx < tr.points.length; pIdx++) {
          if (pIdx % step === 0 || pIdx === tr.points.length - 1) {
            const pt = tr.points[pIdx];
            const pLat = parseFloat(pt.lat);
            const pLng = parseFloat(pt.lng);
            const pSpd = Math.round(parseFloat(pt.speed || 0));
            const pTime = pt.recorded_at.substr(11, 8);
            const circle = L.circleMarker([pLat, pLng], {
              radius: 3.5,
              fillColor: pSpd > 40 ? '#ef4444' : (pSpd > 15 ? '#16a34a' : '#0284c7'),
              color: '#ffffff',
              weight: 1,
              opacity: 0.8,
              fillOpacity: 0.8
            });
            circle.bindTooltip(`Trip ${tr.trip_num} &bull; 🚗 <strong>${pSpd} km/h</strong> &bull; 🕒 ${pTime}`, {
              className: 'w-point-tooltip',
              direction: 'top',
              offset: [0, -4]
            });
            const capturedIdx = pIdx;
            const capturedTripIdx = idx;
            circle.on('click', () => {
              if (activeTripIdx !== capturedTripIdx) selectTrip(capturedTripIdx);
              jumpToPlaybackIndex(capturedIdx);
            });
            ptGroup.addLayer(circle);
          }
        }
        tripPointLayers.push(ptGroup);
      });

      // Speed Annotations along route (stored inside tripPointLayers so they isolate per trip)
      if (document.querySelector('input[name="show_annotations"]')?.checked) {
        trips.forEach((tr, idx) => {
          const step = Math.max(6, Math.floor(tr.points.length / 8));
          for (let pIdx = step; pIdx < tr.points.length - step; pIdx += step) {
            const pt = tr.points[pIdx];
            const spd = Math.round(parseFloat(pt.speed || 0));
            if (spd > 5) {
              const spdIcon = L.divIcon({
                className: 'w-speed-tag-wrap',
                html: `<div style="background:rgba(15,23,42,0.85);color:#fff;border:1px solid #38bdf8;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:700;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,0.4)">${spd} km/h</div>`,
                iconSize: [50, 18],
                iconAnchor: [25, 9]
              });
              const m = L.marker([parseFloat(pt.lat), parseFloat(pt.lng)], { icon: spdIcon, zIndexOffset: 200 });
              if (tripPointLayers[idx]) tripPointLayers[idx].addLayer(m);
            }
          }
        });
      }

      // Snap to roads: ONLY run on actual moving trips (distance >= 0.25 km and avg speed >= 10 km/h)
      async function applySnapToRoads() {
        trips.forEach(async (tr, idx) => {
          if (tr.distance_km < 0.25 || tr.avg_speed < 10) return; // Protect stationary / slow drift
          const trPts = tr.points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
          if (trPts.length < 2) return;

          const step = Math.max(1, Math.floor(trPts.length / 80));
          const sampled = [];
          for (let i = 0; i < trPts.length; i += step) sampled.push(trPts[i]);
          if (sampled[sampled.length - 1] !== trPts[trPts.length - 1]) sampled.push(trPts[trPts.length - 1]);

          const coordStr = sampled.map(p => `${p[1].toFixed(6)},${p[0].toFixed(6)}`).join(';');
          const url = `https://router.project-osrm.org/match/v1/driving/${coordStr}?overview=full&geometries=geojson`;

          try {
            const resp = await fetch(url);
            const data = await resp.json();
            const roadPoints = [];
            if (data.code === 'Ok' && data.matchings && data.matchings.length > 0) {
              data.matchings.forEach(m => {
                if (m.geometry && m.geometry.coordinates) {
                  m.geometry.coordinates.forEach(c => roadPoints.push([c[1], c[0]]));
                }
              });
            }
            if (roadPoints.length > 0 && tripPolylines[idx]) {
              tripPolylines[idx].setLatLngs(roadPoints);
            }
          } catch (e) {}
        });
      }

      if (document.getElementById('chk-snap-roads')?.checked) {
        applySnapToRoads();
      }

      document.getElementById('chk-snap-roads')?.addEventListener('change', (e) => {
        if (e.target.checked) {
          applySnapToRoads();
        } else {
          trips.forEach((tr, idx) => {
            if (tripPolylines[idx]) {
              tripPolylines[idx].setLatLngs(tr.points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]));
            }
          });
        }
      });
    }

    // Include photos in map bounds
    trackPhotos.forEach(p => {
      if (p.lat && p.lng) {
        const pt = [parseFloat(p.lat), parseFloat(p.lng)];
        if (!allBounds) allBounds = L.latLngBounds([pt, pt]);
        else allBounds.extend(pt);
      }
    });

    if (allBounds && !isStationaryDay) {
      map.fitBounds(allBounds, { padding: [50, 50] });
    }

    // Sidebar Trip Table Interactions & Route Isolation
    window.toggleTrip = function(idx, isVisible) {
      if (!tripPolylines[idx]) return;
      const poly = tripPolylines[idx];
      const ptLayer = tripPointLayers[idx];
      if (isVisible) {
        if (!map.hasLayer(poly)) map.addLayer(poly);
        if (ptLayer && !map.hasLayer(ptLayer)) map.addLayer(ptLayer);
      } else {
        if (map.hasLayer(poly)) map.removeLayer(poly);
        if (ptLayer && map.hasLayer(ptLayer)) map.removeLayer(ptLayer);
      }
    };

    window.toggleAllTrips = function(isVisible) {
      document.querySelectorAll('.chk-single-trip').forEach((chk, idx) => {
        chk.checked = isVisible;
        window.toggleTrip(idx, isVisible);
      });
      if (isVisible) {
        selectFullDay();
      } else {
        pausePlayback();
      }
    };

    window.fitFullTrackBounds = function() {
      if (allBounds) map.fitBounds(allBounds, { padding: [50, 50] });
    };

    let startMarker = null;
    let endMarker = null;

    function setEndpoints(startPt, startTime, endPt, endTime, labelPrefix = '') {
      const sHtml = `<div style="background:#2e7d32;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.3);white-space:nowrap">🚩 ${labelPrefix}Start ${startTime}</div>`;
      const eHtml = `<div style="background:#d32f2f;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.3);white-space:nowrap">🏁 ${labelPrefix}End ${endTime}</div>`;

      if (!startMarker) {
        startMarker = L.marker(startPt, {
          icon: L.divIcon({ className: 'w-marker-bubble', html: sHtml, iconSize: [90, 24], iconAnchor: [45, 24] }),
          zIndexOffset: 900
        }).addTo(map);
      } else {
        startMarker.setLatLng(startPt);
        startMarker.setIcon(L.divIcon({ className: 'w-marker-bubble', html: sHtml, iconSize: [90, 24], iconAnchor: [45, 24] }));
      }

      if (!endMarker) {
        endMarker = L.marker(endPt, {
          icon: L.divIcon({ className: 'w-marker-bubble', html: eHtml, iconSize: [90, 24], iconAnchor: [45, 24] }),
          zIndexOffset: 900
        }).addTo(map);
      } else {
        endMarker.setLatLng(endPt);
        endMarker.setIcon(L.divIcon({ className: 'w-marker-bubble', html: eHtml, iconSize: [90, 24], iconAnchor: [45, 24] }));
      }
    }

    if (!isStationaryDay && latlngs.length > 0) {
      setEndpoints(latlngs[0], rawPts[0].recorded_at.substr(11, 5), latlngs[latlngs.length - 1], rawPts[rawPts.length - 1].recorded_at.substr(11, 5));

      // Add Stop / Parking markers (P)
      stops.forEach(st => {
        if (!st.lat || !st.lng) return;
        L.marker([parseFloat(st.lat), parseFloat(st.lng)], {
          icon: L.divIcon({
            className: 'w-marker-bubble',
            html: `<div style="background:#1976d2;color:#fff;padding:1px 5px;border-radius:50%;font-size:11px;font-weight:bold;width:20px;height:20px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.3)">P</div>`,
            iconSize: [22, 22],
            iconAnchor: [11, 11]
          })
        }).addTo(map).bindPopup(`<strong>Parking / Stop</strong><br>Time: ${st.start_time || st.time} &rarr; ${st.end_time || ''}<br>Duration: ${st.duration_str || st.duration}`);
      });
    }

    // 1. Render Photos Taken Along the Track (Camera Markers with download button)
    trackPhotos.forEach(p => {
      if (!p.lat || !p.lng) return;
      const photoUrl = `../<?= $CONFIG['upload_url'] ?>/${p.file_path}`;
      const pIcon = L.divIcon({
        className: 'w-photo-marker-icon',
        html: `<div class="w-photo-pin" title="Click to view photo taken at this location">📷</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14]
      });

      const popupContent = `
        <div style="width:230px;font-family:sans-serif">
          <div style="font-weight:700;font-size:12px;color:#0f172a;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center">
            <span>📷 ${p.type === 'field' ? 'Field Snap' : 'Task Photo'}</span>
            <span style="font-size:10px;color:#64748b">${p.taken_at.substr(11, 5)}</span>
          </div>
          <div style="width:100%;height:140px;background:#0f172a;border-radius:6px;overflow:hidden;margin-bottom:8px;cursor:pointer" onclick="window.open('${photoUrl}', '_blank')">
            <img src="${photoUrl}" style="width:100%;height:100%;object-fit:cover" alt="Photo" loading="lazy">
          </div>
          <div style="font-size:11px;color:#64748b;margin-bottom:4px">🕒 <strong>${p.taken_at}</strong></div>
          ${p.geofence_name ? `<div style="font-size:11px;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:3px 6px;border-radius:4px;margin-bottom:6px;font-weight:700">⬡ ${p.geofence_name}</div>` : ''}
          ${p.note ? `<div style="font-size:11px;color:#334155;background:#f8fafc;border-left:3px solid #0284c7;padding:4px 6px;border-radius:3px;margin-bottom:6px">${p.note}</div>` : ''}
          <div style="font-size:10.5px;color:#94a3b8;margin-bottom:8px">📍 ${parseFloat(p.lat).toFixed(5)}, ${parseFloat(p.lng).toFixed(5)}</div>
          <div style="display:flex;gap:6px">
            <a href="download_photo.php?file=${encodeURIComponent(p.file_path)}" class="w-icon-btn primary" style="flex:1;justify-content:center;padding:5px;font-size:11.5px;font-weight:700;border-radius:4px">⬇️ Download Photo</a>
          </div>
        </div>
      `;

      L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon: pIcon, zIndexOffset: 800 })
        .addTo(map)
        .bindPopup(popupContent);
    });

    // 2. Playback State & Cumulative Distance Engine
    let activePlaybackPts = rawPts;
    let activeTripIdx = null;
    let activeCumDist = [];

    function computeCumDist(pts) {
      const dists = [0];
      let tot = 0;
      for (let i = 1; i < pts.length; i++) {
        const prev = pts[i - 1];
        const curr = pts[i];
        const dLat = (parseFloat(curr.lat) - parseFloat(prev.lat)) * Math.PI / 180;
        const dLng = (parseFloat(curr.lng) - parseFloat(prev.lng)) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(parseFloat(prev.lat) * Math.PI / 180) * Math.cos(parseFloat(curr.lat) * Math.PI / 180) *
                  Math.sin(dLng/2) * Math.sin(dLng/2);
        tot += 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        dists.push(tot);
      }
      return dists;
    }
    activeCumDist = computeCumDist(activePlaybackPts);

    // 3. Smooth Trip Playback Simulator Elements
    const vehicleIcon = L.divIcon({
      className: 'w-playback-car',
      html: `<div class="w-playback-car-pin" id="playback-car-icon">🚗</div>`,
      iconSize: [32, 32],
      iconAnchor: [16, 16]
    });

    const vehicleMarker = L.marker(latlngs[0], { icon: vehicleIcon, zIndexOffset: 1200 }).addTo(map);

    let playIndex = 0;
    let isPlaying = false;
    let playSpeed = 1;
    let playTimer = null;

    const playBtn = document.getElementById('btn-pb-play');
    const stopBtn = document.getElementById('btn-pb-stop');
    const slider = document.getElementById('pb-slider');
    const speedEl = document.getElementById('pb-speed');
    const timeEl = document.getElementById('pb-time');
    const distEl = document.getElementById('pb-dist');
    const targetBadge = document.getElementById('pb-target-badge');
    const totalDistEl = document.getElementById('pb-total-dist');

    function updatePlaybackUI(idx) {
      if (idx < 0 || idx >= activePlaybackPts.length) return;
      playIndex = idx;
      const pt = activePlaybackPts[idx];
      const lat = parseFloat(pt.lat);
      const lng = parseFloat(pt.lng);
      const spd = Math.round(parseFloat(pt.speed || 0));
      const dist = (activeCumDist[idx] || 0).toFixed(2);

      vehicleMarker.setLatLng([lat, lng]);

      // Rotate car icon based on heading to next point
      if (idx < activePlaybackPts.length - 1) {
        const nextPt = activePlaybackPts[idx + 1];
        const dy = parseFloat(nextPt.lat) - lat;
        const dx = (parseFloat(nextPt.lng) - lng) * Math.cos(lat * Math.PI / 180);
        const heading = (Math.atan2(dx, dy) * 180 / Math.PI + 360) % 360;
        const iconEl = document.getElementById('playback-car-icon');
        if (iconEl) iconEl.style.transform = `rotate(${heading - 90}deg)`;
      }

      if (speedEl) {
        speedEl.textContent = `${spd} km/h`;
        speedEl.style.color = spd > 40 ? '#ef4444' : (spd > 15 ? '#16a34a' : '#38bdf8');
      }
      if (timeEl) timeEl.textContent = pt.recorded_at.substr(11, 8);
      if (distEl) distEl.textContent = `${dist} km`;
      if (slider) slider.value = idx;
    }

    function jumpToPlaybackIndex(idx) {
      updatePlaybackUI(idx);
      if (activePlaybackPts[idx]) {
        const pt = activePlaybackPts[idx];
        map.panTo([parseFloat(pt.lat), parseFloat(pt.lng)], { animate: true, duration: 0.3 });
      }
    }

    function stepPlayback() {
      if (!isPlaying) return;
      if (playIndex >= activePlaybackPts.length - 1) {
        pausePlayback();
        return;
      }
      playIndex++;
      updatePlaybackUI(playIndex);

      if (playIndex % 4 === 0) {
        const pt = activePlaybackPts[playIndex];
        const curBounds = map.getBounds();
        if (!curBounds.contains([parseFloat(pt.lat), parseFloat(pt.lng)])) {
          map.panTo([parseFloat(pt.lat), parseFloat(pt.lng)], { animate: true, duration: 0.6 });
        }
      }

      const delay = Math.max(30, 250 / playSpeed);
      playTimer = setTimeout(stepPlayback, delay);
    }

    function startPlayback() {
      if (playIndex >= activePlaybackPts.length - 1) playIndex = 0;
      isPlaying = true;
      if (playBtn) playBtn.textContent = '⏸️ Pause';
      const sbPlay = document.getElementById('btn-toggle-playback');
      if (sbPlay) sbPlay.textContent = '⏸ Pause';
      stepPlayback();
    }

    function pausePlayback() {
      isPlaying = false;
      if (playTimer) clearTimeout(playTimer);
      if (playBtn) playBtn.textContent = '▶️ Play';
      const sbPlay = document.getElementById('btn-toggle-playback');
      if (sbPlay) sbPlay.textContent = '▶ Play';
    }

    // 5. Select Specific Trip: Highlight polyline, zoom to trip, set trip playback target
    window.selectTrip = function(idx) {
      if (!trips[idx] || !tripPolylines[idx]) return;
      activeTripIdx = idx;
      pausePlayback();

      // Highlight selected row in sidebar
      document.querySelectorAll('.w-trip-item-row').forEach(r => r.classList.remove('active-trip'));
      const row = document.getElementById(`trip-row-${idx}`);
      if (row) row.classList.add('active-trip');
      const masterHdr = document.getElementById('master-trip-header');
      if (masterHdr) masterHdr.classList.remove('active-all');

      // Update checkboxes: check only selected trip, uncheck others & master
      const masterChk = document.getElementById('chk-master-trips');
      if (masterChk) masterChk.checked = false;
      document.querySelectorAll('.chk-single-trip').forEach((chk, i) => {
        chk.checked = (i === idx);
      });

      // Remove ALL other trip polylines & points from map; ADD ONLY selected trip!
      tripPolylines.forEach((poly, i) => {
        const ptLayer = tripPointLayers[i];
        if (i === idx) {
          poly.setStyle({ opacity: 1.0, weight: Math.max(5, (thickness || 4) + 1) });
          if (!map.hasLayer(poly)) map.addLayer(poly);
          poly.bringToFront();
          if (ptLayer && !map.hasLayer(ptLayer)) map.addLayer(ptLayer);
        } else {
          if (map.hasLayer(poly)) map.removeLayer(poly);
          if (ptLayer && map.hasLayer(ptLayer)) map.removeLayer(ptLayer);
        }
      });

      // Zoom map to selected trip bounds
      const b = tripPolylines[idx].getBounds();
      map.fitBounds(b, { padding: [60, 60] });

      // Update Start / End markers for this trip
      const trPts = trips[idx].points;
      if (trPts.length > 0) {
        const s = [parseFloat(trPts[0].lat), parseFloat(trPts[0].lng)];
        const e = [parseFloat(trPts[trPts.length - 1].lat), parseFloat(trPts[trPts.length - 1].lng)];
        setEndpoints(s, trips[idx].start_time.substr(0, 5), e, trips[idx].end_time.substr(0, 5), `T${trips[idx].trip_num} `);
      }

      // Switch playback context to this trip ONLY
      activePlaybackPts = trPts;
      activeCumDist = computeCumDist(activePlaybackPts);

      if (slider) {
        slider.max = Math.max(0, activePlaybackPts.length - 1);
        slider.value = 0;
      }
      if (targetBadge) {
        targetBadge.textContent = `Trip ${trips[idx].trip_num}`;
        targetBadge.style.color = trips[idx].color || '#38bdf8';
      }
      if (totalDistEl) {
        totalDistEl.textContent = `${trips[idx].distance_km} km`;
      }
      const hdrLabel = document.getElementById('header-selected-label');
      if (hdrLabel) {
        hdrLabel.textContent = `TRIP ${trips[idx].trip_num} (${trips[idx].distance_km} KM)`;
      }

      jumpToPlaybackIndex(0);
    };

    // 6. Select Full Day: Restore all polylines & full day playback
    window.selectFullDay = function() {
      activeTripIdx = null;
      pausePlayback();

      // Reset sidebar active states
      document.querySelectorAll('.w-trip-item-row').forEach(r => r.classList.remove('active-trip'));
      const masterHdr = document.getElementById('master-trip-header');
      if (masterHdr) masterHdr.classList.add('active-all');

      // Check all checkboxes
      const masterChk = document.getElementById('chk-master-trips');
      if (masterChk) masterChk.checked = true;
      document.querySelectorAll('.chk-single-trip').forEach(chk => {
        chk.checked = true;
      });

      // Show all trip polylines & points on map
      tripPolylines.forEach((poly, i) => {
        poly.setStyle({ opacity: 0.9, weight: thickness || 4 });
        if (!map.hasLayer(poly)) map.addLayer(poly);
        const ptLayer = tripPointLayers[i];
        if (ptLayer && !map.hasLayer(ptLayer)) map.addLayer(ptLayer);
      });

      // Zoom to all bounds
      if (allBounds) map.fitBounds(allBounds, { padding: [50, 50] });

      // Reset Start / End markers to full day
      if (rawPts.length > 0) {
        const s = [parseFloat(rawPts[0].lat), parseFloat(rawPts[0].lng)];
        const e = [parseFloat(rawPts[rawPts.length - 1].lat), parseFloat(rawPts[rawPts.length - 1].lng)];
        setEndpoints(s, rawPts[0].recorded_at.substr(11, 5), e, rawPts[rawPts.length - 1].recorded_at.substr(11, 5));
      }

      // Switch playback context to full day
      activePlaybackPts = rawPts;
      activeCumDist = computeCumDist(activePlaybackPts);

      if (slider) {
        slider.max = Math.max(0, activePlaybackPts.length - 1);
        slider.value = 0;
      }
      if (targetBadge) {
        targetBadge.textContent = 'Full Day';
        targetBadge.style.color = '#38bdf8';
      }
      if (totalDistEl) {
        totalDistEl.textContent = `<?= number_format($km, 2) ?> km`;
      }
      const hdrLabel = document.getElementById('header-selected-label');
      if (hdrLabel) {
        hdrLabel.textContent = `FULL DAY (<?= count($trips) ?> TRIPS)`;
      }

      jumpToPlaybackIndex(0);
    };

    window.playSpecificTrip = function(idx) {
      selectTrip(idx);
      startPlayback();
    };

    window.zoomToTrip = function(idx) {
      selectTrip(idx);
    };

    window.playbackFirstPoint = function() { jumpToPlaybackIndex(0); };
    window.playbackLastPoint = function() { jumpToPlaybackIndex(Math.max(0, activePlaybackPts.length - 1)); };
    window.toggleRoutePlayback = function() {
      if (isPlaying) pausePlayback();
      else startPlayback();
    };

    playBtn?.addEventListener('click', () => {
      if (isPlaying) pausePlayback();
      else startPlayback();
    });

    stopBtn?.addEventListener('click', () => {
      pausePlayback();
      jumpToPlaybackIndex(0);
    });

    slider?.addEventListener('input', (e) => {
      const idx = parseInt(e.target.value, 10);
      updatePlaybackUI(idx);
    });

    document.querySelectorAll('.w-pb-speed').forEach(spBtn => {
      spBtn.addEventListener('click', () => {
        document.querySelectorAll('.w-pb-speed').forEach(b => b.classList.remove('active'));
        spBtn.classList.add('active');
        playSpeed = parseFloat(spBtn.dataset.speed || 1);
      });
    });

    // Auto-select Trip 1 by default on load if trips exist, otherwise show full day
    if (trips.length > 0) {
      selectTrip(0);
    } else {
      selectFullDay();
    }
  }

  // If no track points but photos exist, render photos and fit bounds
  if (rawPts.length === 0 && trackPhotos.length > 0) {
    let pBounds = null;
    trackPhotos.forEach(p => {
      if (!p.lat || !p.lng) return;
      const photoUrl = `../<?= $CONFIG['upload_url'] ?>/${p.file_path}`;
      const pIcon = L.divIcon({
        className: 'w-photo-marker-icon',
        html: `<div class="w-photo-pin" title="Click to view photo taken at this location">📷</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14]
      });

      const popupContent = `
        <div style="width:230px;font-family:sans-serif">
          <div style="font-weight:700;font-size:12px;color:#0f172a;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center">
            <span>📷 ${p.type === 'field' ? 'Field Snap' : 'Task Photo'}</span>
            <span style="font-size:10px;color:#64748b">${p.taken_at.substr(11, 5)}</span>
          </div>
          <div style="width:100%;height:140px;background:#0f172a;border-radius:6px;overflow:hidden;margin-bottom:8px;cursor:pointer" onclick="window.open('${photoUrl}', '_blank')">
            <img src="${photoUrl}" style="width:100%;height:100%;object-fit:cover" alt="Photo" loading="lazy">
          </div>
          <div style="font-size:11px;color:#64748b;margin-bottom:4px">🕒 <strong>${p.taken_at}</strong></div>
          ${p.geofence_name ? `<div style="font-size:11px;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:3px 6px;border-radius:4px;margin-bottom:6px;font-weight:700">⬡ ${p.geofence_name}</div>` : ''}
          ${p.note ? `<div style="font-size:11px;color:#334155;background:#f8fafc;border-left:3px solid #0284c7;padding:4px 6px;border-radius:3px;margin-bottom:6px">${p.note}</div>` : ''}
          <div style="font-size:10.5px;color:#94a3b8;margin-bottom:8px">📍 ${parseFloat(p.lat).toFixed(5)}, ${parseFloat(p.lng).toFixed(5)}</div>
          <div style="display:flex;gap:6px">
            <a href="download_photo.php?file=${encodeURIComponent(p.file_path)}" class="w-icon-btn primary" style="flex:1;justify-content:center;padding:5px;font-size:11.5px;font-weight:700;border-radius:4px">⬇️ Download Photo</a>
          </div>
        </div>
      `;

      const pt = [parseFloat(p.lat), parseFloat(p.lng)];
      L.marker(pt, { icon: pIcon, zIndexOffset: 800 }).addTo(map).bindPopup(popupContent);
      if (!pBounds) pBounds = L.latLngBounds([pt, pt]);
      else pBounds.extend(pt);
    });
    if (pBounds) map.fitBounds(pBounds, { padding: [60, 60] });
  }

  // Preset Date buttons handler
  document.querySelectorAll('.btn-preset').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.btn-preset').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const preset = btn.dataset.preset;
      const now = new Date();
      let fromDate = new Date();
      let toDate = new Date();

      if (preset === 'today') {
        fromDate.setHours(0, 0, 0, 0);
        toDate.setHours(23, 59, 59, 999);
      } else if (preset === 'yesterday') {
        fromDate.setDate(now.getDate() - 1);
        fromDate.setHours(0, 0, 0, 0);
        toDate.setDate(now.getDate() - 1);
        toDate.setHours(23, 59, 59, 999);
      } else if (preset === 'week') {
        fromDate.setDate(now.getDate() - 7);
        fromDate.setHours(0, 0, 0, 0);
      } else if (preset === 'month') {
        fromDate.setMonth(now.getMonth() - 1);
        fromDate.setHours(0, 0, 0, 0);
      }

      const pad = n => String(n).padStart(2, '0');
      const formatDT = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

      document.getElementById('dt-from').value = formatDT(fromDate);
      document.getElementById('dt-to').value = formatDT(toDate);
    });
  });
});
</script>

<?php layout_end(); ?>
