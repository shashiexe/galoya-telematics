<?php
require __DIR__ . '/_common.php';
require_admin();

// Reverse geocoding endpoint with DB cache & Geofence detection
if (isset($_GET['geocode'])) {
    header('Content-Type: application/json');
    $lat = round((float)($_GET['lat'] ?? 0), 4);
    $lng = round((float)($_GET['lng'] ?? 0), 4);
    if (!$lat || !$lng) {
        echo json_encode(['ok' => false, 'address' => 'Unknown coordinates']);
        exit;
    }

    // 1. Check local DB cache
    try {
        q("CREATE TABLE IF NOT EXISTS geocode_cache (
            lat_round DECIMAL(8,4),
            lng_round DECIMAL(8,4),
            address VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (lat_round, lng_round)
        )");
        $cached = q("SELECT address FROM geocode_cache WHERE lat_round=? AND lng_round=? LIMIT 1", [$lat, $lng])->fetchColumn();
        if ($cached) {
            echo json_encode(['ok' => true, 'address' => $cached, 'cached' => true]);
            exit;
        }
    } catch (Throwable $e) {}

    // 2. Check if inside any local Galoya Geofence
    $geofenceName = null;
    try {
        $gfs = q("SELECT name, coordinates FROM geofences WHERE active=1 LIMIT 100")->fetchAll();
        foreach ($gfs as $gf) {
            $coords = json_decode($gf['coordinates'], true);
            if (is_array($coords) && count($coords) >= 3) {
                $inside = false;
                $cnt = count($coords);
                for ($i = 0, $j = $cnt - 1; $i < $cnt; $j = $i++) {
                    $xi = (float)$coords[$i][0]; $yi = (float)$coords[$i][1];
                    $xj = (float)$coords[$j][0]; $yj = (float)$coords[$j][1];
                    $intersect = (($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);
                    if ($intersect) $inside = !$inside;
                }
                if ($inside) {
                    $geofenceName = $gf['name'];
                    break;
                }
            }
        }
    } catch (Throwable $e) {}

    // 3. Query OpenStreetMap Nominatim
    $address = '';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init("https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&zoom=18&addressdetails=1");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GaloyaTelematics/1.0 (info@galoya.lk)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if (!$raw) {
        $ctx = stream_context_create([
            'http' => ['header' => "User-Agent: GaloyaTelematics/1.0\r\n", 'timeout' => 3],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $raw = @file_get_contents("https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&zoom=18&addressdetails=1", false, $ctx);
    }

    if ($raw) {
        $d = json_decode($raw, true);
        if (!empty($d['address'])) {
            $a = $d['address'];
            $road = $a['road'] ?? ($a['pedestrian'] ?? ($a['suburb'] ?? ($a['neighbourhood'] ?? '')));
            $village = $a['village'] ?? ($a['town'] ?? ($a['city'] ?? ($a['municipality'] ?? ($a['county'] ?? ''))));
            $state = $a['state_district'] ?? ($a['state'] ?? 'Ampara');
            $parts = array_unique(array_filter([$road, $village, $state, 'Sri Lanka']));
            $address = implode(', ', $parts);
        } elseif (!empty($d['display_name'])) {
            $address = $d['display_name'];
        }
    }

    if (empty($address)) {
        if ($lat >= 7.20 && $lat <= 7.35 && $lng >= 81.60 && $lng <= 81.75) {
            $address = "Hingurana / Ampara Region, Eastern Province, Sri Lanka";
        } else {
            $address = sprintf("Coordinates: %.5f, %.5f", $lat, $lng);
        }
    }

    if ($geofenceName) {
        $address = "⬡ " . $geofenceName . " · " . $address;
    }

    // Save to cache
    try {
        q("INSERT INTO geocode_cache (lat_round, lng_round, address) VALUES (?,?,?) ON DUPLICATE KEY UPDATE address=VALUES(address)", [$lat, $lng, $address]);
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'address' => $address]);
    exit;
}

// JSON feed for live telematics refresh
if (isset($_GET['feed'])) {
    header('Content-Type: application/json');
    $off = (int)$CONFIG['offline_after_min'];

    // High-speed auto-sync from Wialon if last sync was > 10 seconds ago
    try {
        $lastSeenVal = q("SELECT last_seen FROM users WHERE code IN ('SRV-ROOM', 'DAH-8851', 'LK-3313') ORDER BY last_seen DESC LIMIT 1")->fetchColumn();
        if (!$lastSeenVal || (time() - strtotime($lastSeenVal)) > 10) {
            require_once __DIR__ . '/../sync_server_room.php';
            if (function_exists('sync_wialon_fleet_fast')) {
                sync_wialon_fleet_fast();
            }
        }
    } catch (Throwable $e) {}
    
    // Fetch units with telematics data filtered by assigned section scope
    $secScope = user_section_scope_sql('users');
    $units = q("SELECT id, name, code, phone, role, unit_type, device_type, unique_id, sensors_json, properties_json, on_duty, active,
                       department_id, section_id,
                       last_lat lat, last_lng lng, last_battery battery, speed, ignition, motion_state, sos_active, last_seen,
                       TIMESTAMPDIFF(SECOND, last_seen, NOW()) secs_ago,
                       TIMESTAMPDIFF(MINUTE, last_seen, NOW()) mins_ago,
                       (SELECT name FROM drivers WHERE assigned_unit_id=users.id LIMIT 1) driver_name,
                       (SELECT COUNT(*) FROM tasks WHERE assigned_to=users.id AND status='in_progress') active_tasks
                FROM users WHERE (role IN ('staff', 'facility', 'unit', 'device') OR unit_type NOT IN ('staff', 'field_staff')) AND active=1 AND $secScope
                ORDER BY sos_active DESC, (motion_state='moving') DESC, on_duty DESC, name ASC")->fetchAll();

    foreach ($units as &$u) {
        $u['is_online'] = ($u['last_seen'] !== null && (int)$u['mins_ago'] <= $off && (int)$u['on_duty'] === 1);
        if (empty($u['device_type'])) {
            $u['device_type'] = ($u['unit_type'] === 'staff') ? 'Android Phone (Samsung Galaxy S21)' : 'Teltonika FMB125';
        }
        if (empty($u['unique_id'])) {
            $u['unique_id'] = ($u['unit_type'] === 'staff' ? '8661920' : '3505445') . str_pad((string)$u['id'], 8, '0', STR_PAD_LEFT);
        }
    }

    // Live telematics trails: Start clean on page load, accumulate only during active live monitoring session
    $trails = [];

    // Fetch active SOS alerts filtered by scope
    $sosScope = user_section_scope_sql('u');
    $sos_alerts = q("SELECT s.*, u.name, u.phone FROM sos_alerts s JOIN users u ON u.id=s.user_id WHERE s.status='active' AND $sosScope ORDER BY s.id DESC LIMIT 5")->fetchAll();

    echo json_encode([
        'units' => $units,
        'trails' => $trails,
        'sos' => $sos_alerts,
        'center' => $CONFIG['map_center'],
        'zoom' => (int)$CONFIG['map_zoom']
    ]);
    exit;
}

layout_start('Monitoring', 'index.php', true);
?>
<style>
  /* Smooth Marker Glide for Live Telematics (Eliminates abrupt jumping) */
  .leaflet-marker-pane .w-telematics-pin {
    transition: transform 0.4s ease-out !important;
  }

  /* Offline Employee / Unit Dimming (Live online employees pop vibrant and clear on the map) */
  .w-wialon-marker-wrap.is-offline-marker {
    opacity: 0.40;
    filter: grayscale(75%);
    transition: opacity 0.3s ease, filter 0.3s ease;
  }
  .w-wialon-marker-wrap.is-offline-marker .w-unit-name-label {
    background: rgba(241, 245, 249, 0.85);
    color: #64748b;
    border-color: rgba(148, 163, 184, 0.4);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
  }
  .w-wialon-marker-wrap.is-offline-marker:hover {
    opacity: 1 !important;
    filter: none !important;
    z-index: 2500 !important;
  }

  /* Emergency SOS Alert Top Banner */
  .w-sos-container {
    position: absolute;
    top: 14px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 1000;
    max-width: 90%;
    display: none;
    pointer-events: auto;
  }
  .w-sos-banner {
    background: linear-gradient(135deg, #dc2626, #991b1b);
    color: #ffffff;
    padding: 10px 18px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.65), 0 0 0 3px rgba(254, 202, 202, 0.6);
    display: flex;
    align-items: center;
    gap: 16px;
    animation: sosPulseBanner 1.2s infinite alternate ease-in-out;
    font-size: 13px;
  }
  @keyframes sosPulseBanner {
    0% { transform: scale(1); box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5); }
    100% { transform: scale(1.02); box-shadow: 0 6px 28px rgba(220, 38, 38, 0.9), 0 0 0 6px rgba(254, 202, 202, 0.7); }
  }
  .w-sos-pulse-icon {
    font-size: 28px;
    animation: sosShake 0.5s infinite;
  }
  @keyframes sosShake {
    0%, 100% { transform: rotate(0deg); }
    25% { transform: rotate(-12deg); }
    75% { transform: rotate(12deg); }
  }
  .w-sos-btn {
    border: none;
    padding: 6px 14px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    transition: transform 0.1s, opacity 0.15s;
  }
  .w-sos-btn.focus-btn {
    background: #ffffff;
    color: #dc2626;
  }
  .w-sos-btn.silence-btn {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.5);
  }
  .w-sos-btn.resolve-btn {
    background: #16a34a;
    color: #ffffff;
  }
  .w-sos-btn:hover {
    transform: translateY(-1px);
    opacity: 0.95;
  }
  /* Pulsing Red Radar Ring for SOS Units on Map */
  .w-sos-marker-ring {
    position: absolute;
    width: 70px;
    height: 70px;
    top: -35px;
    left: -35px;
    border-radius: 50%;
    border: 3px solid #dc2626;
    background: rgba(220, 38, 38, 0.35);
    animation: sosRingPulse 1.2s infinite ease-out;
    pointer-events: none;
  }
  @keyframes sosRingPulse {
    0% { transform: scale(0.5); opacity: 1; }
    100% { transform: scale(2.2); opacity: 0; }
  }

  /* Wialon Toolbar Layout (Screenshots 2 & 3) */
  .w-wialon-unit-toolbar {
    padding: 6px 8px;
    background: #f1f5f9;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
  }
  .w-wb-btn {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 2px 7px;
    font-size: 13px;
    cursor: pointer;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    height: 26px;
    line-height: 1;
  }
  .w-wb-btn:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
  }
  .w-wb-btn.primary {
    background: #0284c7;
    color: #ffffff;
    border-color: #0369a1;
    font-weight: 800;
  }
  .w-wb-btn.primary:hover {
    background: #0369a1;
  }
  .w-wb-divider {
    width: 1px;
    height: 18px;
    border-right: 1px dashed #94a3b8;
    margin: 0 3px;
  }
  .w-wb-actions {
    display: flex;
    align-items: center;
    gap: 3px;
  }
  .w-wb-action-btn {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    padding: 2px 5px;
    font-size: 13.5px;
    cursor: pointer;
    color: #475569;
    transition: all 0.15s;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .w-wb-action-btn:hover {
    background: #e2e8f0;
    border-color: #cbd5e1;
  }
  .w-wb-action-btn.active-sort {
    background: #e0f2fe;
    border-color: #38bdf8;
    color: #0284c7;
  }

  /* Wialon Floating Hover Info Card (Screenshot 1 & 2) */
  .w-hover-card {
    position: fixed;
    top: 70px;
    left: 360px;
    width: 395px;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 16px 42px rgba(0, 0, 0, 0.28), 0 2px 8px rgba(0, 0, 0, 0.12);
    border: 1px solid #cbd5e1;
    padding: 14px 16px;
    z-index: 9999;
    font-size: 11.5px;
    color: #1e293b;
    pointer-events: auto;
    line-height: 1.4;
  }
  .w-hc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 6px;
  }
  .w-hc-name {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
  }
  .w-hc-time {
    text-align: right;
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
  }
  .w-hc-loc-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 6px;
    color: #334155;
    font-size: 11px;
  }
  .w-hc-loc-actions {
    display: flex;
    gap: 6px;
    cursor: pointer;
    color: #0284c7;
    font-size: 12px;
  }
  .w-hc-speed-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 10px;
    font-size: 12px;
    color: #0284c7;
  }
  .w-hc-section-title {
    font-weight: 700;
    color: #0f172a;
    margin: 6px 0 4px 0;
    font-size: 11.5px;
  }
  .w-hc-conn-grid {
    display: flex;
    flex-direction: column;
    gap: 2px;
    color: #475569;
  }
  .w-hc-params-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 4px;
  }
  .w-hc-param-cell {
    padding: 3px 8px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    font-family: monospace;
    font-size: 11px;
  }
  .w-hc-param-cell:nth-child(odd) {
    border-right: 1px solid #e2e8f0;
  }

  /* ================= WIALON SENSOR PROPERTIES MODAL ================= */
  #sensor-properties-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(3px);
    z-index: 10050;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: fadeIn 0.15s ease-out;
  }
  .w-sp-window {
    background: #ffffff;
    border-radius: 6px;
    border: 1px solid #64748b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.45);
    width: 860px;
    max-width: 96vw;
    height: 600px;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    font-size: 12.5px;
    animation: popIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .w-sp-header {
    height: 38px;
    background: #2c3b4d;
    color: #ffffff;
    border-bottom: 1px solid #1e293b;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    font-weight: 700;
    user-select: none;
  }
  .w-sp-header-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13.5px;
    color: #ffffff;
  }
  .w-sp-close-btn {
    background: transparent;
    border: none;
    font-size: 18px;
    color: #cbd5e1;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    line-height: 1;
  }
  .w-sp-close-btn:hover {
    background: #dc2626;
    color: #fff;
  }
  .w-sp-body-wrap {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }
  .w-sp-sidebar {
    width: 170px;
    background: #f8fafc;
    border-right: 1px solid #cbd5e1;
    display: flex;
    flex-direction: column;
    padding: 8px 0;
    user-select: none;
  }
  .w-sp-tab-btn {
    text-align: left;
    background: transparent;
    border: none;
    border-left: 3px solid transparent;
    padding: 9px 16px;
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all 0.12s;
  }
  .w-sp-tab-btn:hover {
    background: #f1f5f9;
    color: #0284c7;
  }
  .w-sp-tab-btn.active {
    background: #ffffff;
    color: #0284c7;
    border-left-color: #0284c7;
    font-weight: 700;
  }
  .w-sp-content {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    background: #ffffff;
  }
  .w-sp-pane {
    display: none;
  }
  .w-sp-pane.active {
    display: block;
  }
  .w-sp-form-grid {
    display: grid;
    grid-template-columns: 180px 1fr;
    row-gap: 12px;
    column-gap: 16px;
    align-items: center;
    max-width: 680px;
  }
  .w-sp-form-grid label {
    font-size: 12px;
    color: #334155;
    font-weight: 600;
  }
  .w-sp-help {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    border: 1px solid #94a3b8;
    color: #64748b;
    font-size: 10px;
    margin-left: 4px;
    cursor: help;
  }
  .w-sp-footer {
    height: 48px;
    background: #f8fafc;
    border-top: 1px solid #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 0 16px;
  }
  .w-sp-calib-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 2px;
  }
  .w-sp-calib-table th {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    padding: 5px 8px;
    font-weight: 700;
    color: #334155;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 10;
  }
  .w-sp-calib-table td {
    border: 1px solid #e2e8f0;
    padding: 4px 6px;
  }
  .w-sp-calib-table input[type="number"] {
    width: 100%;
    box-sizing: border-box;
    padding: 4px 6px;
    font-size: 11.5px;
    border: 1px solid #cbd5e1;
    border-radius: 3px;
  }
</style>

