<?php
require __DIR__ . '/_common.php';
require_admin();

// Handle new geofence creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_geofence') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? $name);
    $color = trim($_POST['color'] ?? '#4caf50');
    $desc = trim($_POST['description'] ?? '');
    $coords_raw = trim($_POST['coordinates'] ?? '[]');

    if ($name && $coords_raw) {
        q("INSERT INTO geofences (name, code, type, coordinates, color, description) VALUES (?, ?, 'polygon', ?, ?, ?)",
          [$name, $code, $coords_raw, $color, $desc]);
        flash('Geofence zone "' . $name . '" created successfully!');
    }
    header('Location: geofences.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    check_csrf();
    q("DELETE FROM geofences WHERE id=?", [(int)$_GET['delete']]);
    flash('Geofence deleted.');
    header('Location: geofences.php');
    exit;
}

$geofences = q("SELECT * FROM geofences WHERE active=1 ORDER BY name ASC")->fetchAll();

layout_start('Geofences', 'geofences.php', true);
?>
<div class="w-monitoring-layout">
  <!-- Left Panel (Screenshot 4) -->
  <aside class="w-units-sidebar" style="width:340px">
    <div style="display:flex;border-bottom:1px solid var(--border);background:#fafafa">
      <button class="w-tab active" style="flex:1;justify-content:center;height:38px;color:#1e293b;border-bottom:2px solid #1976d2">Geofences</button>
      <button class="w-tab" style="flex:1;justify-content:center;height:38px;color:#64748b;border-bottom:none">Groups</button>
    </div>

    <div class="w-units-toolbar" style="padding:8px 10px;justify-content:space-between">
      <div style="display:flex;gap:6px">
        <button class="w-icon-btn primary" id="btn-draw-poly" title="Draw New Polygon Zone">+ New</button>
        <button class="w-icon-btn" title="Sort">A↓</button>
        <select style="width:auto;padding:2px 6px;font-size:11px;border:1px solid #cbd5e1;border-radius:3px">
          <option>All</option>
        </select>
      </div>
      <input type="text" id="gf-search" placeholder="Search..." style="width:110px;padding:3px 6px;font-size:11px;border:1px solid #cbd5e1;border-radius:3px">
    </div>

    <!-- Drawing Info Banner (hidden by default) -->
    <div id="draw-banner" style="display:none;background:#fff8e1;border-bottom:1px solid #ffe082;padding:8px 10px;font-size:11.5px;color:#8d6e63">
      <strong>Drawing Mode Active:</strong> Click on the map to place polygon points. Click "Finish Zone" when done.
      <div style="margin-top:6px;display:flex;gap:6px">
        <button class="w-icon-btn primary" id="btn-finish-poly" style="font-size:11px;padding:2px 8px">Finish Zone</button>
        <button class="w-icon-btn" id="btn-cancel-poly" style="font-size:11px;padding:2px 8px">Cancel</button>
      </div>
    </div>

    <!-- Geofences List -->
    <div class="w-units-table-container">
      <table class="w-units-table">
        <thead>
          <tr>
            <th style="width:24px"><input type="checkbox" checked></th>
            <th>Geofence Name</th>
            <th style="width:36px;text-align:center" title="Units in geofence">🚍</th>
            <th style="width:30px;text-align:center">⚙</th>
          </tr>
        </thead>
        <tbody id="gf-tbody">
          <?php foreach ($geofences as $g): ?>
            <tr class="w-unit-row gf-item" data-id="<?= $g['id'] ?>" data-coords="<?= h($g['coordinates']) ?>">
              <td style="text-align:center"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= h($g['color']) ?>"></span></td>
              <td>
                <strong style="color:#1e293b"><?= h($g['name']) ?></strong>
                <?php if ($g['description']): ?>
                  <div style="font-size:10.5px;color:#64748b"><?= h($g['description']) ?></div>
                <?php endif; ?>
              </td>
              <td style="text-align:center;font-size:11px;color:#64748b">0</td>
              <td style="text-align:center">
                <a href="geofences.php?delete=<?= $g['id'] ?>&csrf=<?= csrf() ?>" onclick="return confirm('Delete geofence <?= h($g['name']) ?>?')" style="color:#d32f2f;font-size:12px" title="Delete">✕</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="padding:6px 10px;background:#f8fafc;border-top:1px solid var(--border);font-size:11px;color:#64748b">
      Total Geofence Zones: <strong><?= count($geofences) ?></strong>
    </div>
  </aside>

  <!-- Right Map View -->
  <div class="w-map-wrapper">
    <div id="map"></div>
    <div class="w-coord-hud" id="w-coord-hud">N 07° 12.3522' &nbsp; E 081° 39.5479'</div>
  </div>
</div>

