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

// Calculate distance & telemetry analytics
$km = 0;
$max_speed = 0;
$speed_sum = 0;
$stops = [];
$trips = [];
$current_trip = [];

for ($i = 0; $i < count($pts); $i++) {
    $p = $pts[$i];
    $spd = (float)$p['speed'];
    if ($spd > $max_speed) $max_speed = $spd;
    $speed_sum += $spd;

    if ($i > 0) {
        $prev = $pts[$i - 1];
        $dLat = deg2rad((float)$p['lat'] - (float)$prev['lat']);
        $dLng = deg2rad((float)$p['lng'] - (float)$prev['lng']);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad((float)$prev['lat'])) * cos(deg2rad((float)$p['lat'])) * sin($dLng / 2) ** 2;
        $km += 6371 * 2 * atan2(sqrt($x), sqrt(1 - $x));

        // Detect stop if speed is 0 and time diff > 5 mins
        $t1 = strtotime($prev['recorded_at']);
        $t2 = strtotime($p['recorded_at']);
        if ($spd == 0 && ($t2 - $t1) >= 300) {
            $stops[] = [
                'lat' => (float)$p['lat'],
                'lng' => (float)$p['lng'],
                'time' => substr($p['recorded_at'], 11, 5),
                'duration' => round(($t2 - $t1) / 60) . ' min'
            ];
        }
    }
}