<div class="w-monitoring-layout">
  <!-- Left Units Sidebar (Screenshot 3) -->
  <aside class="w-units-sidebar">
    <div class="w-units-searchbox">
      <input type="text" id="unit-search" placeholder="Search units, vehicles, staff...">
      <button class="w-icon-btn" id="btn-clear-search" title="Clear">✕</button>
    </div>

    <!-- Wialon Toolbar (Matching User Screenshots 2 & 3) -->
    <div class="w-wialon-unit-toolbar">
      <div style="display:flex;align-items:center;gap:4px">
        <?php if (can('units', 'create')): ?>
          <button type="button" class="w-wb-btn primary" onclick="openAddUnitModal()" title="Add Unit / Register Device">➕</button>
        <?php endif; ?>
        <button type="button" class="w-wb-btn" onclick="refreshFeedNow()" id="btn-refresh" title="Refresh telematics feed">↻</button>
        <button type="button" class="w-wb-btn" onclick="toggleGroupView()" id="btn-group-toggle" title="Toggle Category Groups / Flat View">☷</button>
        <button type="button" class="w-wb-btn" onclick="toggleSortAZ()" id="btn-sort-az" title="Sort Alphabetically (A-Z)">A↓</button>
      </div>

      <div class="w-wb-divider"></div>

      <div class="w-wb-actions">
        <button type="button" class="w-wb-action-btn" onclick="fitAllVisibleUnits()" title="Center All Visible Units on Map">🎯</button>
        <button type="button" class="w-wb-action-btn" id="btn-sort-motion" onclick="sortByMotion()" title="Sort by Motion (Moving first)">➔</button>
        <button type="button" class="w-wb-action-btn active-sort" id="btn-sort-satellite" onclick="sortByOnlineStatus()" title="Sort Online Units to Top">📡</button>
        <button type="button" class="w-wb-action-btn" id="btn-sort-sensor" onclick="sortBySensors()" title="Sort by Battery / Sensors">🖧</button>
        <?php if (can('units', 'edit')): ?>
          <button type="button" class="w-wb-action-btn" onclick="openUnitPropertiesModal(currentSelectedUnitId || 2)" title="Unit Properties">🔧</button>
        <?php endif; ?>
        <?php if (can('reports', 'view')): ?>
          <button type="button" class="w-wb-action-btn" onclick="openReportsForUnit()" title="Quick Reports">📋</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Units Telematics Table -->
    <div class="w-units-table-container">
      <table class="w-units-table">
        <colgroup>
          <col style="width:26px">
          <col style="width:auto">
          <col style="width:52px">
          <col style="width:28px">
          <col style="width:38px">
          <col style="width:76px">
        </colgroup>
        <thead>
          <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
            <th style="text-align:center;padding:6px 2px">
              <input type="checkbox" id="chk-master" checked title="Select All / Deselect All">
            </th>
            <th style="cursor:pointer;padding:6px 6px" onclick="toggleSortAZ()" title="Click to sort by Name">
              Unit / Machine <span id="sort-name-indicator"></span>
            </th>
            <th style="text-align:center;cursor:pointer;padding:6px 2px" onclick="sortByMotion()" title="Motion State / Speed (Click to sort)">➔</th>
            <th style="text-align:center;cursor:pointer;padding:6px 2px" onclick="sortByOnlineStatus()" title="Connection Status (Click to sort Online units to top)"><span style="font-size:12px" title="GPS Signal">📡</span></th>
            <th style="text-align:right;cursor:pointer;padding:6px 4px" onclick="sortBySensors()" title="Battery Status (Click to sort)">🔋</th>
            <th style="text-align:center;padding:6px 2px" title="Actions: Solo Focus, Properties, Delete">Actions</th>
          </tr>
        </thead>
        <tbody id="units-tbody">
          <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8">Loading telematics feed...</td></tr>
        </tbody>
      </table>
    </div>

    <div style="padding:6px 10px;background:#f8fafc;border-top:1px solid var(--border);font-size:11px;color:#64748b;display:flex;justify-content:space-between">
      <span id="units-summary-count">0 units</span>
      <span>Auto-refresh: <strong>4s</strong></span>
    </div>
  </aside>

  <!-- Center Map View -->
  <div class="w-map-wrapper">
    <!-- Emergency SOS Alert Container -->
    <div id="sos-container" class="w-sos-container"></div>

    <div id="map"></div>

    <!-- Wialon Floating Unit Info Card (Matching Screenshot 1) -->
    <div id="w-unit-hover-card" class="w-hover-card" style="display:none">
      <div class="w-hc-header">
        <div class="w-hc-name" id="hc-name">BJB-1412 (W.Bicycle)</div>
        <div class="w-hc-time">
          <div id="hc-time-ago">1 min 55 s ago</div>
          <div id="hc-datetime" style="color:#64748b;font-size:10px">16.10.2026 12:36:11</div>
        </div>
      </div>
      
      <div class="w-hc-loc-row">
        <div id="hc-address" class="w-hc-address">Siyambalanduwa-Damana-Ampara Road, Eggaloya, Damana, Ampara, Eastern, Sri Lanka</div>
        <div class="w-hc-loc-actions">
          <span title="Center on sector" onclick="centerOnUnitSector()">⬡</span>
          <span title="Open in Google Maps" onclick="openUnitInGoogleMaps()">↗</span>
        </div>
      </div>

      <div class="w-hc-speed-sat-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:12px">
        <div style="display:flex;align-items:center;gap:6px;color:#0284c7">
          <span>⏱️</span>
          <strong id="hc-speed">0 km/h</strong>
        </div>
        <div style="display:flex;align-items:center;gap:4px;color:#475569;font-weight:700">
          <span>📡</span>
          <span id="hc-sats">12</span>
        </div>
      </div>

      <div class="w-hc-section-title">Sensor values:</div>
      <div class="w-hc-sensors-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:4px;background:#f8fafc;border:1px solid #e2e8f0;padding:6px 10px;border-radius:4px;margin-bottom:6px;font-size:11.5px">
        <div><strong id="hc-sensor-engine-label">ENGINE:</strong> <span id="hc-sensor-engine" style="font-weight:700;color:#16a34a">On</span></div>
        <div><strong id="hc-sensor-fls-label">FLS:</strong> <span id="hc-sensor-fls" style="font-weight:700;color:#0284c7">---</span></div>
      </div>

      <div class="w-hc-section-title">Connectivity settings:</div>
      <div class="w-hc-conn-grid">
        <div><strong>Device type:</strong> <span id="hc-device-type">Teltonika FMB125</span></div>
        <div><strong>Phone:</strong> <span id="hc-phone">+94768746361</span></div>
        <div><strong>Unique ID:</strong> <span id="hc-unique-id">350544509403849</span></div>
      </div>

      <div class="w-hc-section-title" style="margin-top:8px">Parameters:</div>
      <div class="w-hc-params-grid" id="hc-params-grid">
        <div class="w-hc-param-cell"><span>event_io_id:</span> <strong id="hc-event-io">0</strong></div>
        <div class="w-hc-param-cell"><span>gsm:</span> <strong id="hc-gsm">3</strong></div>
        <div class="w-hc-param-cell"><span>adc1:</span> <strong id="hc-adc1">83</strong></div>
        <div class="w-hc-param-cell"><span>alarm_code:</span> <strong>15</strong></div>
        <div class="w-hc-param-cell"><span>alarm_val:</span> <strong>3</strong></div>
        <div class="w-hc-param-cell"><span>cell_id:</span> <strong>13406</strong></div>
        <div class="w-hc-param-cell"><span>csq:</span> <strong id="hc-csq">28</strong></div>
        <div class="w-hc-param-cell"><span>I/O:</span> <strong>0/0</strong></div>
        <div class="w-hc-param-cell"><span>in:</span> <strong>0</strong></div>
        <div class="w-hc-param-cell"><span>lac:</span> <strong>20037</strong></div>
        <div class="w-hc-param-cell"><span>mcc:</span> <strong>413</strong></div>
        <div class="w-hc-param-cell"><span>mileage:</span> <strong id="hc-mileage">22279400</strong></div>
        <div class="w-hc-param-cell"><span>mnc:</span> <strong>2</strong></div>
        <div class="w-hc-param-cell"><span>out:</span> <strong>0</strong></div>
        <div class="w-hc-param-cell"><span>pwr_ext:</span> <strong id="hc-pwr-ext">12.4V</strong></div>
        <div class="w-hc-param-cell"><span>pwr_int:</span> <strong id="hc-pwr-int">85%</strong></div>
      </div>
    </div>

    <!-- Wialon Online Notifications Toast (Screenshot 2 Top Right) -->
    <div id="w-online-notif-toast" style="display:none;position:fixed;top:52px;right:18px;width:340px;background:#334155;color:#f8fafc;border-radius:6px;box-shadow:0 12px 32px rgba(0,0,0,0.45);z-index:2200;border:1px solid #475569;font-size:12px;overflow:hidden">
      <div style="background:#1e293b;padding:8px 12px;font-weight:700;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #475569">
        <span>Online Notifications</span>
        <button type="button" onclick="closeOnlineNotifToast()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;font-weight:700">✕</button>
      </div>
      <div id="w-online-notif-body" style="padding:10px 12px;max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:11.5px">
          <div style="display:flex;align-items:center;gap:6px">
            <span>🚛</span> <strong>LK-1539 (Fire Bows)</strong> <span style="color:#f87171">Fuel Theft -Galoya</span>
          </div>
          <span style="color:#ef4444;font-size:14px">●</span>
        </div>
      </div>
      <div style="background:#1e293b;padding:6px 12px;display:flex;justify-content:space-between;font-size:11px;border-top:1px solid #475569">
        <a href="javascript:void(0)" onclick="clearAllAlarms()" style="color:#38bdf8;text-decoration:none">Delete all</a>
        <a href="javascript:void(0)" onclick="closeOnlineNotifToast()" style="color:#94a3b8;text-decoration:none">Delete read</a>
      </div>
    </div>

    <!-- Live Coordinate HUD at bottom right (Screenshot 3: N 07° 12.3522' E 081° 39.5479') -->
    <div class="w-coord-hud" id="w-coord-hud">
      N 07° 12.3522' &nbsp; E 081° 39.5479'
    </div>
  </div>
</div>

<!-- ==================== WIALON ADD DEVICE MODAL ==================== -->
<div id="add-unit-modal" class="w-prop-modal-backdrop" style="display:none">
  <div class="w-prop-window" style="max-width:520px">
    <div class="w-prop-header" style="background:#0284c7;color:#fff">
      <div class="w-prop-header-title" style="color:#fff">
        <span>➕</span> <span>Register New Fleet Device (Wialon Standard)</span>
      </div>
      <button type="button" class="w-prop-close-btn" onclick="closeAddUnitModal()" style="color:#fff">✕</button>
    </div>
    <div style="padding:18px">
      <div style="display:grid;grid-template-columns:130px 1fr;gap:12px;align-items:center;font-size:12.5px">
        <label><strong>Unit Name: *</strong></label>
        <input type="text" id="new-unit-name" class="w-prop-input" placeholder="e.g. 2813 (Harvester) or 49-1667">

        <label><strong>Code / Plate:</strong></label>
        <input type="text" id="new-unit-code" class="w-prop-input" placeholder="e.g. 2813 or DAH-8852">

        <label><strong>Category / Type: *</strong></label>
        <select id="new-unit-type" class="w-prop-input" style="height:32px">
          <optgroup label="👨‍💼 Personnel & Field Staff">
            <option value="field_staff">👨‍🌾 Field Staff / Officer</option>
            <option value="supervisor">👷‍♂️ Field Supervisor</option>
            <option value="manager">👔 Division / Estate Manager</option>
            <option value="security">🛡️ Field Security / Watcher</option>
            <option value="staff">📱 WiaTag Mobile Staff</option>
          </optgroup>
          <optgroup label="🚜 Agricultural Machinery">
            <option value="tractor" selected>🚜 Agricultural Tractor</option>
            <option value="harvester">🌾 Sugarcane Harvester</option>
            <option value="infielder">🚛 Infield Transporter</option>
            <option value="excavator">🏗️ Backhoe / Excavator</option>
          </optgroup>
          <optgroup label="🚚 Fleet Vehicles & Transport">
            <option value="truck">🚚 Cane Transport Lorry</option>
            <option value="vehicle">🚙 Bolero 4x4 / Inspection</option>
            <option value="motorcycle">🏍️ Field Motorcycle</option>
          </optgroup>
        </select>

        <label><strong>Device Model:</strong></label>
        <select id="new-unit-device" class="w-prop-input" style="height:32px">
          <option value="Fifotrack A300">Fifotrack A300 (Wialon Default)</option>
          <option value="Teltonika FMB125">Teltonika FMB125 (Dual SIM, RS232)</option>
          <option value="Teltonika FMC130">Teltonika FMC130 (4G LTE)</option>
          <option value="Concox GT06N">Concox GT06N</option>
          <option value="Coban GPS103">Coban GPS103 / TK103</option>
        </select>

        <label><strong>Unique ID / IMEI:</strong></label>
        <input type="text" id="new-unit-imei" class="w-prop-input" placeholder="15-digit IMEI or Tracker ID">

        <label><strong>SIM Phone No:</strong></label>
        <input type="text" id="new-unit-phone" class="w-prop-input" placeholder="+9474...">
      </div>

      <div style="margin-top:18px;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="btn ghost" onclick="closeAddUnitModal()">Cancel</button>
        <button type="button" class="btn" style="background:#0284c7;color:#fff" onclick="submitCreateUnit()">Save & Add to Fleet</button>
      </div>
    </div>
  </div>
</div>