<!-- Modal Dialog to Name & Save New Geofence -->
<div id="save-gf-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:6px;width:380px;padding:20px;box-shadow:0 10px 25px rgba(0,0,0,0.2)">
    <h3 style="margin:0 0 14px;font-size:15px;color:#1e293b">Save New Geofence</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_geofence">
      <input type="hidden" name="coordinates" id="modal-coords">

      <div style="margin-bottom:12px">
        <label style="font-size:12px;color:#64748b;display:block;margin-bottom:4px">Geofence Name (e.g. D/207/02)</label>
        <input name="name" id="modal-name" required placeholder="D/207/02" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px">
      </div>

      <div style="margin-bottom:12px">
        <label style="font-size:12px;color:#64748b;display:block;margin-bottom:4px">Description</label>
        <input name="description" placeholder="Plantation block area" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px">
      </div>

      <div style="margin-bottom:16px">
        <label style="font-size:12px;color:#64748b;display:block;margin-bottom:4px">Zone Color</label>
        <input type="color" name="color" value="#4caf50" style="height:34px;width:60px;padding:2px;cursor:pointer">
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="w-icon-btn" onclick="document.getElementById('save-gf-modal').style.display='none'">Cancel</button>
        <button type="submit" class="w-icon-btn primary" style="padding:6px 14px">Save Geofence</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const map = StMap.create(document.getElementById('map'), <?= json_encode($CONFIG['map_center']) ?>, 13);
  
  // Format coordinate HUD
  map.on('mousemove', (e) => {
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    const fLat = (lat >= 0 ? 'N ' : 'S ') + Math.floor(Math.abs(lat)).toString().padStart(2, '0') + '° ' + ((Math.abs(lat) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    const fLng = (lng >= 0 ? 'E ' : 'W ') + Math.floor(Math.abs(lng)).toString().padStart(3, '0') + '° ' + ((Math.abs(lng) % 1) * 60).toFixed(4).padStart(7, '0') + '\'';
    document.getElementById('w-coord-hud').innerHTML = `${fLat} &nbsp; ${fLng}`;
  });

  const gfRows = document.querySelectorAll('.gf-item');
  const renderedPolys = {};

  // Render existing geofences
  gfRows.forEach(row => {
    try {
      const coords = JSON.parse(row.dataset.coords);
      if (!coords || coords.length < 3) return;

      const poly = L.polygon(coords, {
        color: '#4caf50',
        fillColor: '#4caf50',
        fillOpacity: 0.28,
        weight: 2
      }).addTo(map);

      const name = row.querySelector('strong').textContent;
      const center = poly.getBounds().getCenter();
      L.marker(center, {
        icon: L.divIcon({
          className: 'w-geofence-label',
          html: `<div style="color:#e65100;font-weight:700;font-size:12px;background:rgba(255,255,255,0.7);padding:2px 6px;border-radius:3px">${name}</div>`,
          iconSize: [80, 20],
          iconAnchor: [40, 10]
        })
      }).addTo(map);

      renderedPolys[row.dataset.id] = poly;
    } catch(e) {}
  });

  // Clicking a row flies to geofence
  document.getElementById('gf-tbody').addEventListener('click', (e) => {
    const row = e.target.closest('.gf-item');
    if (!row) return;
    const poly = renderedPolys[row.dataset.id];
    if (poly) map.fitBounds(poly.getBounds(), { padding: [40, 40] });
  });

  // Interactive Polygon Drawing
  let drawingMode = false;
  let drawPoints = [];
  let tempMarkers = [];
  let tempPoly = null;

  document.getElementById('btn-draw-poly').addEventListener('click', () => {
    drawingMode = true;
    drawPoints = [];
    tempMarkers = [];
    document.getElementById('draw-banner').style.display = 'block';
  });

  map.on('click', (e) => {
    if (!drawingMode) return;
    drawPoints.push([e.latlng.lat, e.latlng.lng]);

    const m = L.circleMarker(e.latlng, { radius: 5, color: '#1565c0', fillColor: '#fff', fillOpacity: 1 }).addTo(map);
    tempMarkers.push(m);

    if (drawPoints.length >= 2) {
      if (tempPoly) map.removeLayer(tempPoly);
      tempPoly = L.polygon(drawPoints, { color: '#1565c0', fillColor: '#2196f3', fillOpacity: 0.2, dashArray: '4' }).addTo(map);
    }
  });

  document.getElementById('btn-finish-poly').addEventListener('click', () => {
    if (drawPoints.length < 3) {
      alert('Please click at least 3 points on the map to create a boundary.');
      return;
    }
    document.getElementById('modal-coords').value = JSON.stringify(drawPoints);
    document.getElementById('save-gf-modal').style.display = 'flex';
  });

  document.getElementById('btn-cancel-poly').addEventListener('click', () => {
    drawingMode = false;
    document.getElementById('draw-banner').style.display = 'none';
    tempMarkers.forEach(m => map.removeLayer(m));
    if (tempPoly) map.removeLayer(tempPoly);
    drawPoints = [];
  });
});
</script>

<?php layout_end(); ?>
