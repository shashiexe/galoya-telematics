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

// Fetch geofences to render alongside track
$geofences = q("SELECT id, name, coordinates, color FROM geofences WHERE active=1")->fetchAll();
foreach ($geofences as &$g) {
    $g['coords'] = json_decode($g['coordinates'], true) ?: [];
}

layout_start('Tracks', 'history.php', true);
?>
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
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const mapElement = document.getElementById('map');
  const defaultCenter = <?= json_encode($CONFIG['map_center']) ?>;
  const defaultZoom = <?= (int)$CONFIG['map_zoom'] ?>;

  const map = StMap.create(mapElement, defaultCenter, defaultZoom);

  // Coordinate HUD formatting
  map.on('mousemove', (e) => {
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    const fLat = (lat >= 0 ? 'N ' : 'S ') + Math.floor(Math.abs(lat)).toString().padStart(2, '0') + '° ' + ((Math.abs(lat) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    const fLng = (lng >= 0 ? 'E ' : 'W ') + Math.floor(Math.abs(lng)).toString().padStart(3, '0') + '° ' + ((Math.abs(lng) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    document.getElementById('w-coord-hud').innerHTML = `${fLat} &nbsp; ${fLng}`;
  });

  // Render Geofences in background (matching user screenshot)
  const geofences = <?= json_encode($geofences) ?>;
  geofences.forEach(gf => {
    if (!gf.coords || gf.coords.length < 3) return;
    const poly = L.polygon(gf.coords, {
      color: gf.color || '#4caf50',
      fillColor: gf.color || '#4caf50',
      fillOpacity: 0.22,
      weight: 2
    }).addTo(map);

    const center = poly.getBounds().getCenter();
    L.marker(center, {
      icon: L.divIcon({
        className: 'w-geofence-label',
        html: `<div style="color:#e65100;font-weight:700;font-size:12px;background:rgba(255,255,255,0.7);padding:2px 6px;border-radius:3px">${gf.name}</div>`,
        iconSize: [80, 20],
        iconAnchor: [40, 10]
      })
    }).addTo(map);
  });

  // Track points from PHP
  const rawPts = <?= json_encode($pts) ?>;
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
      weight: thickness,
      opacity: 0.85
    }).addTo(map);

    map.fitBounds(polyline.getBounds(), { padding: [50, 50] });

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
        if (data.code === 'Ok' && data.matchings && data.matchings.length > 0) {
          const roadPoints = [];
          data.matchings.forEach(m => {
            if (m.geometry && m.geometry.coordinates) {
              m.geometry.coordinates.forEach(c => roadPoints.push([c[1], c[0]]));
            }
          });
          if (roadPoints.length > 0) {
            polyline.setLatLngs(roadPoints);
          }
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