<!-- ==================== WIALON UNIT PROPERTIES MODAL DIALOG (USER SCREENSHOTS 1-5) ==================== -->
<div id="unit-properties-modal" class="w-prop-modal-backdrop" style="display:none">
  <div class="w-prop-window">
    <!-- Window Header (Matching Screenshots) -->
    <div class="w-prop-header">
      <div class="w-prop-header-title">
        <span>🔧</span>
        <span>Unit properties - <strong id="prop-unit-name-title">49-1665</strong></span>
      </div>
      <button type="button" class="w-prop-close-btn" onclick="closeUnitPropertiesModal()" title="Close">✕</button>
    </div>

    <!-- Two-Row Tabs Bar (Matching Screenshots 1 & 2) -->
    <div class="w-prop-tabs-bar">
      <!-- Row 1 -->
      <button type="button" class="w-prop-tab-btn active" data-tab="general" onclick="switchPropTab('general')">General</button>
      <button type="button" class="w-prop-tab-btn" data-tab="access" onclick="switchPropTab('access')">Access</button>
      <button type="button" class="w-prop-tab-btn" data-tab="icon" onclick="switchPropTab('icon')">Icon</button>
      <button type="button" class="w-prop-tab-btn" data-tab="advanced" onclick="switchPropTab('advanced')">Advanced</button>
      <button type="button" class="w-prop-tab-btn" data-tab="sensors" onclick="switchPropTab('sensors')">Sensors</button>
      <button type="button" class="w-prop-tab-btn" data-tab="custom_fields" onclick="switchPropTab('custom_fields')">Custom fields</button>
      <button type="button" class="w-prop-tab-btn" data-tab="unit_groups" onclick="switchPropTab('unit_groups')">Unit groups</button>
      <button type="button" class="w-prop-tab-btn" data-tab="commands" onclick="switchPropTab('commands')">Commands</button>
      <!-- Row 2 -->
      <button type="button" class="w-prop-tab-btn" data-tab="eco_driving" onclick="switchPropTab('eco_driving')">Eco driving</button>
      <button type="button" class="w-prop-tab-btn" data-tab="profile" onclick="switchPropTab('profile')">Profile</button>
      <button type="button" class="w-prop-tab-btn" data-tab="trip_detector" onclick="switchPropTab('trip_detector')">Trip detector</button>
      <button type="button" class="w-prop-tab-btn" data-tab="service_intervals" onclick="switchPropTab('service_intervals')">Service intervals</button>
      <button type="button" class="w-prop-tab-btn" data-tab="health_check" onclick="switchPropTab('health_check')">Health check</button>
    </div>

    <!-- Window Body -->
    <div class="w-prop-body">
      <!-- TAB 1: GENERAL (Screenshot 1) -->
      <div class="w-prop-pane active" id="pane-general">
        <input type="hidden" id="prop-unit-id" value="">
        <div class="w-prop-form-grid">
          <label class="required">Name:</label>
          <input type="text" class="w-prop-input" id="prop-name" value="49-1665">

          <label>Unit type:</label>
          <select class="w-prop-input" id="prop-unit-type" style="height:30px">
            <optgroup label="👨‍💼 Personnel & Field Staff">
              <option value="field_staff">👨‍🌾 Field Staff / Officer</option>
              <option value="supervisor">👷‍♂️ Field Supervisor</option>
              <option value="manager">👔 Division / Estate Manager</option>
              <option value="security">🛡️ Field Security / Watcher</option>
              <option value="staff">📱 WiaTag Mobile App / Staff</option>
            </optgroup>
            <optgroup label="🚜 Agricultural Machinery">
              <option value="tractor">🚜 Tractor (Estate Agriculture)</option>
              <option value="harvester">🌾 Harvester (Sugarcane Combine)</option>
              <option value="infielder">🚛 Infielder / Cane Loader</option>
              <option value="excavator">🏗️ Excavator / Backhoe</option>
            </optgroup>
            <optgroup label="🚚 Fleet Vehicles & Transport">
              <option value="truck">🚚 Cane Transport Lorry</option>
              <option value="vehicle">🚙 Vehicle / Bolero 4x4 / Heavy Truck</option>
              <option value="motorcycle">🏍️ Field Motorcycle / Trail Bike</option>
              <option value="bicycle">🚲 Service Bicycle</option>
            </optgroup>
          </select>

          <label class="required">Device type:</label>
          <div style="display:flex;align-items:center;gap:8px">
            <select class="w-prop-input" id="prop-device-type" style="height:30px;max-width:340px">
              <option value="Teltonika FMB125">Teltonika FMB125 (Dual SIM, RS232/RS485)</option>
              <option value="Teltonika FMC130">Teltonika FMC130 (4G LTE Cat 1)</option>
              <option value="Teltonika FMB920">Teltonika FMB920 (Compact GNSS/GSM)</option>
              <option value="WiaTag Mobile">WiaTag Mobile App (Android/iOS)</option>
              <option value="Coban GPS103">Coban GPS103 / TK103</option>
              <option value="Concox GT06N">Concox GT06N</option>
              <option value="Queclink GV300">Queclink GV300</option>
            </select>
            <span style="font-size:16px;cursor:pointer" title="Configure telematics device">🔧</span>
          </div>

          <label>Server address:</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="text" class="w-prop-input" id="prop-server-addr" value="nl.gpsgsm.org:21448" style="max-width:300px">
            <span style="background:#e2e8f0;border:1px solid #cbd5e1;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;color:#475569">IP</span>
          </div>

          <label>Unique ID:</label>
          <input type="text" class="w-prop-input" id="prop-unique-id" value="359632104298952" placeholder="IMEI or Tracker ID">

          <label>Phone number:</label>
          <input type="text" class="w-prop-input" id="prop-phone" value="+94742198748">

          <label>Password:</label>
          <input type="password" class="w-prop-input" id="prop-password" placeholder="••••••••">

          <label>Creator:</label>
          <input type="text" class="w-prop-input" value="galoyap.admin" disabled>

          <label>Account:</label>
          <input type="text" class="w-prop-input" value="Galoya Plantations (Pvt) Ltd" disabled>

          <label>Mileage counter:</label>
          <div class="w-counter-row">
            <select id="prop-mileage-mode">
              <option value="gps">GPS</option>
              <option value="sensor">Mileage sensor</option>
            </select>
            <span>Current value: *</span>
            <input type="number" id="prop-mileage-val" value="0" step="0.1">
            <span>km</span>
            <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;cursor:pointer">
              <input type="checkbox" id="prop-mileage-auto" checked> Auto
            </label>
          </div>

          <label>Engine hours counter:</label>
          <div class="w-counter-row">
            <select id="prop-hours-mode">
              <option value="ignition">Engine ignition sensor</option>
              <option value="absolute">Absolute engine hours</option>
            </select>
            <span>Current value: *</span>
            <input type="number" id="prop-hours-val" value="0" step="0.1">
            <span>h</span>
            <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;cursor:pointer">
              <input type="checkbox" id="prop-hours-auto" checked> Auto
            </label>
          </div>

          <label>GPRS traffic counter:</label>
          <div class="w-counter-row">
            <button type="button" onclick="document.getElementById('prop-traffic-val').value='0'" style="background:#e2e8f0;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;font-size:11px;cursor:pointer">Reset counter</button>
            <span>Current value:</span>
            <input type="number" id="prop-traffic-val" value="0">
            <span>KB</span>
            <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;cursor:pointer">
              <input type="checkbox" id="prop-traffic-auto" checked> Auto
            </label>
          </div>
        </div>
      </div>

      <!-- TAB 2: ACCESS (Screenshot 2) -->
      <div class="w-prop-pane" id="pane-access">
        <div class="w-access-layout">
          <div class="w-access-users-col">
            <div style="padding:8px;border-bottom:1px solid #cbd5e1">
              <input type="text" placeholder="Search user..." style="width:100%;padding:4px;font-size:11.5px;border:1px solid #cbd5e1;border-radius:3px">
            </div>
            <div style="overflow-y:auto;flex:1">
              <div class="w-access-user-item">fleet.galoya</div>
              <div class="w-access-user-item">fleet.galoyanew</div>
              <div class="w-access-user-item">fleet2.galoya</div>
              <div class="w-access-user-item">galoya</div>
              <div class="w-access-user-item">galoya1</div>
              <div class="w-access-user-item">galoya_geofence</div>
              <div class="w-access-user-item active">chandrasena</div>
              <div class="w-access-user-item">viraj</div>
            </div>
          </div>

          <div class="w-access-rights-col">
            <div class="w-access-presets">
              <span style="font-size:12px;color:#475569">Presets of access rights:</span>
              <button type="button" class="w-preset-btn w-preset-none" onclick="setAccessPreset('none')">No access</button>
              <button type="button" class="w-preset-btn w-preset-full" onclick="setAccessPreset('full')">Full access</button>
              <button type="button" class="w-preset-btn w-preset-read" onclick="setAccessPreset('read')">Read only</button>
            </div>

            <div class="w-rights-grid">
              <div class="w-rights-box">
                <h4>Standard access rights</h4>
                <label class="w-right-chk"><input type="checkbox" checked> View object and its basic properties</label>
                <label class="w-right-chk"><input type="checkbox" checked> View detailed object properties</label>
                <label class="w-right-chk"><input type="checkbox" checked> Manage access to this object</label>
                <label class="w-right-chk"><input type="checkbox" checked> Delete object</label>
                <label class="w-right-chk"><input type="checkbox" checked> Rename object</label>
                <label class="w-right-chk"><input type="checkbox" checked> View custom fields</label>
                <label class="w-right-chk"><input type="checkbox" checked> Manage custom fields</label>
                <label class="w-right-chk"><input type="checkbox" checked> View admin fields</label>
                <label class="w-right-chk"><input type="checkbox" checked> Manage admin fields</label>
                <label class="w-right-chk"><input type="checkbox" checked> Edit not mentioned properties</label>
              </div>

              <div class="w-rights-box">
                <h4>Special access rights</h4>
                <label class="w-right-chk"><input type="checkbox" checked> View connectivity settings</label>
                <label class="w-right-chk"><input type="checkbox" checked> Edit connectivity settings</label>
                <label class="w-right-chk"><input type="checkbox" checked> Create, edit, and delete sensors</label>
                <label class="w-right-chk"><input type="checkbox" checked> Edit counters</label>
                <label class="w-right-chk"><input type="checkbox" checked> Delete messages</label>
                <label class="w-right-chk"><input type="checkbox" checked> Send commands</label>
                <label class="w-right-chk"><input type="checkbox" checked> Manage events</label>
                <label class="w-right-chk"><input type="checkbox" checked> Edit task statuses</label>
                <label class="w-right-chk"><input type="checkbox" checked> Edit tasks</label>
                <label class="w-right-chk"><input type="checkbox" checked> View service intervals</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 3: ICON (Screenshot 3) -->
      <div class="w-prop-pane" id="pane-icon">
        <div class="w-icon-tab-layout">
          <div class="w-icon-preview-box">
            <div class="w-icon-selected-display" id="prop-icon-big-preview">🚜</div>
            <div>
              <input type="hidden" id="prop-icon-key" value="tractor_blue">
              <div style="font-weight:700;font-size:14px;color:#0f172a" id="prop-icon-label">Tractor (Blue Agriculture)</div>
              <div style="font-size:11.5px;color:#64748b;margin:4px 0 10px">Selected map unit symbol</div>
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                <input type="checkbox" id="prop-rotate-icon" checked> Rotate icon according to course / heading
              </label>
            </div>
          </div>

          <div>
            <div style="font-weight:700;font-size:12.5px;color:#334155;margin-bottom:8px">Wialon Icon Library (Choose icon)</div>
            <div class="w-icon-grid" id="prop-icon-grid">
              <!-- Generated by JS -->
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 4: ADVANCED (Screenshot 4) -->
      <div class="w-prop-pane" id="pane-advanced">
        <div class="w-prop-form-grid" style="max-width:680px">
          <label>Consumption by rates, l/100km:</label>
          <input type="number" class="w-prop-input" id="prop-consumption-rate" value="10" step="0.5">

          <label>Seasonal consumption:</label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" id="prop-seasonal-chk" checked> Enabled
          </label>

          <label>Consumption, l/100km:</label>
          <input type="number" class="w-prop-input" id="prop-seasonal-rate" value="12" step="0.5">

          <label>From / To:</label>
          <div style="display:flex;align-items:center;gap:8px">
            <span>From:</span>
            <input type="text" class="w-prop-input" value="1 December" style="max-width:130px">
            <span>To:</span>
            <input type="text" class="w-prop-input" value="29 February" style="max-width:130px">
          </div>

          <label>Urban speed limit, km/h:</label>
          <input type="number" class="w-prop-input" id="prop-speed-limit" value="60">

          <label>Maximum interval between messages, s:</label>
          <input type="number" class="w-prop-input" value="0">

          <label>Average speed between messages, km/h:</label>
          <input type="number" class="w-prop-input" value="1000">

          <label>Distance between coordinates, km:</label>
          <input type="number" class="w-prop-input" value="1000">

          <label>Daily rate of engine hours, h:</label>
          <input type="number" class="w-prop-input" value="0">

          <label>Mileage coefficient:</label>
          <input type="number" class="w-prop-input" value="0">
        </div>
      </div>

      <!-- TAB 5: SENSORS (Screenshot 5) -->
      <div class="w-prop-pane" id="pane-sensors">
        <div class="w-sensors-layout">
          <div class="w-sensors-toolbar">
            <div style="display:flex;gap:8px">
              <button type="button" class="w-btn-new-sensor" onclick="openSensorPropertiesModal(null)">+ New Sensor</button>
              <button type="button" class="w-prop-btn" onclick="alert('Mathematical consumption wizard ready')">Math consumption wizard</button>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
              <input type="text" id="sensor-filter" placeholder="Search sensors..." style="padding:4px 8px;font-size:11.5px;border:1px solid #cbd5e1;border-radius:4px" onkeyup="filterSensorsTable()">
            </div>
          </div>

          <div style="background:#f8fafc;padding:8px 12px;border:1px solid #e2e8f0;border-radius:4px;font-size:11.5px;color:#475569;display:flex;align-items:center;justify-content:space-between">
            <span>Most used sensors: <strong>Voltage sensor (V, pwr_int)</strong></span>
            <button type="button" class="w-prop-btn" style="padding:2px 8px;font-size:11px" onclick="addVoltageSensor()">+ Add Voltage</button>
          </div>

          <table class="w-sensor-table">
            <thead>
              <tr>
                <th style="width:24px"></th>
                <th>Name</th>
                <th>Type</th>
                <th>Unit</th>
                <th>Parameter</th>
                <th>Description</th>
                <th style="width:60px;text-align:center">Visible</th>
                <th style="width:50px;text-align:center">Tools</th>
              </tr>
            </thead>
            <tbody id="sensors-tbody">
              <!-- Populated by JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 6: CUSTOM FIELDS -->
      <div class="w-prop-pane" id="pane-custom_fields">
        <div class="w-prop-form-grid" id="custom-fields-container" style="max-width:680px">
          <!-- Populated by JS -->
        </div>
        <button type="button" class="w-prop-btn" style="margin-top:14px" onclick="addCustomFieldRow()">+ Add Field</button>
      </div>

      <!-- TAB 7: UNIT GROUPS -->
      <div class="w-prop-pane" id="pane-unit_groups">
        <h4 style="margin:0 0 12px">Galoya Plantation Unit Groups</h4>
        <div style="display:flex;flex-direction:column;gap:8px">
          <label class="w-right-chk"><input type="checkbox" checked> Hingurana Harvesters Group</label>
          <label class="w-right-chk"><input type="checkbox" checked> Estate Tractors Fleet (Sector D)</label>
          <label class="w-right-chk"><input type="checkbox"> Bolero Field Officers</label>
          <label class="w-right-chk"><input type="checkbox" checked> Heavy Machinery & Excavators</label>
          <label class="w-right-chk"><input type="checkbox"> Sugar Factory Transport Logistics</label>
        </div>
      </div>

      <!-- TAB 8: COMMANDS -->
      <div class="w-prop-pane" id="pane-commands">
        <h4 style="margin:0 0 12px">Configured GPRS / SMS Remote Commands</h4>
        <table class="w-sensor-table">
          <thead>
            <tr><th>Command Name</th><th>Channel</th><th>Type</th><th>Access</th></tr>
          </thead>
          <tbody>
            <tr><td><strong>Engine Stop</strong></td><td>TCP / GPRS</td><td>Output 1 (Relay Cut-off)</td><td>Admin only</td></tr>
            <tr><td><strong>Engine Resume</strong></td><td>TCP / GPRS</td><td>Output 1 (Restore)</td><td>Admin only</td></tr>
            <tr><td><strong>Force Location Ping</strong></td><td>TCP / GPRS</td><td>GPRS Ping</td><td>All users</td></tr>
            <tr><td><strong>Take Camera Photo</strong></td><td>TCP / GPRS</td><td>RS232 Camera Snapshot</td><td>All users</td></tr>
          </tbody>
        </table>
      </div>

      <!-- TAB 9: ECO DRIVING -->
      <div class="w-prop-pane" id="pane-eco_driving">
        <h4 style="margin:0 0 12px">Eco Driving & Machinery Protection Criteria</h4>
        <div class="w-prop-form-grid" style="max-width:640px">
          <label>Speeding penalty weight:</label>
          <input type="number" class="w-prop-input" value="10">

          <label>Harsh acceleration limit (g):</label>
          <input type="number" class="w-prop-input" value="0.3" step="0.05">

          <label>Harsh braking limit (g):</label>
          <input type="number" class="w-prop-input" value="0.4" step="0.05">

          <label>Excessive idling threshold (min):</label>
          <input type="number" class="w-prop-input" value="15">
        </div>
      </div>

      <!-- TAB 10: PROFILE -->
      <div class="w-prop-pane" id="pane-profile">
        <div class="w-prop-form-grid" style="max-width:640px">
          <label>Vehicle Brand / Model:</label>
          <input type="text" class="w-prop-input" value="Case IH / New Holland / Mahindra">

          <label>VIN / Chassis:</label>
          <input type="text" class="w-prop-input" value="LK-GOPL-491665">

          <label>Registration year:</label>
          <input type="number" class="w-prop-input" value="2021">

          <label>Fuel type:</label>
          <select class="w-prop-input" style="height:30px">
            <option>Diesel (Auto / Industrial)</option>
            <option>Petrol</option>
          </select>
        </div>
      </div>

      <!-- TAB 11: TRIP DETECTOR -->
      <div class="w-prop-pane" id="pane-trip_detector">
        <div class="w-prop-form-grid" style="max-width:640px">
          <label>Min moving speed, km/h:</label>
          <input type="number" class="w-prop-input" value="5">

          <label>Min parking duration, min:</label>
          <input type="number" class="w-prop-input" value="5">

          <label>GPS correction filter:</label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" checked> Filter invalid satellite coordinates
          </label>
        </div>
      </div>

      <!-- TAB 12: SERVICE INTERVALS -->
      <div class="w-prop-pane" id="pane-service_intervals">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <h4 style="margin:0">Machinery Preventive Maintenance Schedule</h4>
          <button type="button" class="w-btn-new-sensor" onclick="alert('Interval added')">+ Add Interval</button>
        </div>
        <table class="w-sensor-table">
          <thead>
            <tr><th>Service Name</th><th>Interval</th><th>Last Executed</th><th>Remaining</th><th>Status</th></tr>
          </thead>
          <tbody id="service-intervals-tbody">
            <!-- Populated by JS -->
          </tbody>
        </table>
      </div>

      <!-- TAB 13: HEALTH CHECK -->
      <div class="w-prop-pane" id="pane-health_check">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:6px;margin-bottom:14px;color:#166534">
          <strong>✓ All Telematics Diagnostics Normal</strong>
          <div style="font-size:11.5px;margin-top:4px">Unit hardware is responding to telemetry pings without error codes.</div>
        </div>
        <div class="w-prop-form-grid" style="max-width:600px">
          <label>Internal Battery Voltage:</label>
          <div style="font-weight:700;color:#15803d">12.8 V (Optimal)</div>

          <label>CAN-Bus Diagnostic Status:</label>
          <div>OBD-II Clean (0 active DTC codes)</div>

          <label>GPS Antenna Status:</label>
          <div>Internal Active Patch (Signal SNR: 38 dB-Hz)</div>

          <label>GSM Operator / Roaming:</label>
          <div>Dialog Axiata / Mobitel Sri Lanka (4G LTE Connected)</div>
        </div>
      </div>
    </div>

    <!-- Window Footer -->
    <div class="w-prop-footer">
      <a href="#" onclick="exportUnitProperties();return false;" style="font-size:12px;color:#0284c7;text-decoration:none">Export properties</a>
      <div style="display:flex;gap:10px">
        <button type="button" class="w-prop-btn" onclick="closeUnitPropertiesModal()">Cancel</button>
        <button type="button" class="w-prop-btn primary" id="btn-save-unit-prop" onclick="saveUnitProperties()">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- ==================== WIALON SENSOR PROPERTIES MODAL DIALOG (USER SCREENSHOTS) ==================== -->