$avg_speed = count($pts) > 0 ? round($speed_sum / count($pts), 1) : 0;
$duration_str = '0 min';
if (count($pts) >= 2) {
    $secs = strtotime($pts[count($pts) - 1]['recorded_at']) - strtotime($pts[0]['recorded_at']);
    $hrs = floor($secs / 3600);
    $mins = floor(($secs % 3600) / 60);
    $duration_str = ($hrs > 0 ? $hrs . 'h ' : '') . $mins . 'm';
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
</style>
<div class="w-monitoring-layout">
  <!-- Left Tracks Controller (Exact Wialon Screenshot Layout) -->
  <aside class="w-units-sidebar" style="width:340px;overflow-y:auto;padding:14px">
    <form method="get" id="track-form">
      <!-- Unit Selection -->
      <div style="margin-bottom:12px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Unit:</label>
        <div style="display:flex;gap:6px">
          <select name="user" style="flex:1;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12.5px">
            <?php foreach ($staff as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id'] == $uid ? 'selected' : '' ?>>
                <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <a href="staff.php" class="w-icon-btn" title="Unit Properties">🔧</a>
        </div>
      </div>

      <!-- Color Scheme -->
      <div style="margin-bottom:12px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Color:</label>
        <select name="color_mode" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px">
          <option value="trips" <?= $color_mode === 'trips' ? 'selected' : '' ?>>By trips</option>
          <option value="single" <?= $color_mode === 'single' ? 'selected' : '' ?>>Single color</option>
          <option value="speed" <?= $color_mode === 'speed' ? 'selected' : '' ?>>By speed</option>
        </select>
      </div>

      <!-- Line Thickness -->
      <div style="margin-bottom:12px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Line thickness:</label>
        <select name="thickness" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px">
          <option value="1" <?= $thickness === 1 ? 'selected' : '' ?>>1 px</option>
          <option value="2" <?= $thickness === 2 ? 'selected' : '' ?>>2 px</option>
          <option value="3" <?= $thickness === 3 ? 'selected' : '' ?>>3 px</option>
          <option value="4" <?= $thickness === 4 ? 'selected' : '' ?>>4 px</option>
          <option value="5" <?= $thickness === 5 ? 'selected' : '' ?>>5 px</option>
        </select>
      </div>

      <!-- Annotations Icon Bar (Matching Screenshot) -->
      <div style="display:flex;align-items:center;gap:4px;background:#f1f5f9;padding:6px 8px;border-radius:4px;border:1px solid #cbd5e1;margin-bottom:12px">
        <span class="w-cluster-badge" style="width:20px;height:20px;font-size:10px" title="Filter level">3</span>
        <span title="Mileage markers" style="cursor:pointer;font-size:13px">📍</span>
        <span title="Parking markers" style="cursor:pointer;font-size:13px;color:#1976d2;font-weight:bold">🅿️</span>
        <span title="Stops" style="cursor:pointer;font-size:13px;color:#d32f2f">🛑</span>
        <span title="Fuel events" style="cursor:pointer;font-size:13px">⛽</span>
        <span title="Speeding" style="cursor:pointer;font-size:13px">⚠️</span>
        <span title="Photo capture markers" style="cursor:pointer;font-size:13px">📷</span>
        <span title="Direction arrows" style="cursor:pointer;font-size:13px;color:#2e7d32">➜</span>
      </div>

      <!-- Checkboxes -->
      <div style="margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#334155;cursor:pointer;margin-bottom:6px">
          <input type="checkbox" name="show_annotations" value="1" <?= $show_annotations ? 'checked' : '' ?> style="width:auto;margin:0">
          Show annotations
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#334155;cursor:pointer">
          <input type="checkbox" name="apply_trip_detector" value="1" checked style="width:auto;margin:0">
          Apply trip detector
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;cursor:pointer;margin-top:6px;font-weight:600">
          <input type="checkbox" id="chk-snap-roads" checked style="width:auto;margin:0">
          🛣️ Snap track to roads (Smooth road routing)
        </label>
      </div>

      <!-- Date Presets Row -->
      <div class="w-btn-group" style="width:100%;margin-bottom:12px;display:flex">
        <button type="button" class="btn-preset active" data-preset="today" style="flex:1">Today</button>
        <button type="button" class="btn-preset" data-preset="yesterday" style="flex:1">Yesterday</button>
        <button type="button" class="btn-preset" data-preset="week" style="flex:1">Week</button>
        <button type="button" class="btn-preset" data-preset="month" style="flex:1">Month</button>
      </div>

      <!-- Interval Selection -->
      <div style="margin-bottom:10px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Interval:</label>
        <select style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px">
          <option>Specified interval</option>
        </select>
      </div>

      <!-- From / To Date Inputs -->
      <div style="margin-bottom:10px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px">From:</label>
        <input type="datetime-local" name="from" id="dt-from" value="<?= date('Y-m-d\TH:i', strtotime($from_dt)) ?>" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px;box-sizing:border-box">
      </div>

      <div style="margin-bottom:16px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px">To:</label>
        <input type="datetime-local" name="to" id="dt-to" value="<?= date('Y-m-d\TH:i', strtotime($to_dt)) ?>" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px;box-sizing:border-box">
      </div>

      <!-- Big Blue Show Track Button (Matching Screenshot) -->
      <button type="submit" class="w-icon-btn primary" style="width:100%;justify-content:center;padding:9px;font-size:13px;font-weight:700;border-radius:4px">
        Show track
      </button>
    </form>

    <!-- Track Summary Statistics Box (When points exist) -->
    <?php if (count($pts) > 0): ?>
      <div style="margin-top:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px">
        <strong style="font-size:12px;color:#1e293b;display:block;margin-bottom:8px">Track Telemetry Summary</strong>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:11px">
          <div>
            <span style="color:#64748b">Mileage:</span>
            <div style="font-weight:700;font-size:14px;color:#1976d2"><?= number_format($km, 2) ?> km</div>
          </div>
          <div>
            <span style="color:#64748b">Duration:</span>
            <div style="font-weight:700;font-size:14px;color:#0f172a"><?= $duration_str ?></div>
          </div>
          <div>
            <span style="color:#64748b">Max Speed:</span>
            <div style="font-weight:600;color:#d32f2f"><?= number_format($max_speed, 1) ?> km/h</div>
          </div>
          <div>
            <span style="color:#64748b">Avg Speed:</span>
            <div style="font-weight:600;color:#2e7d32"><?= $avg_speed ?> km/h</div>
          </div>
          <div>
            <span style="color:#64748b">GPS Points:</span>
            <div style="font-weight:600"><?= count($pts) ?> pts</div>
          </div>
          <div>
            <span style="color:#64748b">Parking / Stops:</span>
            <div style="font-weight:600"><?= count($stops) ?> stops</div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div style="margin-top:16px;padding:12px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:11.5px;color:#8d6e63">
        No GPS track points found for the selected interval. Click "Show track" or choose "Today".
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
            <span class="w-pb-stat">🚗 <strong id="pb-speed" style="color:#16a34a">0 km/h</strong></span>
            <span class="w-pb-stat">🕒 <strong id="pb-time"><?= substr($pts[0]['recorded_at'], 11, 8) ?></strong></span>
            <span class="w-pb-stat">📍 <span id="pb-dist">0.00 km</span> / <?= number_format($km, 2) ?> km</span>
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

  if (rawPts.length > 0) {
    const latlngs = rawPts.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);

    // Draw trajectory line
    let lineColor = '#1976d2';
    if (colorMode === 'speed') lineColor = '#2e7d32';
    else if (colorMode === 'trips') lineColor = '#7b1fa2';

    const polyline = L.polyline(latlngs, {
      color: lineColor,
      weight: thickness || 3,
      opacity: 0.9,
      lineJoin: 'round'
    }).addTo(map);

    // Compute bounds including both track and all photos
    let allBounds = latlngs.length > 0 ? polyline.getBounds() : null;
    trackPhotos.forEach(p => {
      if (p.lat && p.lng) {
        const pt = [parseFloat(p.lat), parseFloat(p.lng)];
        if (!allBounds) allBounds = L.latLngBounds([pt, pt]);
        else allBounds.extend(pt);
      }
    });
    if (allBounds) {
      map.fitBounds(allBounds, { padding: [50, 50] });
    }

    // Snap to Road (OSRM Map Matching) for smooth road-following tracks
    async function applySnapToRoads() {
      if (latlngs.length < 2) return;
      const step = Math.max(1, Math.floor(latlngs.length / 90));
      const sampled = [];
      for (let i = 0; i < latlngs.length; i += step) {
        sampled.push(latlngs[i]);
      }
      if (sampled[sampled.length - 1] !== latlngs[latlngs.length - 1]) {
        sampled.push(latlngs[latlngs.length - 1]);
      }

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
        // If match had gaps or failed, seamlessly route along roads
        if (roadPoints.length === 0) {
          const routeUrl = `https://router.project-osrm.org/route/v1/driving/${coordStr}?overview=full&geometries=geojson`;
          const rResp = await fetch(routeUrl);
          const rData = await rResp.json();
          if (rData.code === 'Ok' && rData.routes && rData.routes.length > 0) {
            rData.routes[0].geometry.coordinates.forEach(c => roadPoints.push([c[1], c[0]]));
          }
        }
        if (roadPoints.length > 0) {
          polyline.setLatLngs(roadPoints);
        }
      } catch (err) {
        console.warn('Snap to road fallback:', err);
      }
    }

    if (document.getElementById('chk-snap-roads')?.checked) {
      applySnapToRoads();
    }

    document.getElementById('chk-snap-roads')?.addEventListener('change', (e) => {
      if (e.target.checked) {
        applySnapToRoads();
      } else {
        polyline.setLatLngs(latlngs);
      }
    });

    // Start marker (Green flag)
    const startPt = latlngs[0];
    const startTime = rawPts[0].recorded_at.substr(11, 5);
    L.marker(startPt, {
      icon: L.divIcon({
        className: 'w-marker-bubble',
        html: `<div style="background:#2e7d32;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.3)">🚩 Start ${startTime}</div>`,
        iconSize: [80, 24],
        iconAnchor: [40, 24]
      })
    }).addTo(map).bindPopup(`<strong>Trip Start Point</strong><br>Time: ${rawPts[0].recorded_at}<br>Coordinates: ${startPt[0]}, ${startPt[1]}`);

    // End marker (Finish flag)
    const endPt = latlngs[latlngs.length - 1];
    const endTime = rawPts[rawPts.length - 1].recorded_at.substr(11, 5);
    L.marker(endPt, {
      icon: L.divIcon({
        className: 'w-marker-bubble',
        html: `<div style="background:#d32f2f;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.3)">🏁 End ${endTime}</div>`,
        iconSize: [80, 24],
        iconAnchor: [40, 24]
      })
    }).addTo(map).bindPopup(`<strong>Trip End Point</strong><br>Time: ${rawPts[rawPts.length - 1].recorded_at}<br>Coordinates: ${endPt[0]}, ${endPt[1]}`);

    // Add Stop / Parking markers (P)
    stops.forEach(st => {
      L.marker([st.lat, st.lng], {
        icon: L.divIcon({
          className: 'w-marker-bubble',
          html: `<div style="background:#1976d2;color:#fff;padding:1px 5px;border-radius:50%;font-size:11px;font-weight:bold;width:20px;height:20px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.3)">P</div>`,
          iconSize: [22, 22],
          iconAnchor: [11, 11]
        })
      }).addTo(map).bindPopup(`<strong>Parking / Stop</strong><br>Time: ${st.time}<br>Duration: ${st.duration}`);
    });

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

    // 2. Interactive GPS Points with Mini Hover Tooltips showing Speed, Time, Coords
    const stepPts = Math.max(1, Math.floor(rawPts.length / 300));
    const cumulativeDist = [0];
    let totalDistKm = 0;

    for (let i = 0; i < rawPts.length; i++) {
      if (i > 0) {
        const prev = rawPts[i - 1];
        const curr = rawPts[i];
        const dLat = (parseFloat(curr.lat) - parseFloat(prev.lat)) * Math.PI / 180;
        const dLng = (parseFloat(curr.lng) - parseFloat(prev.lng)) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(parseFloat(prev.lat) * Math.PI / 180) * Math.cos(parseFloat(curr.lat) * Math.PI / 180) *
                  Math.sin(dLng/2) * Math.sin(dLng/2);
        totalDistKm += 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      }
      cumulativeDist.push(totalDistKm);

      if (i % stepPts === 0 || i === rawPts.length - 1) {
        const pt = rawPts[i];
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
        }).addTo(map);

        circle.bindTooltip(`🚗 <strong>${pSpd} km/h</strong> &bull; 🕒 ${pTime} &bull; 📍 ${pLat.toFixed(4)}, ${pLng.toFixed(4)}`, {
          className: 'w-point-tooltip',
          direction: 'top',
          offset: [0, -4]
        });

        circle.on('click', () => {
          jumpToPlaybackIndex(i);
        });
      }
    }

    // 3. Smooth Trip Playback Simulator
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

    function updatePlaybackUI(idx) {
      if (idx < 0 || idx >= rawPts.length) return;
      playIndex = idx;
      const pt = rawPts[idx];
      const lat = parseFloat(pt.lat);
      const lng = parseFloat(pt.lng);
      const spd = Math.round(parseFloat(pt.speed || 0));
      const dist = (cumulativeDist[idx] || 0).toFixed(2);

      vehicleMarker.setLatLng([lat, lng]);

      // Rotate car icon based on heading to next point
      if (idx < rawPts.length - 1) {
        const nextPt = rawPts[idx + 1];
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
      const pt = rawPts[idx];
      map.panTo([parseFloat(pt.lat), parseFloat(pt.lng)], { animate: true, duration: 0.4 });
    }

    function stepPlayback() {
      if (!isPlaying) return;
      if (playIndex >= rawPts.length - 1) {
        pausePlayback();
        return;
      }
      playIndex++;
      updatePlaybackUI(playIndex);

      // Pan map if vehicle goes near map boundaries
      if (playIndex % 4 === 0) {
        const pt = rawPts[playIndex];
        const curBounds = map.getBounds();
        if (!curBounds.contains([parseFloat(pt.lat), parseFloat(pt.lng)])) {
          map.panTo([parseFloat(pt.lat), parseFloat(pt.lng)], { animate: true, duration: 0.6 });
        }
      }

      const delay = Math.max(30, 250 / playSpeed);
      playTimer = setTimeout(stepPlayback, delay);
    }

    function startPlayback() {
      if (playIndex >= rawPts.length - 1) playIndex = 0;
      isPlaying = true;
      if (playBtn) playBtn.textContent = '⏸️ Pause';
      stepPlayback();
    }

    function pausePlayback() {
      isPlaying = false;
      if (playTimer) clearTimeout(playTimer);
      if (playBtn) playBtn.textContent = '▶️ Play';
    }

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
