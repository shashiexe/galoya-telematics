<?php
require __DIR__ . '/_common.php';
require_admin();

// JSON feed for live telematics refresh
if (isset($_GET['feed'])) {
    header('Content-Type: application/json');
    $off = (int)$CONFIG['offline_after_min'];
    
    // Fetch units with telematics data
    $units = q("SELECT id, name, code, phone, role, unit_type, on_duty, active,
                       last_lat lat, last_lng lng, last_battery battery, speed, ignition, motion_state, sos_active, last_seen,
                       TIMESTAMPDIFF(MINUTE, last_seen, NOW()) mins_ago,
                       (SELECT COUNT(*) FROM tasks WHERE assigned_to=users.id AND status='in_progress') active_tasks
                FROM users WHERE role='staff' AND active=1 
                ORDER BY sos_active DESC, (motion_state='moving') DESC, on_duty DESC, name ASC")->fetchAll();

    foreach ($units as &$u) {
        $u['is_online'] = ($u['last_seen'] !== null && (int)$u['mins_ago'] <= $off && (int)$u['on_duty'] === 1);
    }

    // Fetch geofences
    $geofences = q("SELECT id, name, code, type, coordinates, color FROM geofences WHERE active=1")->fetchAll();
    foreach ($geofences as &$g) {
        $g['coords'] = json_decode($g['coordinates'], true) ?: [];
    }

    // Fetch active SOS alerts
    $sos_alerts = q("SELECT s.*, u.name, u.phone FROM sos_alerts s JOIN users u ON u.id=s.user_id WHERE s.status='active' ORDER BY s.id DESC LIMIT 5")->fetchAll();

    echo json_encode([
        'units' => $units,
        'geofences' => $geofences,
        'sos' => $sos_alerts,
        'center' => $CONFIG['map_center'],
        'zoom' => (int)$CONFIG['map_zoom']
    ]);
    exit;
}

layout_start('Monitoring', 'index.php', true);
?>
<div class="w-monitoring-layout">
  <!-- Left Units Sidebar (Screenshot 3) -->
  <aside class="w-units-sidebar">
    <div class="w-units-searchbox">
      <input type="text" id="unit-search" placeholder="Search units, vehicles, staff...">
      <button class="w-icon-btn" id="btn-clear-search" title="Clear">✕</button>
    </div>

    <div class="w-units-toolbar">
      <a href="staff.php?action=new" class="w-icon-btn primary" title="Add Unit">+</a>
      <button class="w-icon-btn" id="btn-toggle-all" title="Toggle visibility of all units">👁️ All</button>
      <button class="w-icon-btn" id="btn-sort" title="Sort A-Z">A-Z</button>
      <select id="type-filter" style="width:auto;padding:2px 4px;font-size:11px;border:1px solid #cbd5e1;border-radius:3px">
        <option value="">All Types</option>
        <option value="tractor">Tractor</option>
        <option value="harvester">Harvester</option>
        <option value="infielder">Infielder</option>
        <option value="vehicle">Vehicle</option>
        <option value="excavator">Excavator</option>
        <option value="staff">Staff</option>
      </select>
    </div>

    <!-- Units Telematics Table -->
    <div class="w-units-table-container">
      <table class="w-units-table">
        <thead>
          <tr>
            <th style="width:24px;text-align:center">
              <input type="checkbox" id="chk-master" checked title="Select All">
            </th>
            <th>Unit / Machine</th>
            <th style="width:22px;text-align:center" title="Motion state">M</th>
            <th style="width:20px;text-align:center" title="Ignition">IGN</th>
            <th style="width:16px;text-align:center" title="GPS connection">GPS</th>
            <th style="width:36px;text-align:right" title="Battery">🔋</th>
            <th style="width:52px;text-align:center" title="Settings / History">🔧 / ↝</th>
          </tr>
        </thead>
        <tbody id="units-tbody">
          <tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8">Loading telematics feed...</td></tr>
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
    <div id="map"></div>


    <!-- Live Coordinate HUD at bottom right (Screenshot 3: N 07° 12.3522' E 081° 39.5479') -->
    <div class="w-coord-hud" id="w-coord-hud">
      N 07° 12.3522' &nbsp; E 081° 39.5479'
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
            <option value="tractor">Tractor (Estate Agriculture)</option>
            <option value="harvester">Harvester (Sugarcane Combine)</option>
            <option value="infielder">Infielder / Cane Loader</option>
            <option value="vehicle">Vehicle / Bolero 4x4 / Heavy Truck</option>
            <option value="excavator">Excavator / Backhoe</option>
            <option value="staff">Staff Officer (Field Tracking)</option>
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
              <button type="button" class="w-btn-new-sensor" onclick="promptAddSensor()">+ New Sensor</button>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
  const mapElement = document.getElementById('map');
  const defaultCenter = <?= json_encode($CONFIG['map_center']) ?>;
  const defaultZoom = <?= (int)$CONFIG['map_zoom'] ?>;

  const map = StMap.create(mapElement, defaultCenter, defaultZoom);

  let unitsData = [];
  let geofencesData = [];
  const unitMarkers = {};
  const geofenceLayers = [];
  let showGeofences = true;

  // Icons mapping for unit types
  const typeIcons = {
    tractor: '🚜',
    harvester: '🌾',
    infielder: '🚜',
    vehicle: '🚚',
    excavator: '🏗️',
    staff: '👤'
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

  // Render Geofences on Map
  function renderGeofences(geofences) {
    geofenceLayers.forEach(l => map.removeLayer(l));
    geofenceLayers.length = 0;

    if (!showGeofences) return;

    geofences.forEach(gf => {
      if (!gf.coords || gf.coords.length < 3) return;
      
      const poly = L.polygon(gf.coords, {
        color: gf.color || '#4caf50',
        fillColor: gf.color || '#4caf50',
        fillOpacity: 0.25,
        weight: 2
      }).addTo(map);

      // Centered label (e.g. D/207/01)
      const center = poly.getBounds().getCenter();
      const label = L.marker(center, {
        icon: L.divIcon({
          className: 'w-geofence-label',
          html: `<div style="color:${gf.color || '#e65100'};font-weight:700;font-size:12px;background:rgba(255,255,255,0.7);padding:2px 6px;border-radius:3px">${gf.name}</div>`,
          iconSize: [80, 20],
          iconAnchor: [40, 10]
        })
      }).addTo(map);

      poly.bindPopup(`<strong>Geofence: ${gf.name}</strong><br>${gf.description || ''}`);
      geofenceLayers.push(poly, label);
    });
  }

  // Render Unit list in left panel
  function renderUnitsTable() {
    const q = document.getElementById('unit-search').value.toLowerCase().trim();
    const typeF = document.getElementById('type-filter').value;

    const filtered = unitsData.filter(u => {
      const matchText = (u.name + ' ' + (u.code || '')).toLowerCase().includes(q);
      const matchType = !typeF || u.unit_type === typeF;
      return matchText && matchType;
    });

    document.getElementById('units-summary-count').textContent = `${filtered.length} of ${unitsData.length} units`;

    const tbody = document.getElementById('units-tbody');
    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8">No matching units found</td></tr>`;
      return;
    }

    tbody.innerHTML = filtered.map(u => {
      const icon = typeIcons[u.unit_type] || '🚜';
      const isOnline = !!u.is_online;
      const isMoving = isOnline && (u.motion_state === 'moving' || parseFloat(u.speed || 0) > 0);
      
      // Motion column
      let motionHtml = '';
      if (!isOnline) {
        motionHtml = `<span class="w-motion-badge offline" title="Offline (GPS Stopped)">● OFF</span>`;
      } else if (isMoving) {
        motionHtml = `<span class="w-motion-badge moving" title="Moving at ${u.speed} km/h"><span class="w-moving-arrow">➔</span> ${Math.round(u.speed)}</span>`;
      } else {
        motionHtml = `<span class="w-motion-badge stationary" title="Stationary (0 km/h)">⛔ 0</span>`;
      }

      // Ignition
      const ignHtml = `<div class="w-ignition-bars ${(u.ignition && isOnline) ? 'w-ign-on' : ''}" title="Ignition: ${(u.ignition && isOnline) ? 'ON' : 'OFF'}"><span class="w-ign-bar"></span><span class="w-ign-bar"></span></div>`;

      // GPS dot
      const gpsHtml = `<span class="w-gps-dot ${isOnline ? 'online' : ''}" title="${isOnline ? 'GPS Online' : 'GPS Offline'}"></span>`;

      // Battery
      const batHtml = (isOnline && u.battery !== null) ? `<span style="color:${+u.battery < 20 ? '#d32f2f' : '#2e7d32'}">${u.battery}%</span>` : (u.battery !== null ? `<span style="color:#94a3b8">${u.battery}%</span>` : '–');

      return `
        <tr class="w-unit-row ${isMoving ? 'is-moving' : ''}" data-id="${u.id}" ondblclick="openUnitPropertiesModal(${u.id})">
          <td style="text-align:center" onclick="event.stopPropagation()">
            <input type="checkbox" class="chk-unit" data-id="${u.id}" checked>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <span class="w-unit-vehicle-icon ${isMoving ? 'moving' : ''}">${icon}</span>
              <div>
                <strong style="color:#1e293b">${u.name}</strong>
                ${isMoving ? `<span style="font-size:10px;color:#16a34a;font-weight:700;margin-left:4px">🚗💨 ${Math.round(u.speed)} km/h</span>` : (isOnline ? `<span style="font-size:9.5px;color:#0284c7;margin-left:4px">● Online</span>` : `<span style="font-size:9.5px;color:#94a3b8;margin-left:4px">● Offline</span>`)}
              </div>
            </div>
          </td>
          <td style="text-align:center">${motionHtml}</td>
          <td style="text-align:center">${ignHtml}</td>
          <td style="text-align:center">${gpsHtml}</td>
          <td style="text-align:right;font-size:11px">${batHtml}</td>
          <td style="text-align:center" onclick="event.stopPropagation()">
            <button type="button" class="w-icon-btn btn-unit-settings" onclick="openUnitPropertiesModal(${u.id})" style="padding:1px 5px;cursor:pointer;font-size:12px" title="Unit properties (Settings)">🔧</button>
            <a href="history.php?user=${u.id}" class="w-icon-btn" style="padding:1px 5px;margin-left:2px" title="Route history">↝</a>
          </td>
        </tr>
      `;
    }).join('');
  }

  // Update Leaflet Markers
  function updateMarkers() {
    unitsData.forEach(u => {
      if (u.lat === null || u.lng === null) return;

      const lat = parseFloat(u.lat);
      const lng = parseFloat(u.lng);
      const icon = typeIcons[u.unit_type] || '🚜';
      const isOnline = !!u.is_online;
      const isMoving = isOnline && (u.motion_state === 'moving' || parseFloat(u.speed || 0) > 0);
      const stateClass = !isOnline ? 'offline' : (isMoving ? 'moving' : 'stationary');

      const labelHtml = `
        <div class="w-unit-marker-wrap">
          ${isMoving ? '<div class="w-radar-pulse"></div>' : ''}
          <div class="w-marker-bubble ${stateClass}">
            <span class="w-mb-icon">${icon}</span>
            <span>${u.name.split(' ')[0]}</span>
            ${isMoving ? `<span class="w-mb-speed">➔ ${Math.round(u.speed)}k</span>` : (isOnline ? `<span class="w-mb-speed">0k</span>` : `<span class="w-mb-speed">OFF</span>`)}
          </div>
        </div>
      `;

      const customIcon = L.divIcon({
        className: 'w-telematics-pin',
        html: labelHtml,
        iconSize: [110, 40],
        iconAnchor: [55, 20]
      });

      const popupContent = `
        <div style="min-width:180px;font-size:12px;line-height:1.4">
          <strong style="font-size:13px;color:#1565c0">${icon} ${u.name}</strong>
          ${u.code ? `<span style="color:#64748b;font-size:11px">(${u.code})</span>` : ''}
          <hr style="margin:6px 0;border:0;border-top:1px solid #e2e8f0">
          <div>Status: <strong style="color:${isMoving ? '#16a34a' : (isOnline ? '#0284c7' : '#94a3b8')}">${!isOnline ? 'OFFLINE' : (isMoving ? 'MOVING ➔' : 'STATIONARY ⛔')}</strong></div>
          <div>Speed: <strong>${Math.round(u.speed || 0)} km/h</strong></div>
          <div>Ignition: <strong>${(u.ignition && isOnline) ? 'ON' : 'OFF'}</strong></div>
          <div>Battery: <strong>${u.battery !== null ? u.battery + '%' : 'N/A'}</strong></div>
          <div>Coordinates: <code>${lat.toFixed(5)}, ${lng.toFixed(5)}</code></div>
          <div style="margin-top:8px">
            <a href="history.php?user=${u.id}" class="w-icon-btn primary" style="font-size:11px;padding:3px 8px">Track Route History</a>
          </div>
        </div>
      `;

      if (unitMarkers[u.id]) {
        unitMarkers[u.id].setLatLng([lat, lng]);
        unitMarkers[u.id].setIcon(customIcon);
        unitMarkers[u.id].setPopupContent(popupContent);
      } else {
        const marker = L.marker([lat, lng], { icon: customIcon }).addTo(map);
        marker.bindPopup(popupContent);
        unitMarkers[u.id] = marker;
      }
    });
  }

  // Fetch live data from backend feed
  async function fetchFeed() {
    try {
      const res = await fetch('index.php?feed=1');
      const data = await res.json();
      unitsData = data.units || [];
      geofencesData = data.geofences || [];

      renderUnitsTable();
      updateMarkers();
      renderGeofences(geofencesData);

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
    const focusId = urlParams.get('focus');
    if (focusId) {
      setTimeout(() => {
        const u = unitsData.find(x => x.id == focusId);
        if (u && u.lat && u.lng) {
          map.flyTo([parseFloat(u.lat), parseFloat(u.lng)], 16, { animate: true, duration: 1.2 });
          if (unitMarkers[focusId]) unitMarkers[focusId].openPopup();
        }
      }, 600);
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

    tbodyEl.addEventListener('change', (e) => {
      if (e.target.classList.contains('chk-unit')) {
        const id = e.target.dataset.id;
        if (unitMarkers[id]) {
          if (e.target.checked) map.addLayer(unitMarkers[id]);
          else map.removeLayer(unitMarkers[id]);
        }
      }
    });
  }

  // Search & filter handlers
  const uSearch = document.getElementById('unit-search');
  if (uSearch) uSearch.addEventListener('input', renderUnitsTable);

  const tFilter = document.getElementById('type-filter');
  if (tFilter) tFilter.addEventListener('change', renderUnitsTable);

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
      const pts = unitsData.filter(u => u.lat && u.lng).map(u => [parseFloat(u.lat), parseFloat(u.lng)]);
      if (pts.length > 0) map.fitBounds(L.latLngBounds(pts), { padding: [50, 50] });
    });
  }

  // Master checkbox toggle
  const chkMaster = document.getElementById('chk-master');
  if (chkMaster) {
    chkMaster.addEventListener('change', (e) => {
      const checked = e.target.checked;
      document.querySelectorAll('.chk-unit').forEach(chk => {
        chk.checked = checked;
        const id = chk.dataset.id;
        if (unitMarkers[id]) {
          if (checked) map.addLayer(unitMarkers[id]);
          else map.removeLayer(unitMarkers[id]);
        }
      });
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
        <td style="color:#64748b">${s.desc || ''}</td>
        <td style="text-align:center">
          <input type="checkbox" ${s.visible ? 'checked' : ''} onchange="toggleSensorVisible(${idx}, this.checked)">
        </td>
        <td style="text-align:center">
          <button type="button" class="w-icon-btn" onclick="editSensor(${idx})" title="Edit sensor">🔧</button>
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

  window.promptAddSensor = function() {
    const name = prompt('Enter sensor name (e.g. Fuel Level Tank 2, PTO RPM, Engine Oil Pressure):');
    if (!name) return;
    const param = prompt('Enter parameter identifier (e.g. io_202, can_fuel, pwr_ext):', 'io_' + Math.floor(Math.random() * 100));
    if (!param) return;
    
    if (!currentUnitProp.sensors) currentUnitProp.sensors = [];
    currentUnitProp.sensors.push({
      id: Date.now(),
      name: name,
      type: 'Custom analog/digital sensor',
      unit: 'l',
      param: param,
      desc: 'Configured by Admin',
      visible: true
    });
    renderSensorsTable(currentUnitProp.sensors);
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

  window.editSensor = function(idx) {
    const s = currentUnitProp.sensors[idx];
    const newName = prompt('Sensor Name:', s.name);
    if (newName) {
      s.name = newName;
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