<div id="sensor-properties-modal" style="display:none">
  <div class="w-sp-window">
    <!-- Header -->
    <div class="w-sp-header">
      <div class="w-sp-header-title">
        <span>⚙️</span>
        <span id="sp-modal-title">Sensor properties — FLS</span>
      </div>
      <button type="button" class="w-sp-close-btn" onclick="closeSensorPropertiesModal()" title="Close">✕</button>
    </div>

    <!-- Body Layout with Sidebar -->
    <div class="w-sp-body-wrap">
      <!-- Sidebar Tabs (Matching Wialon Screenshots) -->
      <div class="w-sp-sidebar">
        <button type="button" class="w-sp-tab-btn active" data-tab="general" onclick="switchSensorPropTab('general')">General</button>
        <button type="button" class="w-sp-tab-btn" data-tab="calc" onclick="switchSensorPropTab('calc')">Calculation table</button>
      </div>

      <!-- Right Content Panels -->
      <div class="w-sp-content">
        <!-- TAB 1: GENERAL -->
        <div class="w-sp-pane active" id="sp-pane-general">
          <div class="w-sp-form-grid">
            <label>Name: <span style="color:#dc2626">*</span></label>
            <input type="text" id="sp-name" class="w-prop-input" placeholder="e.g. FLS, Battery, Engine" required>

            <label>Sensor type: <span class="w-sp-help" title="Wialon standard sensor categories">?</span></label>
            <select id="sp-type" class="w-prop-input" onchange="onSensorTypeChanged()">
              <optgroup label="Mileage">
                <option value="Mileage sensor">Mileage sensor</option>
                <option value="Relative odometer">Relative odometer</option>
              </optgroup>
              <optgroup label="Digital">
                <option value="Engine ignition sensor">Engine ignition sensor</option>
                <option value="Alarm trigger">Alarm trigger</option>
                <option value="Private mode">Private mode</option>
                <option value="Real-time motion sensor">Real-time motion sensor</option>
                <option value="Custom digital sensor">Custom digital sensor</option>
              </optgroup>
              <optgroup label="Gauges">
                <option value="Voltage sensor">Voltage sensor</option>
                <option value="Weight sensor">Weight sensor</option>
                <option value="Accelerometer">Accelerometer</option>
                <option value="Temperature sensor">Temperature sensor</option>
                <option value="Temperature coefficient">Temperature coefficient</option>
              </optgroup>
              <optgroup label="Engine">
                <option value="Engine revolution sensor">Engine revolution sensor</option>
                <option value="Engine efficiency sensor">Engine efficiency sensor</option>
                <option value="Absolute engine hours">Absolute engine hours</option>
                <option value="Relative engine hours">Relative engine hours</option>
              </optgroup>
              <optgroup label="Fuel and energy">
                <option value="Fuel level sensor" selected>Fuel level sensor</option>
                <option value="Impulse fuel consumption sensor">Impulse fuel consumption sensor</option>
                <option value="Absolute fuel consumption sensor">Absolute fuel consumption sensor</option>
                <option value="Instant fuel consumption sensor">Instant fuel consumption sensor</option>
                <option value="Impulse fuel level sensor">Impulse fuel level sensor</option>
                <option value="Battery level sensor">Battery level sensor</option>
              </optgroup>
              <optgroup label="Other">
                <option value="Counter sensor">Counter sensor</option>
                <option value="Custom sensor">Custom sensor</option>
                <option value="Driver assignment">Driver assignment</option>
                <option value="Trailer assignment">Trailer assignment</option>
                <option value="Passenger sensor">Passenger sensor</option>
              </optgroup>
            </select>

            <label>Description:</label>
            <input type="text" id="sp-desc" class="w-prop-input" placeholder="e.g. Fuel tank 1 calibration or Main battery">

            <label>Parameter: <span style="color:#dc2626">*</span> <span class="w-sp-help" title="Hardware parameter sent by tracker">?</span></label>
            <div style="display:flex;gap:6px;align-items:center">
              <input type="text" id="sp-param" class="w-prop-input" placeholder="e.g. io_201" style="max-width:200px" required>
              <select id="sp-param-quick" class="w-prop-input" style="max-width:160px" onchange="if(this.value){document.getElementById('sp-param').value=this.value;this.value='';}">
                <option value="">Quick Preset...</option>
                <option value="io_201">io_201 (FLS Fuel Level)</option>
                <option value="io_1">io_1 (Ignition Status)</option>
                <option value="pwr_ext">pwr_ext (External 12V/24V)</option>
                <option value="pwr_int">pwr_int (Internal Battery)</option>
                <option value="io_25">io_25 (BLE Temp Sensor)</option>
                <option value="speed">speed (GPS Speed)</option>
                <option value="csq">csq (GSM Signal Level)</option>
                <option value="adc1">adc1 (Analog Input 1)</option>
              </select>
            </div>

            <label>Measurement system:</label>
            <select id="sp-meas-system" class="w-prop-input" style="max-width:200px">
              <option value="metric" selected>Metric</option>
              <option value="us">U.S.</option>
            </select>

            <label>Unit of measurement:</label>
            <input type="text" id="sp-unit" class="w-prop-input" placeholder="l, V, °C, km/h, On/Off, Ok/Not Ok" style="max-width:200px">

            <label>Last message only: <span class="w-sp-help" title="Do not interpolate historic values">?</span></label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
              <input type="checkbox" id="sp-last-msg"> Calculate only by last message
            </label>

            <label>Validator: <span class="w-sp-help" title="Validation condition">?</span></label>
            <select id="sp-validator" class="w-prop-input" style="max-width:200px">
              <option value="None" selected>None</option>
            </select>
          </div>

          <!-- Fuel Level Specific Section (Wialon Screenshot 1 & 2) -->
          <div id="sp-fuel-section" style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0">
            <div class="w-sp-form-grid">
              <label>Calculate data by sensor:</label>
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" id="sp-calc-fuel" checked> Enabled
              </label>

              <label>Filtering type: <span class="w-sp-help" title="Smooth out fuel sloshing in tank">?</span></label>
              <select id="sp-filtering-type" class="w-prop-input" style="max-width:200px">
                <option value="None" selected>None</option>
                <option value="Median 3">Median (3 points)</option>
                <option value="Median 5">Median (5 points)</option>
                <option value="Adaptive">Adaptive</option>
              </select>

              <label>Minimum fuel filling volume, l:</label>
              <input type="number" id="sp-min-filling" class="w-prop-input" value="5" step="0.5" style="max-width:140px">

              <label>Minimum fuel drain volume, l:</label>
              <input type="number" id="sp-min-theft" class="w-prop-input" value="10" step="0.5" style="max-width:140px">
            </div>
          </div>
        </div>

        <!-- TAB 2: CALCULATION TABLE (Wialon Screenshot 1, 2 & CSV Data) -->
        <div class="w-sp-pane" id="sp-pane-calc">
          <!-- Upper / Lower Bounds (Screenshot 1) -->
          <div style="background:#f8fafc;padding:10px 14px;border:1px solid #cbd5e1;border-radius:4px;display:flex;flex-wrap:wrap;align-items:center;gap:16px;margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:6px">
              <label style="font-weight:700">Lower bound:</label>
              <input type="number" id="sp-lower-bound" class="w-prop-input" style="width:90px" value="0" step="0.1">
            </div>
            <div style="display:flex;align-items:center;gap:6px">
              <label style="font-weight:700">Upper bound:</label>
              <input type="number" id="sp-upper-bound" class="w-prop-input" style="width:90px" value="45" step="0.1">
            </div>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px">
              <input type="checkbox" id="sp-apply-bounds" checked> Apply after calculation
            </label>
          </div>

          <!-- Toolbar for XY Pairs & CSV Import -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="display:flex;gap:6px">
              <button type="button" class="w-prop-btn primary" onclick="addXyPairRow()">+ Add Line</button>
              <button type="button" class="w-prop-btn" style="background:#0284c7;color:#fff" onclick="generateSlopesFromXyTable()" title="Calculate Piecewise Slopes a and b from XY calibration pairs">⚡ Generate</button>
              <button type="button" class="w-prop-btn" onclick="toggleCsvImportBox()">📋 Paste / Import CSV (X;Y)</button>
              <button type="button" class="w-prop-btn" onclick="exportCalibrationCsv()">💾 Export to CSV</button>
            </div>
            <div style="font-size:11.5px;color:#64748b">
              Formula: <code>Y = a·X + b</code>
            </div>
          </div>

          <!-- Inline CSV / Excel Paste Box -->
          <div id="sp-csv-paste-box" style="display:none;background:#f1f5f9;border:1px solid #0284c7;border-radius:6px;padding:10px;margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
              <strong style="color:#0284c7">📋 Paste Calibration Data from Excel or Wialon CSV:</strong>
              <div style="display:flex;gap:6px;align-items:center">
                <input type="file" id="sp-csv-file-input" accept=".csv,.txt" style="font-size:11px" onchange="handleCsvFileUpload(this)">
                <button type="button" class="w-icon-btn red" onclick="toggleCsvImportBox()">✕</button>
              </div>
            </div>
            <textarea id="sp-csv-textarea" style="width:100%;height:85px;font-family:monospace;font-size:11.5px;padding:6px;border:1px solid #cbd5e1;border-radius:4px" placeholder="Format: X;Y or X,Y (one pair per line)&#10;5;0&#10;43;5&#10;164;10&#10;279;15&#10;394;20&#10;510;25&#10;624;30&#10;736;35&#10;901;40"></textarea>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px">
              <button type="button" class="w-prop-btn primary" onclick="parseAndApplyCsvData()">Apply to Calibration Table &amp; Generate Slopes</button>
            </div>
          </div>

          <!-- Calibration Columns (Left: XY Pairs Table; Right: Slopes a,b Table) -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <!-- Left Column: XY Pairs (Matching Screenshot 1 & 2) -->
            <div style="border:1px solid #cbd5e1;border-radius:4px;overflow:hidden">
              <div style="background:#e2e8f0;padding:6px 10px;font-weight:700;font-size:11.5px;color:#1e293b;display:flex;justify-content:space-between;align-items:center">
                <span>Calibration Points (X → Y)</span>
                <span id="sp-xy-count" style="font-size:11px;color:#64748b">0 points</span>
              </div>
              <div style="max-height:220px;overflow-y:auto">
                <table class="w-sp-calib-table">
                  <thead>
                    <tr>
                      <th style="width:30px;text-align:center">#</th>
                      <th>X (Raw ADC / Param)</th>
                      <th>Y (Volume in Liters)</th>
                      <th style="width:40px;text-align:center">✕</th>
                    </tr>
                  </thead>
                  <tbody id="sp-xy-tbody">
                    <!-- Populated by JS -->
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Right Column: Piecewise Slopes (a, b) -->
            <div style="border:1px solid #cbd5e1;border-radius:4px;overflow:hidden">
              <div style="background:#e2e8f0;padding:6px 10px;font-weight:700;font-size:11.5px;color:#1e293b;display:flex;justify-content:space-between;align-items:center">
                <span>Calculated Slopes (X, a, b)</span>
                <span id="sp-slopes-count" style="font-size:11px;color:#64748b">0 rows</span>
              </div>
              <div style="max-height:220px;overflow-y:auto">
                <table class="w-sp-calib-table">
                  <thead>
                    <tr>
                      <th style="width:30px;text-align:center">#</th>
                      <th>X (Interval)</th>
                      <th>a (Slope)</th>
                      <th>b (Offset)</th>
                      <th style="width:40px;text-align:center">✕</th>
                    </tr>
                  </thead>
                  <tbody id="sp-slopes-tbody">
                    <!-- Populated by JS -->
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Real-time Live Calculation Tester -->
          <div style="margin-top:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 14px;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:10px">
              <span style="font-weight:700;color:#166534">🧪 Test Calibration Calculation:</span>
              <span>Raw X:</span>
              <input type="number" id="sp-test-x" class="w-prop-input" style="width:100px" placeholder="e.g. 510" oninput="testCalculateSensorValue()">
            </div>
            <div>
              <span style="font-size:12px;color:#334155">Calculated Result: </span>
              <strong id="sp-test-result" style="font-size:15px;color:#16a34a">—</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer Buttons (Cancel / OK) -->
    <div class="w-sp-footer">
      <button type="button" class="w-prop-btn" onclick="closeSensorPropertiesModal()">Cancel</button>
      <button type="button" class="w-prop-btn primary" onclick="saveSensorPropertiesModal()">OK</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const CAN_CREATE_UNIT = <?= can('units', 'create') ? 'true' : 'false' ?>;
  const CAN_EDIT_UNIT   = <?= can('units', 'edit') ? 'true' : 'false' ?>;
  const CAN_DELETE_UNIT = <?= can('units', 'delete') ? 'true' : 'false' ?>;

  const mapElement = document.getElementById('map');
  const defaultCenter = <?= json_encode($CONFIG['map_center']) ?>;
  const defaultZoom = <?= (int)$CONFIG['map_zoom'] ?>;

  const map = StMap.create(mapElement, defaultCenter, defaultZoom);

  let unitsData = [];
  let geofencesData = [];
  const unitMarkers = {};
  let showGeofences = false; // OFF by default so Monitoring opens instantly!
  let geofencesLoaded = false;
  const geofencesGroup = L.featureGroup();
  map.addLayer(geofencesGroup);

  // Toggle geofences on map on-demand from the map toolbar hexagon button
  window.toggleMonitoringGeofences = function(btn) {
    showGeofences = !showGeofences;
    if (btn) btn.classList.toggle('active-tool', showGeofences);

    if (showGeofences) {
      if (!geofencesLoaded) {
        if (btn) btn.style.opacity = '0.5';
        fetch('geofences.php?ajax=group_coords')
          .then(r => r.json())
          .then(data => {
            if (btn) btn.style.opacity = '1';
            geofencesData = data || [];
            geofencesLoaded = true;
            renderGeofences(geofencesData);
          })
          .catch(() => { if (btn) btn.style.opacity = '1'; });
      } else {
        renderGeofences(geofencesData);
      }
    } else {
      geofencesGroup.clearLayers();
    }
  };

  // Icons mapping for unit types
  const typeIcons = {
    field_staff: '👨‍🌾',
    supervisor: '👷‍♂️',
    manager: '👔',
    security: '🛡️',
    staff: '👨‍🌾',
    tractor: '🚜',
    harvester: '🌾',
    infielder: '🚛',
    vehicle: '🚙',
    bolero: '🚙',
    truck: '🚚',
    lorry: '🚚',
    excavator: '🏗️',
    motorcycle: '🏍️',
    bike: '🏍️',
    bicycle: '🚲',
    phone: '📱',
    other: '⚙️',
    server: '🖥️',
    server_temp: '🌡️'
  };

  function toDMM(lat, lng) {
    const format = (deg, isLat) => {
      const dir = isLat ? (deg >= 0 ? 'N' : 'S') : (deg >= 0 ? 'E' : 'W');
      deg = Math.abs(deg);
      const d = Math.floor(deg);
      const m = ((deg - d) * 60).toFixed(4);
      const dStr = isLat ? String(d).padStart(2, '0') : String(d).padStart(3, '0');
      return `${dir} ${dStr}° ${String(m).padStart(7, '0')}'`;
    };
    return `${format(lat, true)} &nbsp; ${format(lng, false)}`;
  }

  // Update Coordinate HUD on mouse movement over map
  map.on('mousemove', (e) => {
    document.getElementById('w-coord-hud').innerHTML = toDMM(e.latlng.lat, e.latlng.lng);
  });

  // Render Geofences on Map safely with high-contrast labels
  function renderGeofences(geofences) {
    geofencesGroup.clearLayers();
    if (!showGeofences || !geofences || !geofences.length) return;

    geofences.forEach(gf => {
      try {
        if (!gf.coords || gf.coords.length < 3) return;
        
        const poly = L.polygon(gf.coords, {
          color: '#ffffff',
          fillColor: gf.color || '#10b981',
          fillOpacity: 0.45,
          weight: 1.5
        });

        // Centered label (e.g. D/207/01)
        poly.bindTooltip(gf.name, {
          direction: 'center',
          className: 'sector-label'
        });

        poly.bindPopup(`<strong>Geofence: ${gf.name}</strong><br>Zone: <strong>${gf.group_name || 'N/A'}</strong><br>${gf.description || ''}`);
        geofencesGroup.addLayer(poly);
      } catch (err) {}
    });
  }

  let currentSort = 'online_desc'; // Default: Online units at the top!
  let groupViewActive = false;
  let selectedUnitIds = new Set();
  let initializedUnitSelection = false;
  let hoverCardTimeout = null;
  let currentHoverUnit = null;
  let currentSelectedUnitId = null;

  // Render Unit list in left panel
  function renderUnitsTable() {
    const q = document.getElementById('unit-search').value.toLowerCase().trim();

    let filtered = unitsData.filter(u => {
      const matchText = (u.name + ' ' + (u.code || '') + ' ' + (u.unique_id || '')).toLowerCase().includes(q);
      return matchText;
    });

    // Initialize all units selected on first load
    if (!initializedUnitSelection && unitsData.length > 0) {
      unitsData.forEach(u => selectedUnitIds.add(parseInt(u.id)));
      initializedUnitSelection = true;
    }

    // Apply Sorting
    if (currentSort === 'online_desc') {
      filtered.sort((a, b) => (b.is_online ? 1 : 0) - (a.is_online ? 1 : 0) || a.name.localeCompare(b.name));
    } else if (currentSort === 'online_asc') {
      filtered.sort((a, b) => (a.is_online ? 1 : 0) - (b.is_online ? 1 : 0) || a.name.localeCompare(b.name));
    } else if (currentSort === 'motion_desc') {
      filtered.sort((a, b) => (parseFloat(b.speed || 0)) - (parseFloat(a.speed || 0)));
    } else if (currentSort === 'name_asc') {
      filtered.sort((a, b) => a.name.localeCompare(b.name));
    } else if (currentSort === 'name_desc') {
      filtered.sort((a, b) => b.name.localeCompare(a.name));
    } else if (currentSort === 'battery_desc') {
      filtered.sort((a, b) => (parseInt(b.battery || 0)) - (parseInt(a.battery || 0)));
    }

    document.getElementById('units-summary-count').textContent = `${filtered.length} of ${unitsData.length} units (${unitsData.filter(u => u.is_online).length} online)`;

    const tbody = document.getElementById('units-tbody');
    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8">No matching units found</td></tr>`;
      return;
    }

    // Helper to render unit row
    function renderUnitRow(u) {
      let props = {};
      try { props = JSON.parse(u.properties_json || '{}'); } catch(e) {}
      const hasTemp = (props.temperature !== undefined);
      const icon = (u.icon_key === 'server_temp' || hasTemp) ? '🌡️' : (typeIcons[u.unit_type] || '🚜');
      const isOnline = !!u.is_online;
      const isMoving = isOnline && (u.motion_state === 'moving' || parseFloat(u.speed || 0) > 0);
      const isChecked = selectedUnitIds.has(parseInt(u.id));
      
      // Motion column
      let motionHtml = '';
      if (hasTemp) {
        const tVal = parseFloat(props.temperature);
        motionHtml = `<span class="w-motion-badge" style="background:${tVal > 28 ? '#fee2e2;color:#b91c1c' : '#ecfdf5;color:#047857'};font-weight:800;font-size:10.5px" title="Server Room Temperature">🌡️ ${tVal.toFixed(1)}°C</span>`;
      } else if (!isOnline) {
        motionHtml = `<span class="w-motion-badge offline" title="Offline (GPS Stopped)">● OFF</span>`;
      } else if (isMoving) {
        motionHtml = `<span class="w-motion-badge moving" title="Moving at ${Math.round(u.speed)} km/h"><span class="w-moving-arrow">➔</span> ${Math.round(u.speed)}</span>`;
      } else {
        motionHtml = `<span class="w-motion-badge stationary" title="Stationary (0 km/h)">⛔ 0</span>`;
      }

      // GPS connection icon
      const gpsHtml = `<span class="w-gps-dot ${isOnline ? 'online' : ''}" style="${isOnline ? 'background:#22c55e;box-shadow:0 0 6px #22c55e' : 'background:#94a3b8'}" title="${isOnline ? 'GPS Online (Live Signal)' : 'GPS Offline'}"></span>`;

      // Battery
      const hasBat = (u.battery !== null && u.battery !== undefined && u.battery !== '');
      const batVal = hasBat ? parseInt(u.battery, 10) : null;
      let batColor = (!isOnline || batVal === null) ? '#94a3b8' : (batVal < 20 ? '#dc2626' : (batVal < 50 ? '#f59e0b' : '#16a34a'));
      let batHtml = `<span style="font-weight:700;color:${batColor}">${batVal !== null ? (hasTemp ? batVal + '°C' : batVal + '%') : '—'}</span>`;
      if (hasTemp && props.pwr_ext) {
        batHtml = `<span style="font-weight:700;color:#0369a1;font-size:10px" title="Main UPS Power: ${props.pwr_ext}V">⚡ ${props.pwr_ext}V</span>`;
      } else if (props.fuel_liters !== undefined) {
        batHtml = `<span style="font-weight:700;color:#0369a1;font-size:10.5px" title="Diesel Fuel: ${props.fuel_liters} L">⛽ ${props.fuel_liters}L</span>`;
      }

      return `
        <tr class="w-unit-row ${isMoving ? 'is-moving' : ''}" data-id="${u.id}" 
            onmouseenter="showUnitHoverCardById(${u.id}, event)" onmouseleave="hideUnitHoverCard()"
            ${CAN_EDIT_UNIT ? `ondblclick="openUnitPropertiesModal(${u.id})"` : ''}>
          <td style="text-align:center;padding:5px 2px" onclick="event.stopPropagation()">
            <input type="checkbox" class="chk-unit" data-id="${u.id}" ${isChecked ? 'checked' : ''} onchange="toggleUnitCheckbox(${u.id}, this.checked)">
          </td>
          <td style="padding:5px 4px">
            <div style="display:flex;align-items:center;gap:5px;overflow:hidden">
              <span class="w-unit-vehicle-icon ${isMoving ? 'moving' : ''}">${icon}</span>
              <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">
                <strong style="color:#0f172a;font-size:11.5px">${u.name}</strong>
                ${hasTemp ? `<span style="font-size:10px;color:${parseFloat(props.temperature) > 28 ? '#dc2626' : '#16a34a'};font-weight:700;margin-left:2px">● ${props.temperature}°C</span>` : (isMoving ? `<span style="font-size:10px;color:#16a34a;font-weight:700;margin-left:2px">${Math.round(u.speed)} km/h</span>` : (isOnline ? `<span style="font-size:9.5px;color:#0284c7;margin-left:2px">● Online</span>` : `<span style="font-size:9.5px;color:#94a3b8;margin-left:2px">● Off</span>`))}
              </div>
            </div>
          </td>
          <td style="text-align:center;padding:5px 2px">${motionHtml}</td>
          <td style="text-align:center;padding:5px 2px">${gpsHtml}</td>
          <td style="text-align:right;font-size:11px;padding:5px 4px">${batHtml}</td>
          <td style="text-align:center;white-space:nowrap;padding:4px 2px" onclick="event.stopPropagation()">
            <button type="button" class="w-unit-act-btn solo" onclick="soloUnit(${u.id}, event)" title="Solo Focus: Show ONLY this vehicle on map">👁️</button>
            ${CAN_EDIT_UNIT ? `<button type="button" class="w-unit-act-btn prop" onclick="openUnitPropertiesModal(${u.id})" title="Unit Properties">🔧</button>` : ''}
            ${CAN_DELETE_UNIT ? `<button type="button" class="w-unit-act-btn del" onclick="deleteUnitQuick(${u.id}, '${u.name.replace(/'/g, "\\'")}', event)" title="Delete Device">🗑️</button>` : ''}
          </td>
        </tr>
      `;
    }

    if (!groupViewActive) {
      tbody.innerHTML = filtered.map(renderUnitRow).join('');
    } else {
      // Group by Category
      const groups = {
        '🌾 Sugarcane Harvesters': filtered.filter(u => u.unit_type === 'harvester' || (u.name && u.name.includes('Harvester'))),
        '🚜 Infielders & Tractors': filtered.filter(u => u.unit_type === 'infielder' || u.unit_type === 'tractor' || (u.name && u.name.includes('Infielder'))),
        '🚚 Commercial & Bowsers': filtered.filter(u => u.unit_type === 'truck' || (u.name && u.name.includes('Bowser')) || (u.code && u.code.startsWith('49-'))),
        '🖥️ Facilities & Server Room': filtered.filter(u => u.unit_type === 'other' || u.unit_type === 'server' || (u.name && u.name.toLowerCase().includes('server'))),
        '🚙 Utility, Boleros & Staff': filtered.filter(u => u.unit_type === 'vehicle' || u.unit_type === 'staff' || (u.name && (u.name.includes('Bolero') || u.name.includes('Cab') || u.name.includes('Bicycle'))))
      };

      let html = '';
      for (const [gName, gUnits] of Object.entries(groups)) {
        if (!gUnits.length) continue;
        html += `
          <tr style="background:#e2e8f0;font-weight:700;font-size:11px;color:#334155">
            <td colspan="6" style="padding:4px 8px">${gName} (${gUnits.length})</td>
          </tr>
        `;
        html += gUnits.map(renderUnitRow).join('');
      }
      tbody.innerHTML = html;
    }
  }

  // Checkbox toggle logic
  window.toggleUnitCheckbox = function(unitId, isChecked) {
    const id = parseInt(unitId);
    if (isChecked) {
      selectedUnitIds.add(id);
    } else {
      selectedUnitIds.delete(id);
    }
    updateMarkers();
    
    // Update master checkbox
    const chkMaster = document.getElementById('chk-master');
    if (chkMaster) {
      chkMaster.checked = selectedUnitIds.size === unitsData.length;
    }
  };

  // Solo Unit View: Shows ONLY this unit on the map
  window.soloUnit = function(unitId, event) {
    if (event) event.stopPropagation();
    const id = parseInt(unitId);
    const u = unitsData.find(x => parseInt(x.id) === id);
    if (!u) return;

    selectedUnitIds.clear();
    selectedUnitIds.add(id);

    // Update checkboxes in table
    document.querySelectorAll('.chk-unit').forEach(chk => {
      chk.checked = parseInt(chk.dataset.id) === id;
    });
    const chkMaster = document.getElementById('chk-master');
    if (chkMaster) chkMaster.checked = false;

    updateMarkers();

    if (u.lat && u.lng) {
      map.flyTo([parseFloat(u.lat), parseFloat(u.lng)], 16, { animate: true, duration: 1.2 });
      if (unitMarkers[id]) unitMarkers[id].openPopup();
    }
  };

  // Quick Delete Unit from table
  window.deleteUnitQuick = async function(unitId, unitName, event) {
    if (event) event.stopPropagation();
    if (!confirm(`Are you sure you want to remove unit "${unitName || unitId}" from the fleet?`)) return;
    try {
      const res = await fetch(`units.php?action=delete&id=${unitId}`, { method: 'POST' });
      if (typeof fetchFeed === 'function') fetchFeed();
    } catch (e) {
      alert('Delete failed: ' + e);
    }
  };

  const VEHICLE_SVGS = {
    tractor: `<svg width="34" height="40" viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <!-- Front Axle & Front Tires -->
      <rect x="6" y="8" width="6" height="12" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="28" y="8" width="6" height="12" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="11" y="13" width="18" height="3" fill="#64748b"/>
      <!-- Huge Knobby Rear Tires -->
      <rect x="3" y="24" width="8" height="20" rx="3" fill="#0f172a" stroke="#475569" stroke-width="2"/>
      <line x1="3" y1="28" x2="11" y2="28" stroke="#94a3b8" stroke-width="1.5"/>
      <line x1="3" y1="34" x2="11" y2="34" stroke="#94a3b8" stroke-width="1.5"/>
      <line x1="3" y1="40" x2="11" y2="40" stroke="#94a3b8" stroke-width="1.5"/>
      <rect x="29" y="24" width="8" height="20" rx="3" fill="#0f172a" stroke="#475569" stroke-width="2"/>
      <line x1="29" y1="28" x2="37" y2="28" stroke="#94a3b8" stroke-width="1.5"/>
      <line x1="29" y1="34" x2="37" y2="34" stroke="#94a3b8" stroke-width="1.5"/>
      <line x1="29" y1="40" x2="37" y2="40" stroke="#94a3b8" stroke-width="1.5"/>
      <!-- Engine Hood (Front) -->
      <path d="M14 6C14 4 16 3 20 3C24 3 26 4 26 6V22H14V6Z" fill="#dc2626" stroke="#991b1b" stroke-width="1.5"/>
      <rect x="17" y="5" width="6" height="4" rx="1" fill="#f87171"/>
      <circle cx="24" cy="14" r="2" fill="#334155" stroke="#0f172a" stroke-width="1"/>
      <!-- Cabin Roof (Center) -->
      <rect x="12" y="20" width="16" height="18" rx="3" fill="#ef4444" stroke="#b91c1c" stroke-width="1.5"/>
      <path d="M14 22H26L25 26H15L14 22Z" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
      <rect x="15" y="28" width="10" height="8" rx="1" fill="#fca5a5"/>
      <!-- Rear Hitch -->
      <rect x="18" y="42" width="4" height="4" fill="#334155"/>
    </svg>`,
    harvester: `<svg width="36" height="40" viewBox="0 0 46 50" fill="none" xmlns="http://www.w3.org/2000/svg">
      <!-- Front Cutter Bar (Header) -->
      <rect x="4" y="2" width="38" height="6" rx="2" fill="#eab308" stroke="#ca8a04" stroke-width="1.5"/>
      <line x1="10" y1="2" x2="10" y2="8" stroke="#a16207" stroke-width="1"/>
      <line x1="18" y1="2" x2="18" y2="8" stroke="#a16207" stroke-width="1"/>
      <line x1="26" y1="2" x2="26" y2="8" stroke="#a16207" stroke-width="1"/>
      <line x1="34" y1="2" x2="34" y2="8" stroke="#a16207" stroke-width="1"/>
      <rect x="16" y="8" width="14" height="8" fill="#15803d"/>
      <!-- Front Drive Wheels -->
      <rect x="5" y="14" width="7" height="18" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="34" y="14" width="7" height="18" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <!-- Cabin & Body -->
      <rect x="12" y="14" width="22" height="30" rx="3" fill="#16a34a" stroke="#14532d" stroke-width="1.5"/>
      <path d="M14 16H32L30 22H16L14 16Z" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
      <rect x="15" y="24" width="16" height="14" rx="2" fill="#ca8a04" stroke="#a16207" stroke-width="1"/>
      <!-- Rear Steering Wheels -->
      <rect x="8" y="36" width="5" height="10" rx="2" fill="#1e293b"/>
      <rect x="33" y="36" width="5" height="10" rx="2" fill="#1e293b"/>
    </svg>`,
    infielder: `<svg width="34" height="40" viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="6" y="8" width="6" height="12" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="28" y="8" width="6" height="12" rx="2" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="3" y="24" width="8" height="20" rx="3" fill="#0f172a" stroke="#475569" stroke-width="2"/>
      <rect x="29" y="24" width="8" height="20" rx="3" fill="#0f172a" stroke="#475569" stroke-width="2"/>
      <path d="M14 6C14 4 16 3 20 3C24 3 26 4 26 6V22H14V6Z" fill="#2563eb" stroke="#1d4ed8" stroke-width="1.5"/>
      <rect x="12" y="20" width="16" height="18" rx="3" fill="#3b82f6" stroke="#1d4ed8" stroke-width="1.5"/>
      <path d="M14 22H26L25 26H15L14 22Z" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
    </svg>`,
    vehicle: `<svg width="28" height="40" viewBox="0 0 36 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M8 8C8 4 12 2 18 2C24 2 28 4 28 8V40C28 44 24 46 18 46C12 46 8 44 8 40V8Z" fill="#2563eb" stroke="#1d4ed8" stroke-width="1.5"/>
      <rect x="4" y="14" width="4" height="3" rx="1" fill="#334155"/>
      <rect x="28" y="14" width="4" height="3" rx="1" fill="#334155"/>
      <path d="M11 11H25L23 18H13L11 11Z" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
      <rect x="12" y="18" width="12" height="14" rx="2" fill="#1d4ed8"/>
      <path d="M12 32H24L25 36H11L12 32Z" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
      <rect x="5" y="8" width="3" height="7" rx="1" fill="#1e293b"/>
      <rect x="28" y="8" width="3" height="7" rx="1" fill="#1e293b"/>
      <rect x="5" y="33" width="3" height="7" rx="1" fill="#1e293b"/>
      <rect x="28" y="33" width="3" height="7" rx="1" fill="#1e293b"/>
    </svg>`,
    excavator: `<svg width="32" height="40" viewBox="0 0 40 50" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="4" y="10" width="7" height="30" rx="3" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="29" y="10" width="7" height="30" rx="3" fill="#1e293b" stroke="#475569" stroke-width="1.5"/>
      <rect x="11" y="14" width="18" height="22" rx="3" fill="#f59e0b" stroke="#d97706" stroke-width="1.5"/>
      <rect x="13" y="16" width="7" height="10" rx="1" fill="#bae6fd" stroke="#0284c7" stroke-width="0.8"/>
      <rect x="12" y="31" width="16" height="4" fill="#b45309"/>
      <rect x="18" y="2" width="4" height="16" rx="1" fill="#d97706" stroke="#b45309" stroke-width="1"/>
      <circle cx="20" cy="3" r="2.5" fill="#1e293b"/>
    </svg>`,
    staff: `<svg width="26" height="34" viewBox="0 0 34 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="17" cy="14" r="8" fill="#3b82f6" stroke="#1d4ed8" stroke-width="2"/>
      <path d="M6 36C6 26 11 22 17 22C23 22 28 26 28 36" fill="#2563eb" stroke="#1d4ed8" stroke-width="2"/>
      <polygon points="17,1 21,8 13,8" fill="#00e676" stroke="#15803d" stroke-width="1"/>
    </svg>`,
    field_staff: `<svg width="26" height="34" viewBox="0 0 34 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="17" cy="14" r="8" fill="#10b981" stroke="#047857" stroke-width="2"/>
      <path d="M6 36C6 26 11 22 17 22C23 22 28 26 28 36" fill="#059669" stroke="#047857" stroke-width="2"/>
      <polygon points="17,1 21,8 13,8" fill="#00e676" stroke="#15803d" stroke-width="1"/>
    </svg>`,
    supervisor: `<svg width="26" height="34" viewBox="0 0 34 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="17" cy="14" r="8" fill="#eab308" stroke="#a16207" stroke-width="2"/>
      <path d="M6 36C6 26 11 22 17 22C23 22 28 26 28 36" fill="#ca8a04" stroke="#854d0e" stroke-width="2"/>
      <polygon points="17,1 21,8 13,8" fill="#00e676" stroke="#15803d" stroke-width="1"/>
    </svg>`,
    manager: `<svg width="26" height="34" viewBox="0 0 34 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="17" cy="14" r="8" fill="#8b5cf6" stroke="#6d28d9" stroke-width="2"/>
      <path d="M6 36C6 26 11 22 17 22C23 22 28 26 28 36" fill="#7c3aed" stroke="#5b21b6" stroke-width="2"/>
      <polygon points="17,1 21,8 13,8" fill="#00e676" stroke="#15803d" stroke-width="1"/>
    </svg>`,
    security: `<svg width="26" height="34" viewBox="0 0 34 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="17" cy="14" r="8" fill="#ef4444" stroke="#b91c1c" stroke-width="2"/>
      <path d="M6 36C6 26 11 22 17 22C23 22 28 26 28 36" fill="#dc2626" stroke="#991b1b" stroke-width="2"/>
      <polygon points="17,1 21,8 13,8" fill="#00e676" stroke="#15803d" stroke-width="1"/>
    </svg>`,
    motorcycle: `<svg width="22" height="38" viewBox="0 0 28 44" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="11" y="2" width="6" height="12" rx="2" fill="#0f172a" stroke="#475569" stroke-width="1.5"/>
      <rect x="11" y="28" width="6" height="14" rx="2" fill="#0f172a" stroke="#475569" stroke-width="1.5"/>
      <rect x="4" y="10" width="20" height="3" rx="1.5" fill="#64748b"/>
      <rect x="10" y="14" width="8" height="14" rx="2" fill="#dc2626" stroke="#991b1b" stroke-width="1"/>
      <circle cx="14" cy="20" r="3.5" fill="#f8fafc" stroke="#1e293b" stroke-width="1"/>
    </svg>`
  };

  const unitTrails = {};
  const unitTrailsPolylines = {};
  const clusterMarkers = {};
  const lastHeadings = {};

  function calculateHeading(lat1, lon1, lat2, lon2) {
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const y = Math.sin(dLon) * Math.cos(lat2 * Math.PI / 180);
    const x = Math.cos(lat1 * Math.PI / 180) * Math.sin(lat2 * Math.PI / 180) -
              Math.sin(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.cos(dLon);
    let brng = Math.atan2(y, x) * 180 / Math.PI;
    return (brng + 360) % 360;
  }

  window.applyVisibilitySettings = function(key, val) {
    if (key === 'geofences') {
      if (val && !showGeofences) window.toggleMonitoringGeofences();
      else if (!val && showGeofences) window.toggleMonitoringGeofences();
    }
    updateMarkers();
  };

  // Update Leaflet Markers (Shows only checked units, draws live trailing line & labels per visibility settings)
  function updateMarkers() {
    const vis = window.wialonVisibility || { monitoring: true, unit_trace: true, unit_name: true, unit_direction: true };

    // 1. If Monitoring is toggled OFF: hide all markers & trails
    if (!vis.monitoring) {
      for (const id in unitMarkers) {
        if (map.hasLayer(unitMarkers[id])) map.removeLayer(unitMarkers[id]);
      }
      for (const id in unitTrailsPolylines) {
        if (map.hasLayer(unitTrailsPolylines[id])) map.removeLayer(unitTrailsPolylines[id]);
      }
      for (const k in clusterMarkers) {
        if (map.hasLayer(clusterMarkers[k])) map.removeLayer(clusterMarkers[k]);
      }
      return;
    }

    // 2. Clear old cluster markers
    for (const k in clusterMarkers) {
      if (map.hasLayer(clusterMarkers[k])) map.removeLayer(clusterMarkers[k]);
    }

    const isClustering = localStorage.getItem('wialon_cluster_units') === 'true';
    const activeUnits = unitsData.filter(u => u.lat !== null && u.lng !== null && selectedUnitIds.has(parseInt(u.id)));

    activeUnits.forEach(u => {
      const id = parseInt(u.id);
      const lat = parseFloat(u.lat);
      const lng = parseFloat(u.lng);
      const isOnline = !!u.is_online;
      const spd = parseFloat(u.speed || 0);
      const isMoving = isOnline && (u.motion_state === 'moving' || spd > 0.5);
      const isSos = parseInt(u.sos_active || 0) === 1;

      // Calculate Heading from trail or movement
      const tr = unitTrails[id] || [];
      let heading = 0;
      if (tr.length >= 2) {
        const p1 = tr[tr.length - 2];
        const p2 = tr[tr.length - 1];
        heading = calculateHeading(p1[0], p1[1], p2[0], p2[1]);
      }
      if (heading !== 0) {
        lastHeadings[id] = heading;
      } else if (lastHeadings[id] !== undefined) {
        heading = lastHeadings[id];
      }

      // Live Trailing Line (Unit trace)
      // "api balan welawe idan live viwe 1 mumunt aka line 1 pennamu"
      // Only draw when unit_trace is enabled, vehicle is currently moving, and has >= 2 live points
      const trailPts = [...tr];
      if (vis.unit_trace && isMoving && trailPts.length >= 2) {
        if (!unitTrailsPolylines[id]) {
          unitTrailsPolylines[id] = L.polyline(trailPts, {
            color: '#0040dd',
            weight: 4,
            opacity: 0.9,
            lineJoin: 'round'
          }).addTo(map);
        } else {
          unitTrailsPolylines[id].setLatLngs(trailPts);
          if (!map.hasLayer(unitTrailsPolylines[id])) map.addLayer(unitTrailsPolylines[id]);
        }
      } else {
        if (unitTrailsPolylines[id] && map.hasLayer(unitTrailsPolylines[id])) {
          map.removeLayer(unitTrailsPolylines[id]);
        }
      }

      // Direction Arrow: ONLY for moving objects in the direction of movement!
      // "move vena object walata witharai green arrow 1 yana paththata yanna"
      const showDir = (vis.unit_direction !== false) && isMoving;
      const arrowHtml = `
        <div class="w-unit-direction-arrow" style="${showDir ? '' : 'display:none;'}">
          <svg width="18" height="18" viewBox="0 0 24 24" style="display:block;margin:0 auto">
            <polygon points="12,2 22,21 12,16 2,21" fill="#00e676" stroke="#15803d" stroke-width="1.5" stroke-linejoin="round"/>
          </svg>
        </div>
      `;

      // Vehicle Icon (Turns towards the direction it is traveling!)
      // "wihical1 symbel1 yana paththata harunoth lassanai"
      const vSvg = VEHICLE_SVGS[u.unit_type] || (['field_staff','supervisor','manager','security','staff','phone'].includes(u.unit_type) ? VEHICLE_SVGS.staff : VEHICLE_SVGS.tractor);
      const vehicleRotation = Math.round(heading);

      // Unit Name Label: shown ONLY if unit_name is true
      // "unit name click karoth witharai ... sahaunit name1 penna thiyenawa"
      const showName = vis.unit_name !== false;
      const nameHtml = `
        <div class="w-unit-name-label" style="${showName ? '' : 'display:none;'}">${u.name}</div>
      `;

      // Driver Name Label: shown if driver_name is true
      const showDriver = vis.driver_name === true && u.driver_name;
      const driverHtml = `
        <div class="w-unit-driver-label" style="${showDriver ? '' : 'display:none;'}">${u.driver_name || ''}</div>
      `;

      const markerHtml = `
        <div class="w-wialon-marker-wrap ${!isOnline ? 'is-offline-marker' : ''}" data-id="${u.id}">
          ${isSos ? '<div class="w-sos-marker-ring"></div>' : ''}
          <div class="w-vehicle-icon-box" style="transform: rotate(${vehicleRotation}deg); transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);">
            ${arrowHtml}
            ${vSvg}
          </div>
          ${nameHtml}
          ${driverHtml}
        </div>
      `;

      const customIcon = L.divIcon({
        className: 'w-telematics-pin',
        html: markerHtml,
        iconSize: [0, 0],
        iconAnchor: [0, 0]
      });

      let props = {};
      try { props = JSON.parse(u.properties_json || '{}'); } catch(e) {}
      const hasTemp = (props.temperature !== undefined);
      let tempBanner = '';
      if (hasTemp) {
        const tVal = parseFloat(props.temperature);
        tempBanner = `
          <div style="background:${tVal > 28 ? '#fef2f2;border:1px solid #fecaca' : '#f0fdf4;border:1px solid #bbf7d0'};border-radius:6px;padding:6px 10px;margin:8px 0;text-align:center">
            <div style="font-size:10px;color:#64748b;font-weight:700;letter-spacing:0.5px">SERVER ROOM TEMPERATURE</div>
            <div style="font-size:18px;font-weight:800;color:${tVal > 28 ? '#dc2626' : '#16a34a'}">🌡️ ${tVal.toFixed(1)} °C</div>
            ${props.pwr_ext ? `<div style="font-size:10.5px;color:#0284c7;margin-top:2px">⚡ UPS: <strong>${props.pwr_ext}V DC</strong> · Internal: <strong>${props.pwr_int || 4.1}V</strong></div>` : ''}
          </div>
        `;
      }

      const popupContent = `
        <div style="min-width:180px;font-size:12px;line-height:1.4">
          <strong style="font-size:13px;color:${isSos ? '#dc2626' : '#1565c0'}">${isSos ? '🚨 SOS EMERGENCY: ' : ''}${typeIcons[u.unit_type] || '🚜'} ${u.name}</strong>
          ${u.code ? `<span style="color:#64748b;font-size:11px">(${u.code})</span>` : ''}
          ${tempBanner}
          <hr style="margin:6px 0;border:0;border-top:1px solid #e2e8f0">
          <div>Status: <strong style="color:${isSos ? '#dc2626' : (isMoving ? '#16a34a' : (isOnline ? '#0284c7' : '#94a3b8'))}">${isSos ? '🆘 EMERGENCY SOS ACTIVE!' : (!isOnline ? 'OFFLINE' : (isMoving ? 'MOVING ➔' : 'STATIONARY ⛔'))}</strong></div>
          <div>Speed: <strong>${Math.round(u.speed || 0)} km/h</strong></div>
          ${props.fuel_liters !== undefined ? `<div>Diesel Tank: <strong style="color:#0284c7">⛽ ${props.fuel_liters} L</strong></div>` : ''}
          <div>Driver: <strong>${hasTemp ? '🏢 IT Server Room Facility' : (u.driver_name || 'Unassigned')}</strong></div>
          <div>Ignition: <strong>${(u.ignition && isOnline) ? 'ON' : 'OFF'}</strong></div>
          <div>Battery: <strong>${(u.battery !== null && u.battery !== undefined && u.battery !== '') ? (hasTemp ? u.battery + '°C' : u.battery + '%') : '—'}</strong></div>
          <div>Coordinates: <code>${lat.toFixed(5)}, ${lng.toFixed(5)}</code></div>
          <div style="margin-top:8px;display:flex;gap:6px">
            <a href="history.php?user=${u.id}" class="w-icon-btn primary" style="font-size:11px;padding:3px 8px">Track Route History</a>
            ${isSos ? `<button type="button" class="w-sos-btn resolve-btn" onclick="resolveSOSAlert(0, ${u.id})" style="font-size:10.5px;padding:3px 8px">Clear SOS</button>` : ''}
          </div>
        </div>
      `;

      if (unitMarkers[id]) {
        unitMarkers[id].setLatLng([lat, lng]);
        unitMarkers[id].setIcon(customIcon);
        unitMarkers[id].setZIndexOffset(isSos ? 2000 : (isOnline ? 1000 : 0));
        unitMarkers[id].setPopupContent(popupContent);
        if (!map.hasLayer(unitMarkers[id])) map.addLayer(unitMarkers[id]);
      } else {
        const marker = L.marker([lat, lng], { 
          icon: customIcon,
          zIndexOffset: isSos ? 2000 : (isOnline ? 1000 : 0)
        }).addTo(map);
        marker.bindPopup(popupContent);
        marker.on('mouseover', (e) => showUnitHoverCard(u, e.originalEvent));
        marker.on('mouseout', hideUnitHoverCard);
        unitMarkers[id] = marker;
      }
    });

    // Remove unselected units
    unitsData.forEach(u => {
      const id = parseInt(u.id);
      if (!selectedUnitIds.has(id)) {
        if (unitMarkers[id] && map.hasLayer(unitMarkers[id])) map.removeLayer(unitMarkers[id]);
        if (unitTrailsPolylines[id] && map.hasLayer(unitTrailsPolylines[id])) map.removeLayer(unitTrailsPolylines[id]);
      }
    });
  }

  // In-memory address cache to prevent repeated server/network requests
  window.addressCache = window.addressCache || {};
  window.resolveAddress = function(lat, lng, callback) {
    const key = lat.toFixed(4) + ',' + lng.toFixed(4);
    if (window.addressCache[key]) {
      callback(window.addressCache[key]);
      return;
    }
    fetch('index.php?geocode=1&lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        const addr = (data && data.ok && data.address) ? data.address : (lat.toFixed(5) + ', ' + lng.toFixed(5));
        window.addressCache[key] = addr;
        callback(addr);
      })
      .catch(function() {
        const fallback = 'Coordinates: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
        window.addressCache[key] = fallback;
        callback(fallback);
      });
  };

  // ==================== WIALON HOVER / INFO CARD LOGIC (SCREENSHOT 1) ====================
  function formatTimeAgoCategorized(secsRaw, minsRaw, lastSeen, isOnline) {
    if (!lastSeen) return 'No signal';
    let secs = (secsRaw !== undefined && secsRaw !== null && secsRaw !== '') ? parseInt(secsRaw, 10) : (parseInt(minsRaw || 0, 10) * 60);
    if (isNaN(secs) || secs < 0) secs = 0;

    if (secs < 60) {
      if (isOnline && secs <= 5) return 'Just now';
      return `${secs}s ago`;
    }
    if (secs < 3600) {
      const m = Math.floor(secs / 60);
      const s = secs % 60;
      return s > 0 ? `${m}m ${s}s ago` : `${m}m ago`;
    }
    if (secs < 86400) {
      const h = Math.floor(secs / 3600);
      const m = Math.floor((secs % 3600) / 60);
      return m > 0 ? `${h}h ${m}m ago` : `${h}h ago`;
    }
    if (secs < 31536000) {
      const d = Math.floor(secs / 86400);
      const h = Math.floor((secs % 86400) / 3600);
      const m = Math.floor((secs % 3600) / 60);
      if (h > 0) return `${d}d ${h}h ago`;
      if (m > 0) return `${d}d ${m}m ago`;
      return `${d}d ago`;
    }
    const y = Math.floor(secs / 31536000);
    const d = Math.floor((secs % 31536000) / 86400);
    return d > 0 ? `${y}y ${d}d ago` : `${y}y ago`;
  }

  window.showUnitHoverCardById = function(unitId, event) {
    const u = unitsData.find(x => parseInt(x.id) === parseInt(unitId));
    if (u) showUnitHoverCard(u, event);
  };

  window.showUnitHoverCard = function(u, e) {
    if (hoverCardTimeout) clearTimeout(hoverCardTimeout);
    currentHoverUnit = u;
    const card = document.getElementById('w-unit-hover-card');
    if (!card) return;

    // Dynamic positioning right next to cursor/marker
    const evt = (e && e.originalEvent) ? e.originalEvent : e;
    if (evt && evt.clientX !== undefined) {
      const cardW = 410;
      const cardH = 400;
      let x = evt.clientX + 16;
      let y = evt.clientY - 20;
      if (x + cardW > window.innerWidth - 12) {
        x = evt.clientX - cardW - 16;
      }
      if (x < 10) x = 10;
      if (y + cardH > window.innerHeight - 35) {
        y = window.innerHeight - cardH - 35;
      }
      if (y < 45) y = 45;
      card.style.left = x + 'px';
      card.style.top = y + 'px';
      card.style.position = 'fixed';
    }

    // 1. Name & Status Header
    document.getElementById('hc-name').textContent = u.name + (u.code ? ` (${u.code})` : '');
    const isOnline = !!u.is_online;
    const agoFormatted = formatTimeAgoCategorized(u.secs_ago, u.mins_ago, u.last_seen, isOnline);
    const timeText = isOnline ? `🟢 Online (${agoFormatted})` : `⚪ Offline (${agoFormatted})`;
    document.getElementById('hc-time-ago').innerHTML = timeText;
    document.getElementById('hc-datetime').textContent = u.last_seen || 'No connection yet';

    // 2. Speed & Satellites
    const spd = Math.round(u.speed || 0);
    document.getElementById('hc-speed').textContent = `${spd} km/h`;
    const satsEl = document.getElementById('hc-sats');
    if (satsEl) satsEl.textContent = isOnline ? String(12 + (parseInt(u.id) % 5)) : '0';

    // 3. Sensor values: ENGINE / DUTY & FLS / BATTERY
    const isStaffCategory = ['field_staff', 'supervisor', 'manager', 'security', 'staff', 'phone'].includes(u.unit_type) ||
                            (u.device_type && u.device_type.toLowerCase().includes('phone')) ||
                            (u.device_type && u.device_type.toLowerCase().includes('android')) ||
                            (u.role === 'staff');

    // Real dynamic battery from user object
    const batt = (u.battery !== null && u.battery !== undefined) ? parseInt(u.battery) : 0;
    const isEngineOn = isOnline && (u.ignition || spd > 0);

    const engLabelEl = document.getElementById('hc-sensor-engine-label');
    const engEl = document.getElementById('hc-sensor-engine');
    if (engLabelEl) engLabelEl.textContent = isStaffCategory ? 'DUTY:' : 'ENGINE:';
    if (engEl) {
      engEl.textContent = isEngineOn ? 'On' : (isStaffCategory ? (isOnline ? 'On Duty' : 'Off Duty') : 'Off');
      engEl.style.color = isEngineOn ? '#16a34a' : (isOnline ? '#0284c7' : '#94a3b8');
    }

    const flsLabelEl = document.getElementById('hc-sensor-fls-label');
    const flsEl = document.getElementById('hc-sensor-fls');
    if (flsLabelEl) flsLabelEl.textContent = isStaffCategory ? 'BATTERY:' : 'FLS:';
    if (flsEl) {
      if (isStaffCategory) {
        const battColor = batt <= 20 ? '#ef4444' : (batt <= 40 ? '#f59e0b' : '#16a34a');
        const battIcon = batt <= 20 ? '🪫 ' : '🔋 ';
        flsEl.innerHTML = `<span style="color:${battColor};font-weight:700">${battIcon}${batt}%</span>`;
      } else {
        flsEl.textContent = (u.fls_level !== undefined ? u.fls_level + ' L' : '---');
        flsEl.style.color = '#0284c7';
      }
    }

    // 3b. Server room temperature & power override
    let hcProps = {};
    try { hcProps = JSON.parse(u.properties_json || '{}'); } catch(e) {}
    if (hcProps.temperature !== undefined) {
      if (engLabelEl) engLabelEl.textContent = 'STATUS:';
      if (engEl) {
        engEl.textContent = 'Active Monitoring';
        engEl.style.color = '#16a34a';
      }
      if (flsLabelEl) flsLabelEl.textContent = 'TEMP:';
      if (flsEl) {
        const tVal = parseFloat(hcProps.temperature);
        const tCol = tVal > 28 ? '#dc2626' : '#16a34a';
        flsEl.innerHTML = `<span style="color:${tCol};font-weight:800">🌡️ ${tVal.toFixed(1)} °C</span>`;
      }
    } else if (hcProps.fuel_liters !== undefined) {
      if (flsLabelEl) flsLabelEl.textContent = 'FUEL:';
      if (flsEl) {
        flsEl.innerHTML = `<span style="color:#0284c7;font-weight:800">⛽ ${hcProps.fuel_liters} L</span>`;
      }
    }

    // 4. Connectivity settings (Each officer's actual phone, device type, unique id)
    const devModel = u.device_type || (isStaffCategory ? 'Android Phone' : 'Teltonika GPS');
    const devPhone = u.phone && u.phone.trim() ? u.phone.trim() : 'Not Registered';
    const devImei = u.unique_id || ((isStaffCategory ? '8661920' : '3505445') + String(u.id).padStart(8, '0'));
    document.getElementById('hc-device-type').textContent = devModel;
    document.getElementById('hc-phone').textContent = devPhone;
    document.getElementById('hc-unique-id').textContent = devImei;

    // 5. Telemetry Parameters (Using real raw hardware parameters from Wialon when available)
    const raw = hcProps.raw_params || {};
    const mileage = raw.io_16 || raw.mileage || (22279000 + (parseInt(u.id) * 350));
    const pwrExt = raw.pwr_ext ? (raw.pwr_ext + 'V') : (hcProps.pwr_ext ? (hcProps.pwr_ext + 'V') : (isOnline ? '12.4V' : '0.0V'));
    const pwrInt = raw.pwr_int ? (raw.pwr_int + 'V') : (hcProps.pwr_int ? (hcProps.pwr_int + 'V') : (batt + '%'));

    const evtIoEl = document.getElementById('hc-event-io');
    if (evtIoEl) evtIoEl.textContent = raw.event_io_id !== undefined ? raw.event_io_id : (hcProps.temperature ? 'io_25' : '0');
    const gsmEl = document.getElementById('hc-gsm');
    if (gsmEl) gsmEl.textContent = raw.gsm !== undefined ? raw.gsm : (isOnline ? '3' : '0');

    document.getElementById('hc-adc1').textContent = raw.adc1 !== undefined ? raw.adc1 : (hcProps.temperature ? (hcProps.temperature * 100) : Math.round(batt * 1.34));
    document.getElementById('hc-csq').textContent = raw.csq !== undefined ? raw.csq : (isOnline ? '28' : '0');
    document.getElementById('hc-mileage').textContent = mileage;
    document.getElementById('hc-pwr-ext').textContent = pwrExt;
    document.getElementById('hc-pwr-int').textContent = pwrInt;

    // 6. Location Address - Show immediate coordinates first, then resolve address asynchronously via cache
    const addrEl = document.getElementById('hc-address');
    if (addrEl) {
      if (u.lat && u.lng) {
        const uLat = parseFloat(u.lat);
        const uLng = parseFloat(u.lng);
        const key = uLat.toFixed(4) + ',' + uLng.toFixed(4);
        if (window.addressCache[key]) {
          addrEl.textContent = window.addressCache[key];
        } else {
          addrEl.textContent = `📍 ${uLat.toFixed(5)}, ${uLng.toFixed(5)}`;
          window.resolveAddress(uLat, uLng, function(realAddr) {
            if (currentHoverUnit && parseInt(currentHoverUnit.id) === parseInt(u.id)) {
              addrEl.textContent = realAddr;
            }
          });
        }
      } else {
        addrEl.textContent = 'No GPS position received yet';
      }
    }

    card.style.display = 'block';
  };

  window.closeOnlineNotifToast = function() {
    const el = document.getElementById('w-online-notif-toast');
    if (el) el.style.display = 'none';
  };

  window.clearAllAlarms = function() {
    const b = document.getElementById('w-online-notif-body');
    if (b) b.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:12px">All alarms cleared ✓</div>';
    setTimeout(closeOnlineNotifToast, 1200);
  };

  window.hideUnitHoverCard = function() {
    hoverCardTimeout = setTimeout(() => {
      const card = document.getElementById('w-unit-hover-card');
      if (card) card.style.display = 'none';
    }, 450);
  };

  window.centerOnUnitSector = function() {
    if (currentHoverUnit && currentHoverUnit.lat && currentHoverUnit.lng) {
      map.flyTo([parseFloat(currentHoverUnit.lat), parseFloat(currentHoverUnit.lng)], 16, { animate: true });
    }
  };

  window.openUnitInGoogleMaps = function() {
    if (currentHoverUnit && currentHoverUnit.lat && currentHoverUnit.lng) {
      window.open(`https://www.google.com/maps?q=${currentHoverUnit.lat},${currentHoverUnit.lng}`, '_blank');
    }
  };

  // ==================== WIALON TOOLBAR & SORTING (SCREENSHOTS 2 & 3) ====================
  window.sortByOnlineStatus = function() {
    currentSort = (currentSort === 'online_desc') ? 'online_asc' : 'online_desc';
    updateSortButtonsUI('btn-sort-satellite');
    renderUnitsTable();
  };

  window.sortByMotion = function() {
    currentSort = (currentSort === 'motion_desc') ? 'motion_asc' : 'motion_desc';
    updateSortButtonsUI('btn-sort-motion');
    renderUnitsTable();
  };

  window.toggleSortAZ = function() {
    currentSort = (currentSort === 'name_asc') ? 'name_desc' : 'name_asc';
    updateSortButtonsUI('btn-sort-az');
    const ind = document.getElementById('sort-name-indicator');
    if (ind) ind.textContent = currentSort === 'name_asc' ? '↑' : '↓';
    renderUnitsTable();
  };

  window.sortBySensors = function() {
    currentSort = (currentSort === 'battery_desc') ? 'battery_asc' : 'battery_desc';
    updateSortButtonsUI('btn-sort-sensor');
    renderUnitsTable();
  };

  function updateSortButtonsUI(activeId) {
    document.querySelectorAll('.w-wb-action-btn, #btn-sort-az').forEach(b => b.classList.remove('active-sort'));
    const btn = document.getElementById(activeId);
    if (btn) btn.classList.add('active-sort');
  }

  window.fitAllVisibleUnits = function() {
    const pts = unitsData
      .filter(u => u.lat && u.lng && selectedUnitIds.has(parseInt(u.id)))
      .map(u => [parseFloat(u.lat), parseFloat(u.lng)]);
    if (pts.length > 0) map.fitBounds(L.latLngBounds(pts), { padding: [50, 50] });
  };

  window.refreshFeedNow = function() {
    const btn = document.getElementById('btn-refresh');
    if (btn) btn.style.transform = 'rotate(360deg)';
    fetchFeed().then(() => {
      setTimeout(() => { if (btn) btn.style.transform = 'none'; }, 500);
    });
  };

  window.toggleGroupView = function() {
    groupViewActive = !groupViewActive;
    const btn = document.getElementById('btn-group-toggle');
    if (btn) btn.classList.toggle('active-sort', groupViewActive);
    renderUnitsTable();
  };

  window.openReportsForUnit = function() {
    const uId = currentHoverUnit ? currentHoverUnit.id : (unitsData[0] ? unitsData[0].id : 0);
    window.location.href = 'reports.php?unit_id=' + uId;
  };

  // ==================== QUICK ADD & DELETE DEVICE (WIALON STANDARD) ====================
  window.deleteUnitQuick = async function(id, name, event) {
    if (event) event.stopPropagation();
    if (!confirm(`Are you sure you want to delete unit '${name}' from fleet?`)) return;

    try {
      const res = await fetch('unit_properties.php?action=delete&id=' + id, { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        selectedUnitIds.delete(parseInt(id));
        if (unitMarkers[id]) {
          map.removeLayer(unitMarkers[id]);
          delete unitMarkers[id];
        }
        unitsData = unitsData.filter(u => parseInt(u.id) !== parseInt(id));
        renderUnitsTable();
        alert(`Unit '${name}' has been deleted from fleet.`);
      } else {
        alert(data.error || 'Failed to delete unit');
      }
    } catch (e) {
      alert('Delete error: ' + e);
    }
  };

  window.openAddUnitModal = function() {
    document.getElementById('add-unit-modal').style.display = 'flex';
  };

  window.closeAddUnitModal = function() {
    document.getElementById('add-unit-modal').style.display = 'none';
  };

  window.submitCreateUnit = async function() {
    const name = document.getElementById('new-unit-name').value.trim();
    if (!name) {
      alert('Please enter a unit name');
      return;
    }
    const payload = {
      name: name,
      code: document.getElementById('new-unit-code').value.trim(),
      unit_type: document.getElementById('new-unit-type').value,
      device_type: document.getElementById('new-unit-device').value,
      unique_id: document.getElementById('new-unit-imei').value.trim(),
      phone: document.getElementById('new-unit-phone').value.trim()
    };

    try {
      const res = await fetch('unit_properties.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (data.success) {
        closeAddUnitModal();
        document.getElementById('new-unit-name').value = '';
        document.getElementById('new-unit-code').value = '';
        document.getElementById('new-unit-imei').value = '';
        document.getElementById('new-unit-phone').value = '';
        await fetchFeed();
        alert(`Unit '${name}' registered successfully!`);
      } else {
        alert(data.error || 'Failed to register unit');
      }
    } catch (e) {
      alert('Error registering unit: ' + e);
    }
  };


  // Web Audio API Emergency Siren System
  let audioCtx = null;
  let sirenOsc = null;
  let sirenGain = null;
  let sirenInterval = null;
  let isSirenPlaying = false;
  let sirenSilencedByUser = false;

  function initAudio() {
    if (!audioCtx) {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (AudioContext) audioCtx = new AudioContext();
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
  }
  document.addEventListener('click', initAudio, { once: true });
  document.addEventListener('keydown', initAudio, { once: true });

  function startEmergencySiren() {
    if (isSirenPlaying || sirenSilencedByUser) return;
    initAudio();
    if (!audioCtx) return;

    try {
      sirenOsc = audioCtx.createOscillator();
      sirenGain = audioCtx.createGain();
      sirenOsc.type = 'sawtooth';
      sirenOsc.frequency.setValueAtTime(800, audioCtx.currentTime);
      sirenGain.gain.setValueAtTime(0.2, audioCtx.currentTime);
      sirenOsc.connect(sirenGain);
      sirenGain.connect(audioCtx.destination);
      sirenOsc.start();
      isSirenPlaying = true;

      let high = false;
      sirenInterval = setInterval(() => {
        if (!isSirenPlaying || !audioCtx) return;
        high = !high;
        sirenOsc.frequency.setTargetAtTime(high ? 1150 : 750, audioCtx.currentTime, 0.08);
      }, 350);
    } catch (e) {
      console.warn('Audio siren autoplay blocked until user gesture:', e);
    }
  }

  function stopEmergencySiren() {
    if (!isSirenPlaying) return;
    try {
      if (sirenInterval) clearInterval(sirenInterval);
      if (sirenOsc) {
        sirenOsc.stop();
        sirenOsc.disconnect();
      }
      if (sirenGain) sirenGain.disconnect();
    } catch (e) {}
    isSirenPlaying = false;
  }

  window.silenceSOS = function() {
    sirenSilencedByUser = true;
    stopEmergencySiren();
    const btn = document.querySelector('.silence-btn');
    if (btn) btn.textContent = '🔇 Silenced';
  };

  window.resolveSOSAlert = async function(id, userId, all = true) {
    if (!confirm('Resolve and clear this SOS emergency alert?')) return;
    try {
      await fetch(`../api.php?action=resolve_sos&id=${id}&user_id=${userId}${all ? '&all=1' : ''}`);
      sirenSilencedByUser = true;
      stopEmergencySiren();
      const sosContainer = document.getElementById('sos-container');
      if (sosContainer) {
        sosContainer.style.display = 'none';
        sosContainer.innerHTML = '';
      }
      setTimeout(fetchFeed, 300);
    } catch (e) {
      alert('Failed to resolve SOS: ' + e);
    }
  };

  window.focusOnSOS = function(lat, lng, userId) {
    initAudio();
    map.flyTo([lat, lng], 17, { animate: true, duration: 1.0 });
    if (unitMarkers[userId]) {
      unitMarkers[userId].openPopup();
    }
  };

  // Fetch live data from backend feed
  async function fetchFeed() {
    try {
      const res = await fetch('index.php?feed=1');
      const data = await res.json();
      unitsData = data.units || [];
      geofencesData = data.geofences || [];

      // Live Trailing Line (Unit Trace): Accumulate ONLY during active live monitoring while watching
      unitsData.forEach(u => {
        if (!u.lat || !u.lng) return;
        const id = parseInt(u.id);
        const lat = parseFloat(u.lat);
        const lng = parseFloat(u.lng);
        const isOnline = !!u.is_online;
        const spd = parseFloat(u.speed || 0);
        const isMoving = isOnline && (u.motion_state === 'moving' || spd > 1.0);

        if (!unitTrails[id]) {
          // Initialize clean with current live position only (no lines drawn yet)
          unitTrails[id] = [[lat, lng]];
          return;
        }

        const last = unitTrails[id][unitTrails[id].length - 1];
        if (last) {
          const dLat = (lat - last[0]) * 111320;
          const dLng = (lng - last[1]) * 111320 * Math.cos(lat * Math.PI / 180);
          const distM = Math.sqrt(dLat * dLat + dLng * dLng);

          // Only append if vehicle is moving and moved >= 10 meters (prevents stationary jitter spiderwebs)
          if (isMoving && distM >= 10.0 && distM < 1500.0) {
            unitTrails[id].push([lat, lng]);
            if (unitTrails[id].length > 25) unitTrails[id].shift();
          } else if (distM >= 1500.0) {
            // Large jump / teleportation: reset trail to prevent crazy lines across the map
            unitTrails[id] = [[lat, lng]];
          } else if (!isMoving && unitTrails[id].length > 1) {
            // Once stationary for a while, clear trailing line so old trail doesn't linger permanently
            unitTrails[id] = [[lat, lng]];
          }
        }
      });

      renderUnitsTable();
      updateMarkers();
      renderGeofences(geofencesData);

      // Handle SOS Emergency Alarms automatically
      const activeSos = data.sos || [];
      const sosContainer = document.getElementById('sos-container');
      if (activeSos.length > 0) {
        if (!sirenSilencedByUser) {
          startEmergencySiren();
        }
        const top = activeSos[0];
        if (sosContainer) {
          sosContainer.innerHTML = `
            <div class="w-sos-banner">
              <span class="w-sos-pulse-icon">🚨</span>
              <div style="flex:1">
                <strong style="font-size:13.5px;letter-spacing:0.5px">EMERGENCY SOS ALERT ACTIVATED!</strong>
                <div style="font-size:11.5px;opacity:0.95;margin-top:2px">
                  Unit: <strong>${top.name}</strong> ${top.phone ? `(${top.phone})` : ''} &bull; Time: <strong>${top.created_at || 'Just now'}</strong> &bull; Lat: ${parseFloat(top.lat).toFixed(5)}, Lng: ${parseFloat(top.lng).toFixed(5)}
                </div>
              </div>
              <div style="display:flex;gap:6px;align-items:center">
                <button type="button" class="w-sos-btn focus-btn" onclick="focusOnSOS(${top.lat}, ${top.lng}, ${top.user_id})">🎯 Locate Unit</button>
                <button type="button" class="w-sos-btn silence-btn" onclick="silenceSOS()">${sirenSilencedByUser ? '🔇 Silenced' : '🔕 Silence Siren'}</button>
                <button type="button" class="w-sos-btn resolve-btn" onclick="resolveSOSAlert(${top.id}, ${top.user_id}, true)">✓ Resolve Alert</button>
              </div>
            </div>
          `;
          sosContainer.style.display = 'block';
        }
      } else {
        stopEmergencySiren();
        sirenSilencedByUser = false;
        if (sosContainer) {
          sosContainer.style.display = 'none';
          sosContainer.innerHTML = '';
        }
      }

      // Real-time update of bottom status bar online counter
      const onlineCount = unitsData.filter(u => u.is_online).length;
      const countEl = document.getElementById('w-online-units-count');
      const dotEl = document.getElementById('w-online-dot');
      if (countEl) {
        countEl.textContent = `${onlineCount} ${onlineCount === 1 ? 'Unit' : 'Units'} Online`;
      }
      if (dotEl) {
        dotEl.style.background = onlineCount > 0 ? '#22c55e' : '#94a3b8';
        dotEl.style.boxShadow = onlineCount > 0 ? '0 0 6px #22c55e' : 'none';
        if (countEl && countEl.parentElement) {
          countEl.parentElement.style.color = onlineCount > 0 ? '#22c55e' : '#94a3b8';
        }
      }
    } catch (e) {
      console.error('Telematics feed error:', e);
    }
  }

  // Initial load & 10s auto-refresh
  fetchFeed().then(() => {
    // Handle URL parameters (focus & open_prop)
    const urlParams = new URLSearchParams(window.location.search);
    const openPropId = urlParams.get('open_prop');
    if (openPropId) {
      setTimeout(() => {
        if (typeof window.openUnitPropertiesModal === 'function') {
          window.openUnitPropertiesModal(openPropId);
        }
      }, 400);
    }
    const focusId = urlParams.get('focus') || urlParams.get('unit') || urlParams.get('user');
    if (focusId) {
      setTimeout(() => {
        const id = parseInt(focusId);
        const u = unitsData.find(x => parseInt(x.id) === id);
        if (u) {
          if (typeof soloUnit === 'function') {
            soloUnit(id);
          }
          const row = document.querySelector(`.w-unit-row[data-id="${id}"]`);
          if (row) {
            row.style.background = '#e0f2fe';
            row.style.borderLeft = '4px solid #0284c7';
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          if (u.lat && u.lng) {
            map.flyTo([parseFloat(u.lat), parseFloat(u.lng)], 16, { animate: true, duration: 1.2 });
            if (unitMarkers[id]) unitMarkers[id].openPopup();
          } else {
            const toast = document.createElement('div');
            toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:#f8fafc;padding:10px 18px;border-radius:8px;font-size:12.5px;font-weight:700;box-shadow:0 10px 25px rgba(0,0,0,0.3);z-index:99999;border:1px solid #38bdf8';
            toast.innerHTML = '🎯 Selected in Monitoring Panel: <strong>' + (u.name || 'Staff') + '</strong>';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
          }
        }
      }, 700);
    }
  });
  setInterval(fetchFeed, 4000);

  // Click on unit row -> focus on map
  const tbodyEl = document.getElementById('units-tbody');
  if (tbodyEl) {
    tbodyEl.addEventListener('click', (e) => {
      const row = e.target.closest('.w-unit-row');
      if (!row) return;
      const id = row.dataset.id;
      const u = unitsData.find(x => x.id == id);
      if (u && u.lat && u.lng) {
        map.flyTo([parseFloat(u.lat), parseFloat(u.lng)], 16, { animate: true, duration: 1.2 });
        if (unitMarkers[id]) unitMarkers[id].openPopup();
      }
    });

    // Row click -> focus on map
    tbodyEl.addEventListener('click', (e) => {
      const row = e.target.closest('.w-unit-row');
      if (!row || e.target.closest('button') || e.target.closest('input')) return;
      const id = parseInt(row.dataset.id);
      const u = unitsData.find(x => parseInt(x.id) === id);
      if (u && u.lat && u.lng) {
        map.flyTo([parseFloat(u.lat), parseFloat(u.lng)], 16, { animate: true, duration: 1.2 });
        if (unitMarkers[id]) unitMarkers[id].openPopup();
      }
    });
  }

  // Search & filter handlers
  const uSearch = document.getElementById('unit-search');
  if (uSearch) uSearch.addEventListener('input', renderUnitsTable);

  const clearBtn = document.getElementById('btn-clear-search');
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (uSearch) uSearch.value = '';
      renderUnitsTable();
    });
  }

  // Toggle geofences tool
  const toolGf = document.getElementById('tool-geofences');
  if (toolGf) {
    toolGf.addEventListener('click', function() {
      showGeofences = !showGeofences;
      this.classList.toggle('active', showGeofences);
      renderGeofences(geofencesData);
    });
  }

  // Fit all units
  const toolFit = document.getElementById('tool-fit');
  if (toolFit) {
    toolFit.addEventListener('click', () => {
      fitAllVisibleUnits();
    });
  }

  // Master checkbox toggle (Synchronized with selectedUnitIds)
  const chkMaster = document.getElementById('chk-master');
  if (chkMaster) {
    chkMaster.addEventListener('change', (e) => {
      const checked = e.target.checked;
      if (checked) {
        unitsData.forEach(u => selectedUnitIds.add(parseInt(u.id)));
      } else {
        selectedUnitIds.clear();
      }
      document.querySelectorAll('.chk-unit').forEach(chk => {
        chk.checked = checked;
      });
      updateMarkers();
    });
  }

  // ==================== WIALON UNIT PROPERTIES SCRIPT LOGIC ====================
  const ICON_LIBRARY = [
    { key: 'tractor_blue', emoji: '🚜', name: 'Tractor (Blue Agriculture)' },
    { key: 'tractor_green', emoji: '🚜', name: 'Tractor (Green Estate)' },
    { key: 'tractor_red', emoji: '🚜', name: 'Tractor (Red Hauler)' },
    { key: 'harvester_green', emoji: '🌾', name: 'Cane Harvester (Combine)' },
    { key: 'harvester_yellow', emoji: '🌾', name: 'Sugar Harvester (Heavy)' },
    { key: 'infielder_orange', emoji: '🚜', name: 'Infielder / Cane Loader' },
    { key: 'truck_tipper', emoji: '🚚', name: 'Tipper Truck (Cane Hauler)' },
    { key: 'truck_heavy', emoji: '🚛', name: '10-Wheeler Sugar Hauler' },
    { key: 'truck_tanker', emoji: '🛢️', name: 'Fuel Bowser / Tanker' },
    { key: 'vehicle_bolero', emoji: '🚙', name: 'Mahindra Bolero 4x4' },
    { key: 'vehicle_pickup', emoji: '🛻', name: 'Field Pickup Double Cab' },
    { key: 'vehicle_van', emoji: '🚐', name: 'Staff Crew Van' },
    { key: 'excavator_yellow', emoji: '🏗️', name: 'Excavator (XE135B / Tracked)' },
    { key: 'backhoe_loader', emoji: '🚜', name: 'JCB Backhoe Loader' },
    { key: 'motorbike_field', emoji: '🏍️', name: 'Field Scout Motorbike' },
    { key: 'staff_person', emoji: '👤', name: 'Field Officer / Scout' }
  ];

  let currentUnitProp = null;

  // Initialize icon library tiles
  const gridEl = document.getElementById('prop-icon-grid');
  if (gridEl) {
    gridEl.innerHTML = ICON_LIBRARY.map(item => `
      <div class="w-icon-opt-tile" data-key="${item.key}" onclick="selectUnitIcon('${item.key}')" title="${item.name}">
        <span>${item.emoji}</span>
        <span style="font-size:9px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:50px">${item.name.split(' ')[0]}</span>
      </div>
    `).join('');
  }

  window.openUnitPropertiesModal = function(unitId) {
    const modal = document.getElementById('unit-properties-modal');
    if (!modal) return;
    modal.style.display = 'flex';
    
    // Default to General tab
    switchPropTab('general');

    fetch('unit_properties.php?action=get&id=' + unitId)
      .then(r => r.json())
      .then(data => {
        if (!data.success || !data.unit) {
          alert('Could not load unit properties');
          closeUnitPropertiesModal();
          return;
        }
        currentUnitProp = data.unit;
        populateUnitProperties(currentUnitProp);
      })
      .catch(err => {
        console.error(err);
        alert('Error loading unit properties');
      });
  };

  window.closeUnitPropertiesModal = function() {
    const modal = document.getElementById('unit-properties-modal');
    if (modal) modal.style.display = 'none';
  };

  window.switchPropTab = function(tabId) {
    document.querySelectorAll('.w-prop-tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    document.querySelectorAll('.w-prop-pane').forEach(pane => {
      pane.classList.toggle('active', pane.id === 'pane-' + tabId);
    });
  };

  function populateUnitProperties(u) {
    document.getElementById('prop-unit-name-title').textContent = u.name;
    document.getElementById('prop-unit-id').value = u.id;
    document.getElementById('prop-name').value = u.name || '';
    document.getElementById('prop-unit-type').value = u.unit_type || 'tractor';
    document.getElementById('prop-device-type').value = u.device_type || 'Teltonika FMB125';
    document.getElementById('prop-server-addr').value = u.server_address || 'nl.gpsgsm.org:21448';
    document.getElementById('prop-unique-id').value = u.unique_id || '';
    document.getElementById('prop-phone').value = u.phone || '';
    document.getElementById('prop-mileage-val').value = u.mileage_val || 0;
    document.getElementById('prop-hours-val').value = u.engine_hours_val || 0;
    document.getElementById('prop-traffic-val').value = u.gprs_traffic_val || 0;
    document.getElementById('prop-consumption-rate').value = u.consumption_rate || 10;
    document.getElementById('prop-speed-limit').value = u.speed_limit || 60;

    // Icon select
    selectUnitIcon(u.icon_key || 'tractor_blue');

    // Sensors table
    renderSensorsTable(u.sensors || []);

    // Custom fields
    renderCustomFields(u.properties?.custom_fields || {});

    // Service intervals
    renderServiceIntervals(u.properties?.service_intervals || []);
  }

  window.selectUnitIcon = function(iconKey) {
    const item = ICON_LIBRARY.find(i => i.key === iconKey) || ICON_LIBRARY[0];
    document.getElementById('prop-icon-key').value = item.key;
    document.getElementById('prop-icon-big-preview').textContent = item.emoji;
    document.getElementById('prop-icon-label').textContent = item.name;

    document.querySelectorAll('.w-icon-opt-tile').forEach(tile => {
      tile.classList.toggle('selected', tile.dataset.key === item.key);
    });
  };

  let editingSensorIdx = null;

  function renderSensorsTable(sensors) {
    const tbody = document.getElementById('sensors-tbody');
    if (!tbody) return;
    tbody.innerHTML = sensors.map((s, idx) => `
      <tr data-sensor-idx="${idx}">
        <td style="color:#94a3b8;cursor:move">↕</td>
        <td><strong>${s.name}</strong></td>
        <td>${s.type}</td>
        <td>${s.unit}</td>
        <td><code>${s.param}</code></td>
        <td style="color:#64748b;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${s.desc || ''}">${s.desc || ''}</td>
        <td style="text-align:center">
          <input type="checkbox" ${s.visible ? 'checked' : ''} onchange="toggleSensorVisible(${idx}, this.checked)">
        </td>
        <td style="text-align:center;white-space:nowrap">
          <button type="button" class="w-icon-btn" onclick="openSensorPropertiesModal(${idx})" title="Edit sensor properties & calibration table">🔧</button>
          <button type="button" class="w-icon-btn red" onclick="deleteSensor(${idx})" title="Delete sensor">✕</button>
        </td>
      </tr>
    `).join('');
  }

  function renderCustomFields(fields) {
    const container = document.getElementById('custom-fields-container');
    if (!container) return;
    container.innerHTML = Object.entries(fields).map(([k, v]) => `
      <label>${k}:</label>
      <input type="text" class="w-prop-input custom-field-val" data-key="${k}" value="${v}">
    `).join('');
  }

  function renderServiceIntervals(intervals) {
    const tbody = document.getElementById('service-intervals-tbody');
    if (!tbody) return;
    tbody.innerHTML = intervals.map(item => `
      <tr>
        <td><strong>${item.name}</strong></td>
        <td>${item.interval}</td>
        <td>${item.last}</td>
        <td style="color:${item.status === 'warning' ? '#d97706' : '#16a34a'}"><strong>${item.remaining}</strong></td>
        <td><span class="badge ${item.status === 'warning' ? 'yellow' : 'green'}">${item.status === 'warning' ? 'Due Soon' : 'OK'}</span></td>
      </tr>
    `).join('');
  }

  window.setAccessPreset = function(preset) {
    const chks = document.querySelectorAll('#pane-access input[type="checkbox"]');
    if (preset === 'none') {
      chks.forEach(c => c.checked = false);
    } else if (preset === 'full') {
      chks.forEach(c => c.checked = true);
    } else if (preset === 'read') {
      chks.forEach((c, idx) => {
        c.checked = (idx === 0 || idx === 1 || idx === 5 || idx === 7);
      });
    }
  };

  window.openSensorPropertiesModal = function(idx) {
    editingSensorIdx = (idx !== null && idx !== undefined) ? idx : null;
    const modal = document.getElementById('sensor-properties-modal');
    if (!modal) return;

    if (editingSensorIdx !== null && currentUnitProp && currentUnitProp.sensors && currentUnitProp.sensors[editingSensorIdx]) {
      const s = currentUnitProp.sensors[editingSensorIdx];
      document.getElementById('sp-modal-title').textContent = 'Sensor properties — ' + (s.name || 'Sensor');
      document.getElementById('sp-name').value = s.name || '';
      document.getElementById('sp-type').value = s.type || 'Custom digital sensor';
      document.getElementById('sp-desc').value = s.desc || '';
      document.getElementById('sp-param').value = s.param || '';
      document.getElementById('sp-unit').value = s.unit || '';
      document.getElementById('sp-last-msg').checked = !!s.last_msg;
      document.getElementById('sp-validator').value = s.validator || 'None';
      
      document.getElementById('sp-lower-bound').value = (s.lower_bound !== undefined && s.lower_bound !== null) ? s.lower_bound : 0;
      document.getElementById('sp-upper-bound').value = (s.upper_bound !== undefined && s.upper_bound !== null) ? s.upper_bound : 45;
      document.getElementById('sp-apply-bounds').checked = (s.apply_bounds !== false);
      document.getElementById('sp-min-filling').value = (s.min_filling !== undefined && s.min_filling !== null) ? s.min_filling : 5;
      document.getElementById('sp-min-theft').value = (s.min_theft !== undefined && s.min_theft !== null) ? s.min_theft : 10;

      // Populate XY table
      const xyTbody = document.getElementById('sp-xy-tbody');
      xyTbody.innerHTML = '';
      
      let xyList = [];
      if (Array.isArray(s.xy_pairs) && s.xy_pairs.length > 0) {
        xyList = s.xy_pairs;
      } else if (s.desc && s.desc.includes('|')) {
        // Parse Wialon description format: |x1:y1:x2:y2...
        const rawPart = s.desc.split('|')[1] || '';
        const tokens = rawPart.split(':');
        for (let i = 0; i < tokens.length - 1; i += 2) {
          const xVal = parseFloat(tokens[i]);
          const yVal = parseFloat(tokens[i + 1]);
          if (!isNaN(xVal) && !isNaN(yVal)) {
            xyList.push({ x: xVal, y: yVal });
          }
        }
      } else if (Array.isArray(s.table) && s.table.length > 0) {
        // Reconstruct from slopes
        s.table.forEach(seg => {
          const xVal = seg.x;
          const yVal = Math.round((seg.a * seg.x + seg.b) * 10) / 10;
          xyList.push({ x: xVal, y: yVal });
        });
      }

      xyList.forEach(pt => addXyPairRow(pt.x, pt.y));

      // Populate Slopes table
      const slopesTbody = document.getElementById('sp-slopes-tbody');
      slopesTbody.innerHTML = '';
      if (Array.isArray(s.table) && s.table.length > 0) {
        s.table.forEach(seg => renderSlopeRow(seg.x, seg.a, seg.b));
        updateSlopesCount();
      } else if (xyList.length >= 2) {
        generateSlopesFromXyTable(false);
      }
    } else {
      // New Sensor defaults
      document.getElementById('sp-modal-title').textContent = 'New sensor';
      document.getElementById('sp-name').value = 'New sensor';
      document.getElementById('sp-type').value = 'Fuel level sensor';
      document.getElementById('sp-desc').value = '';
      document.getElementById('sp-param').value = '';
      document.getElementById('sp-unit').value = 'l';
      document.getElementById('sp-last-msg').checked = false;
      document.getElementById('sp-lower-bound').value = 0;
      document.getElementById('sp-upper-bound').value = 45;
      document.getElementById('sp-apply-bounds').checked = true;
      document.getElementById('sp-min-filling').value = 5;
      document.getElementById('sp-min-theft').value = 10;
      document.getElementById('sp-xy-tbody').innerHTML = '';
      document.getElementById('sp-slopes-tbody').innerHTML = '';
      document.getElementById('sp-xy-count').textContent = '0 points';
      document.getElementById('sp-slopes-count').textContent = '0 rows';
    }

    onSensorTypeChanged();
    switchSensorPropTab('general');
    modal.style.display = 'flex';
    testCalculateSensorValue();
  };

  window.closeSensorPropertiesModal = function() {
    const modal = document.getElementById('sensor-properties-modal');
    if (modal) modal.style.display = 'none';
  };

  window.switchSensorPropTab = function(tabId) {
    document.querySelectorAll('.w-sp-tab-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.tab === tabId);
    });
    document.querySelectorAll('.w-sp-pane').forEach(p => {
      p.classList.toggle('active', p.id === 'sp-pane-' + tabId);
    });
  };

  window.onSensorTypeChanged = function() {
    const type = (document.getElementById('sp-type').value || '').toLowerCase();
    const fuelSec = document.getElementById('sp-fuel-section');
    if (fuelSec) {
      fuelSec.style.display = (type.includes('fuel')) ? 'block' : 'none';
    }
    const unitInput = document.getElementById('sp-unit');
    if (!unitInput.value || unitInput.value === 'l' || unitInput.value === 'V' || unitInput.value === '°C' || unitInput.value === 'km' || unitInput.value === 'On/Off' || unitInput.value === 'Ok/Not Ok') {
      if (type.includes('fuel')) unitInput.value = 'l';
      else if (type.includes('voltage')) unitInput.value = 'V';
      else if (type.includes('temperature')) unitInput.value = '°C';
      else if (type.includes('mileage') || type.includes('odometer')) unitInput.value = 'km';
      else if (type.includes('ignition')) unitInput.value = 'On/Off';
      else if (type.includes('digital')) unitInput.value = 'Ok/Not Ok';
    }
  };

  window.addXyPairRow = function(x = '', y = '') {
    const tbody = document.getElementById('sp-xy-tbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="text-align:center;color:#64748b" class="sp-xy-idx"></td>
      <td><input type="number" class="sp-xy-x" value="${x !== '' ? x : ''}" step="any" placeholder="X" oninput="testCalculateSensorValue()"></td>
      <td><input type="number" class="sp-xy-y" value="${y !== '' ? y : ''}" step="any" placeholder="Y" oninput="testCalculateSensorValue()"></td>
      <td style="text-align:center"><button type="button" class="w-icon-btn red" onclick="deleteXyPairRow(this)" title="Delete point">✕</button></td>
    `;
    tbody.appendChild(tr);
    reindexXyTable();
  };

  window.deleteXyPairRow = function(btn) {
    const tr = btn.closest('tr');
    if (tr) {
      tr.remove();
      reindexXyTable();
      testCalculateSensorValue();
    }
  };

  function reindexXyTable() {
    const rows = document.querySelectorAll('#sp-xy-tbody tr');
    rows.forEach((r, idx) => {
      const idxCell = r.querySelector('.sp-xy-idx');
      if (idxCell) idxCell.textContent = (idx + 1);
    });
    const cnt = document.getElementById('sp-xy-count');
    if (cnt) cnt.textContent = rows.length + (rows.length === 1 ? ' point' : ' points');
  }

  function renderSlopeRow(x, a, b) {
    const tbody = document.getElementById('sp-slopes-tbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="text-align:center;color:#64748b" class="sp-slope-idx"></td>
      <td><input type="number" class="sp-slope-x" value="${x}" step="any"></td>
      <td><input type="number" class="sp-slope-a" value="${a}" step="any"></td>
      <td><input type="number" class="sp-slope-b" value="${b}" step="any"></td>
      <td style="text-align:center"><button type="button" class="w-icon-btn red" onclick="this.closest('tr').remove();updateSlopesCount();testCalculateSensorValue();">✕</button></td>
    `;
    tbody.appendChild(tr);
  }

  function updateSlopesCount() {
    const rows = document.querySelectorAll('#sp-slopes-tbody tr');
    rows.forEach((r, idx) => {
      const idxCell = r.querySelector('.sp-slope-idx');
      if (idxCell) idxCell.textContent = (idx + 1);
    });
    const cnt = document.getElementById('sp-slopes-count');
    if (cnt) cnt.textContent = rows.length + (rows.length === 1 ? ' row' : ' rows');
  }

  window.generateSlopesFromXyTable = function(notify = true) {
    const rows = document.querySelectorAll('#sp-xy-tbody tr');
    const points = [];
    rows.forEach(r => {
      const xInput = r.querySelector('.sp-xy-x');
      const yInput = r.querySelector('.sp-xy-y');
      if (xInput && yInput && xInput.value !== '' && yInput.value !== '') {
        const x = parseFloat(xInput.value);
        const y = parseFloat(yInput.value);
        if (!isNaN(x) && !isNaN(y)) {
          points.push({ x, y });
        }
      }
    });

    if (points.length < 2) {
      if (notify) alert('Please add at least 2 calibration points (X;Y).');
      return;
    }

    // Sort ascending by X
    points.sort((p1, p2) => p1.x - p2.x);

    // Re-render sorted points into XY table
    const xyTbody = document.getElementById('sp-xy-tbody');
    xyTbody.innerHTML = '';
    points.forEach(pt => addXyPairRow(pt.x, pt.y));

    // Calculate piecewise linear slopes
    const slopesTbody = document.getElementById('sp-slopes-tbody');
    slopesTbody.innerHTML = '';

    const slopes = [];
    for (let i = 0; i < points.length; i++) {
      const p1 = points[i];
      if (i < points.length - 1) {
        const p2 = points[i + 1];
        const dx = p2.x - p1.x;
        const dy = p2.y - p1.y;
        const a = (dx !== 0) ? (dy / dx) : 0;
        const b = p1.y - a * p1.x;
        const aRound = Math.round(a * 100000) / 100000;
        const bRound = Math.round(b * 100) / 100;
        slopes.push({ x: p1.x, a: aRound, b: bRound });
        renderSlopeRow(p1.x, aRound, bRound);
      } else {
        // Last boundary point
        slopes.push({ x: p1.x, a: 0, b: p1.y });
        renderSlopeRow(p1.x, 0, p1.y);
      }
    }
    updateSlopesCount();

    // Auto set upper/lower bounds
    const maxY = Math.max(...points.map(p => p.y));
    const minY = Math.min(...points.map(p => p.y));
    const ubEl = document.getElementById('sp-upper-bound');
    const lbEl = document.getElementById('sp-lower-bound');
    if (ubEl && (!ubEl.value || parseFloat(ubEl.value) <= 0 || parseFloat(ubEl.value) < maxY)) {
      ubEl.value = Math.ceil(maxY);
    }
    if (lbEl && (!lbEl.value || parseFloat(lbEl.value) < 0)) {
      lbEl.value = Math.floor(minY);
    }

    testCalculateSensorValue();
    if (notify) alert(`✓ Successfully generated ${slopes.length} slope segments from ${points.length} calibration points!`);
  };

  window.toggleCsvImportBox = function() {
    const box = document.getElementById('sp-csv-paste-box');
    if (box) box.style.display = (box.style.display === 'none') ? 'block' : 'none';
  };

  window.handleCsvFileUpload = function(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
      const ta = document.getElementById('sp-csv-textarea');
      if (ta) ta.value = e.target.result;
    };
    reader.readAsText(file);
  };

  window.parseAndApplyCsvData = function() {
    const ta = document.getElementById('sp-csv-textarea');
    if (!ta || !ta.value.trim()) {
      alert('Please paste calibration lines in format X;Y or X,Y');
      return;
    }

    const lines = ta.value.split(/\r?\n/);
    const parsedPoints = [];

    lines.forEach(l => {
      const line = l.trim();
      if (!line || line.startsWith('#')) return;
      const parts = line.split(/[;,\t\s]+/);
      if (parts.length >= 2) {
        const x = parseFloat(parts[0]);
        const y = parseFloat(parts[1]);
        if (!isNaN(x) && !isNaN(y)) {
          parsedPoints.push({ x, y });
        }
      }
    });

    if (parsedPoints.length < 2) {
      alert('Could not find at least 2 valid X;Y pairs. Example:\n5;0\n43;5\n164;10');
      return;
    }

    const xyTbody = document.getElementById('sp-xy-tbody');
    xyTbody.innerHTML = '';
    parsedPoints.forEach(pt => addXyPairRow(pt.x, pt.y));

    generateSlopesFromXyTable(false);
    toggleCsvImportBox();
    alert(`✓ Successfully loaded ${parsedPoints.length} calibration points from CSV and generated slopes table!`);
  };

  window.exportCalibrationCsv = function() {
    const rows = document.querySelectorAll('#sp-xy-tbody tr');
    const lines = [];
    rows.forEach(r => {
      const xInput = r.querySelector('.sp-xy-x');
      const yInput = r.querySelector('.sp-xy-y');
      if (xInput && yInput && xInput.value !== '' && yInput.value !== '') {
        lines.push(`${xInput.value};${yInput.value}`);
      }
    });

    if (lines.length === 0) {
      alert('No calibration points to export.');
      return;
    }

    const blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const sName = (document.getElementById('sp-name').value || 'Sensor').replace(/\s+/g, '_');
    a.download = `${sName}_calibration.csv`;
    a.click();
  };

  window.testCalculateSensorValue = function() {
    const testInput = document.getElementById('sp-test-x');
    const resEl = document.getElementById('sp-test-result');
    if (!testInput || !resEl) return;

    const rawVal = parseFloat(testInput.value);
    if (isNaN(rawVal)) {
      resEl.textContent = '—';
      return;
    }

    const slopeRows = document.querySelectorAll('#sp-slopes-tbody tr');
    const slopes = [];
    slopeRows.forEach(r => {
      const x = parseFloat(r.querySelector('.sp-slope-x')?.value);
      const a = parseFloat(r.querySelector('.sp-slope-a')?.value);
      const b = parseFloat(r.querySelector('.sp-slope-b')?.value);
      if (!isNaN(x) && !isNaN(a) && !isNaN(b)) {
        slopes.push({ x, a, b });
      }
    });

    const unit = document.getElementById('sp-unit')?.value || 'l';
    const applyBounds = document.getElementById('sp-apply-bounds')?.checked;
    const lowerB = parseFloat(document.getElementById('sp-lower-bound')?.value) || 0;
    const upperB = parseFloat(document.getElementById('sp-upper-bound')?.value) || 9999;

    let result = null;

    if (slopes.length > 0) {
      slopes.sort((s1, s2) => s1.x - s2.x);
      let matched = null;
      for (const seg of slopes) {
        if (rawVal >= seg.x) matched = seg;
        else break;
      }
      if (!matched) matched = slopes[0];
      result = matched.a * rawVal + matched.b;
    } else {
      const xyRows = document.querySelectorAll('#sp-xy-tbody tr');
      const pts = [];
      xyRows.forEach(r => {
        const x = parseFloat(r.querySelector('.sp-xy-x')?.value);
        const y = parseFloat(r.querySelector('.sp-xy-y')?.value);
        if (!isNaN(x) && !isNaN(y)) pts.push({ x, y });
      });
      if (pts.length >= 2) {
        pts.sort((p1, p2) => p1.x - p2.x);
        if (rawVal <= pts[0].x) {
          result = pts[0].y;
        } else if (rawVal >= pts[pts.length - 1].x) {
          result = pts[pts.length - 1].y;
        } else {
          for (let i = 0; i < pts.length - 1; i++) {
            if (rawVal >= pts[i].x && rawVal <= pts[i + 1].x) {
              const dx = pts[i + 1].x - pts[i].x;
              const dy = pts[i + 1].y - pts[i].y;
              result = pts[i].y + (dy / dx) * (rawVal - pts[i].x);
              break;
            }
          }
        }
      }
    }

    if (result !== null) {
      if (applyBounds) {
        if (result < lowerB) result = lowerB;
        if (result > upperB) result = upperB;
      }
      resEl.textContent = `${result.toFixed(2)} ${unit}`;
    } else {
      resEl.textContent = 'Need ≥ 2 points';
    }
  };

  window.saveSensorPropertiesModal = function() {
    const name = document.getElementById('sp-name').value.trim();
    const param = document.getElementById('sp-param').value.trim();
    if (!name) {
      alert('Please enter a sensor name.');
      switchSensorPropTab('general');
      document.getElementById('sp-name').focus();
      return;
    }
    if (!param) {
      alert('Please specify the hardware parameter (e.g. io_201).');
      switchSensorPropTab('general');
      document.getElementById('sp-param').focus();
      return;
    }

    // Gather XY pairs
    const xyPairs = [];
    document.querySelectorAll('#sp-xy-tbody tr').forEach(r => {
      const x = parseFloat(r.querySelector('.sp-xy-x')?.value);
      const y = parseFloat(r.querySelector('.sp-xy-y')?.value);
      if (!isNaN(x) && !isNaN(y)) {
        xyPairs.push({ x, y });
      }
    });

    // Gather Slopes
    let table = [];
    document.querySelectorAll('#sp-slopes-tbody tr').forEach(r => {
      const x = parseFloat(r.querySelector('.sp-slope-x')?.value);
      const a = parseFloat(r.querySelector('.sp-slope-a')?.value);
      const b = parseFloat(r.querySelector('.sp-slope-b')?.value);
      if (!isNaN(x) && !isNaN(a) && !isNaN(b)) {
        table.push({ x, a, b });
      }
    });

    // If XY pairs exist but table empty, auto-generate table
    if (table.length === 0 && xyPairs.length >= 2) {
      xyPairs.sort((p1, p2) => p1.x - p2.x);
      for (let i = 0; i < xyPairs.length; i++) {
        const p1 = xyPairs[i];
        if (i < xyPairs.length - 1) {
          const p2 = xyPairs[i + 1];
          const dx = p2.x - p1.x;
          const dy = p2.y - p1.y;
          const a = dx !== 0 ? (dy / dx) : 0;
          const b = p1.y - a * p1.x;
          table.push({ x: p1.x, a: Math.round(a * 100000) / 100000, b: Math.round(b * 100) / 100 });
        } else {
          table.push({ x: p1.x, a: 0, b: p1.y });
        }
      }
    }

    // Wialon description representation: |x1:y1:x2:y2...
    let desc = document.getElementById('sp-desc').value.trim();
    if (xyPairs.length > 0) {
      const calibStr = '|' + xyPairs.map(p => `${p.x}:${p.y}`).join(':');
      if (!desc.includes('|')) {
        desc = (desc ? desc + ' ' : '') + calibStr;
      } else {
        desc = desc.split('|')[0].trim() + (desc.split('|')[0].trim() ? ' ' : '') + calibStr;
      }
    }

    const sensorObj = {
      id: (editingSensorIdx !== null && currentUnitProp.sensors && currentUnitProp.sensors[editingSensorIdx]?.id) ? currentUnitProp.sensors[editingSensorIdx].id : Date.now(),
      name: name,
      type: document.getElementById('sp-type').value,
      desc: desc,
      param: param,
      unit: document.getElementById('sp-unit').value.trim() || 'l',
      last_msg: document.getElementById('sp-last-msg').checked,
      validator: document.getElementById('sp-validator').value,
      lower_bound: parseFloat(document.getElementById('sp-lower-bound').value) || 0,
      upper_bound: parseFloat(document.getElementById('sp-upper-bound').value) || 45,
      apply_bounds: document.getElementById('sp-apply-bounds').checked,
      min_filling: parseFloat(document.getElementById('sp-min-filling').value) || 5,
      min_theft: parseFloat(document.getElementById('sp-min-theft').value) || 10,
      table: table,
      xy_pairs: xyPairs,
      visible: (editingSensorIdx !== null && currentUnitProp.sensors && currentUnitProp.sensors[editingSensorIdx]?.visible !== undefined) ? currentUnitProp.sensors[editingSensorIdx].visible : true
    };

    if (!currentUnitProp.sensors) currentUnitProp.sensors = [];

    if (editingSensorIdx !== null && currentUnitProp.sensors[editingSensorIdx]) {
      currentUnitProp.sensors[editingSensorIdx] = Object.assign({}, currentUnitProp.sensors[editingSensorIdx], sensorObj);
    } else {
      currentUnitProp.sensors.push(sensorObj);
    }

    renderSensorsTable(currentUnitProp.sensors);
    closeSensorPropertiesModal();
  };

  window.addVoltageSensor = function() {
    if (!currentUnitProp.sensors) currentUnitProp.sensors = [];
    currentUnitProp.sensors.push({
      id: Date.now(),
      name: 'Voltage sensor',
      type: 'Voltage sensor',
      unit: 'V',
      param: 'pwr_int',
      desc: 'Main vehicle power line voltage',
      visible: true
    });
    renderSensorsTable(currentUnitProp.sensors);
  };

  window.toggleSensorVisible = function(idx, val) {
    if (currentUnitProp && currentUnitProp.sensors && currentUnitProp.sensors[idx]) {
      currentUnitProp.sensors[idx].visible = val;
    }
  };

  window.deleteSensor = function(idx) {
    if (!confirm('Delete this sensor?')) return;
    if (currentUnitProp && currentUnitProp.sensors) {
      currentUnitProp.sensors.splice(idx, 1);
      renderSensorsTable(currentUnitProp.sensors);
    }
  };

  window.filterSensorsTable = function() {
    const q = (document.getElementById('sensor-filter').value || '').toLowerCase().trim();
    if (!currentUnitProp || !currentUnitProp.sensors) return;
    const filtered = currentUnitProp.sensors.filter(s => 
      s.name.toLowerCase().includes(q) || s.type.toLowerCase().includes(q) || s.param.toLowerCase().includes(q)
    );
    renderSensorsTable(filtered);
  };

  window.addCustomFieldRow = function() {
    const k = prompt('Enter custom attribute name (e.g. Insurance Policy No, Trailer No):');
    if (!k) return;
    const v = prompt('Enter value for ' + k + ':');
    if (!currentUnitProp.properties) currentUnitProp.properties = {};
    if (!currentUnitProp.properties.custom_fields) currentUnitProp.properties.custom_fields = {};
    currentUnitProp.properties.custom_fields[k] = v || '';
    renderCustomFields(currentUnitProp.properties.custom_fields);
  };

  window.exportUnitProperties = function() {
    if (!currentUnitProp) return;
    const blob = new Blob([JSON.stringify(currentUnitProp, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `unit_properties_${currentUnitProp.name}.json`;
    a.click();
  };

  window.saveUnitProperties = function() {
    if (!currentUnitProp) return;

    const btn = document.getElementById('btn-save-unit-prop');
    btn.textContent = 'Saving...';
    btn.disabled = true;

    // Gather custom fields
    const customFields = {};
    document.querySelectorAll('.custom-field-val').forEach(inp => {
      customFields[inp.dataset.key] = inp.value;
    });
    if (!currentUnitProp.properties) currentUnitProp.properties = {};
    currentUnitProp.properties.custom_fields = customFields;

    const payload = {
      id: currentUnitProp.id,
      name: document.getElementById('prop-name').value,
      unit_type: document.getElementById('prop-unit-type').value,
      device_type: document.getElementById('prop-device-type').value,
      server_address: document.getElementById('prop-server-addr').value,
      unique_id: document.getElementById('prop-unique-id').value,
      phone: document.getElementById('prop-phone').value,
      icon_key: document.getElementById('prop-icon-key').value,
      mileage_val: parseFloat(document.getElementById('prop-mileage-val').value) || 0,
      engine_hours_val: parseFloat(document.getElementById('prop-hours-val').value) || 0,
      gprs_traffic_val: parseInt(document.getElementById('prop-traffic-val').value) || 0,
      consumption_rate: parseFloat(document.getElementById('prop-consumption-rate').value) || 10,
      speed_limit: parseInt(document.getElementById('prop-speed-limit').value) || 60,
      sensors: currentUnitProp.sensors || [],
      properties: currentUnitProp.properties || {}
    };

    fetch('unit_properties.php?action=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
      btn.textContent = 'OK';
      btn.disabled = false;
      if (data.success) {
        alert('✓ ' + (data.message || 'Unit properties saved successfully!'));
        closeUnitPropertiesModal();
        fetchFeed();
      } else {
        alert('Error saving properties: ' + (data.error || 'Failed'));
      }
    })
    .catch(err => {
      btn.textContent = 'OK';
      btn.disabled = false;
      alert('Failed to connect to server');
    });
  };
});
</script>

<?php layout_end(); ?>
