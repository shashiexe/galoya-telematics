<?php
require __DIR__ . '/_common.php';
$me = require_admin();
require_perm('units', 'view');

// Handle Unit CRUD / quick actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'save_unit') {
            $id = (int)($_POST['id'] ?? 0);
            require_perm('units', $id > 0 ? 'edit' : 'create');

            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $unit_type = trim($_POST['unit_type'] ?? 'tractor');
            $device_type = trim($_POST['device_type'] ?? 'Teltonika FMB125');
            $unique_id = trim($_POST['unique_id'] ?? '');
            $icon_key = trim($_POST['icon_key'] ?? 'tractor_blue');
            $driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
            $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
            if ($section_id) {
                $pDept = q("SELECT department_id FROM sections WHERE id=?", [$section_id])->fetchColumn();
                if ($pDept) $department_id = (int)$pDept;
            }

            if (empty($name)) throw new Exception("Unit name is required");

            if ($id > 0) {
                q("UPDATE users SET name=?, code=?, phone=?, unit_type=?, device_type=?, unique_id=?, icon_key=?, department_id=?, section_id=? WHERE id=?",
                  [$name, $code, $phone, $unit_type, $device_type, $unique_id, $icon_key, $department_id, $section_id, $id]);
                if (!empty($_POST['password'])) {
                    q("UPDATE users SET password_hash=? WHERE id=?", [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                }
                
                // Update driver binding
                if ($driver_id) {
                    q("UPDATE drivers SET assigned_unit_id = NULL WHERE assigned_unit_id = ?", [$id]);
                    q("UPDATE drivers SET assigned_unit_id = ? WHERE id = ?", [$id, $driver_id]);
                }
                flash("Unit '{$name}' updated successfully");
            } else {
                $pwd = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : password_hash('123456', PASSWORD_DEFAULT);
                $phoneVal = !empty($phone) ? $phone : 'unit_' . time();
                
                q("INSERT INTO users (name, code, phone, password_hash, role, unit_type, device_type, unique_id, icon_key, department_id, section_id, active, on_duty) 
                   VALUES (?,?,?,?,'staff',?,?,?,?,?,?,1,1)",
                  [$name, $code, $phoneVal, $pwd, $unit_type, $device_type, $unique_id, $icon_key, $department_id, $section_id]);
                $newId = (int)db()->lastInsertId();

                if ($driver_id) {
                    q("UPDATE drivers SET assigned_unit_id = ? WHERE id = ?", [$newId, $driver_id]);
                }
                flash("New unit '{$name}' registered in fleet");
            }
        } elseif ($act === 'toggle_active') {
            require_perm('units', 'toggle');
            $id = (int)$_POST['id'];
            q("UPDATE users SET active = 1 - active WHERE id = ?", [$id]);
            flash("Unit status changed");
        } elseif ($act === 'delete_unit') {
            require_perm('units', 'delete');
            $id = (int)$_POST['id'];
            if ($id !== (int)$me['id']) {
                q("UPDATE drivers SET assigned_unit_id = NULL WHERE assigned_unit_id = ?", [$id]);
                q("DELETE FROM users WHERE id = ? AND role = 'staff'", [$id]);
                flash("Unit removed from fleet");
            }
        }
    } catch (Throwable $e) {
        flash("Error: " . $e->getMessage());
    }

    header('Location: units.php');
    exit;
}

// Fetch all available drivers
$drivers = q("SELECT id, name, code, assigned_unit_id FROM drivers WHERE active=1 ORDER BY name ASC")->fetchAll();
$departments = q("SELECT id, name FROM departments WHERE active=1 ORDER BY name ASC")->fetchAll();
$sections = q("SELECT id, department_id, name, code FROM sections WHERE active=1 ORDER BY name ASC")->fetchAll();

// Filter & Search
$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$sql = "SELECT u.*, 
        dept.name as dept_name, sec.name as sec_name,
        d.name as driver_name, d.id as driver_id, d.phone as driver_phone,
        (SELECT speed FROM locations WHERE user_id = u.id ORDER BY recorded_at DESC LIMIT 1) as latest_speed,
        (SELECT lat FROM locations WHERE user_id = u.id ORDER BY recorded_at DESC LIMIT 1) as latest_lat,
        (SELECT lng FROM locations WHERE user_id = u.id ORDER BY recorded_at DESC LIMIT 1) as latest_lng,
        (SELECT recorded_at FROM locations WHERE user_id = u.id ORDER BY recorded_at DESC LIMIT 1) as latest_time
        FROM users u
        LEFT JOIN departments dept ON dept.id = u.department_id
        LEFT JOIN sections sec ON sec.id = u.section_id
        LEFT JOIN drivers d ON d.assigned_unit_id = u.id
        WHERE u.role = 'staff' AND " . user_section_scope_sql('u');
$params = [];

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.code LIKE ? OR u.unique_id LIKE ? OR u.phone LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term, $term];
}

if ($type_filter !== 'all') {
    $sql .= " AND u.unit_type = ?";
    $params[] = $type_filter;
}

if ($status_filter === 'active') {
    $sql .= " AND u.active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND u.active = 0";
}

$sql .= " ORDER BY u.active DESC, u.name ASC";
$units = q($sql, $params)->fetchAll();

// Counts
$off_min = (int)($CONFIG['offline_after_min'] ?? 10);
$total_units = count($units);
$active_count = count(array_filter($units, fn($u) => $u['active']));
$online_count = count(array_filter($units, function($u) use ($off_min) {
    if (empty($u['last_seen'])) return false;
    return (time() - strtotime($u['last_seen'])) <= ($off_min * 60);
}));
$assigned_driver_count = count(array_filter($units, fn($u) => !empty($u['driver_name'])));

layout_start('Fleet Units', 'units.php');
?>

<div style="padding:16px 20px">
  <!-- Wialon Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px;color:#263238">
        <span>🚜</span> <span>Fleet Units & GPS Trackers Management</span>
      </h2>
      <div style="font-size:12px;color:#78909c;margin-top:2px">
        Manage machinery telematics hardware, IMEIs, sensors, icons, and operator bindings
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="staff.php" class="btn ghost sm" style="display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #cbd5e1" title="Manage Departments, Sections & Personnel">
        <span>🏢</span> <span>Staff & Departments</span>
      </a>
      <?php if (can('units', 'create')): ?>
        <button class="btn" onclick="openUnitModal()" style="display:flex;align-items:center;gap:6px;background:#0284c7">
          <span>➕</span> <span>New Unit</span>
        </button>
      <?php endif; ?>
      <a href="index.php" class="btn ghost sm" style="display:flex;align-items:center;gap:6px">
        <span>🛰️</span> <span>Live Monitoring</span>
      </a>
    </div>
  </div>

  <!-- Metric Quick Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:16px">
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #0284c7">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Total Machinery Units</div>
      <div style="font-size:22px;font-weight:700;color:#263238;margin-top:4px"><?= $total_units ?> Units</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #2e7d32">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Live GPS Online</div>
      <div style="font-size:22px;font-weight:700;color:<?= $online_count > 0 ? '#2e7d32' : '#94a3b8' ?>;margin-top:4px"><?= $online_count ?> Online</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #7c3aed">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Bound Operators</div>
      <div style="font-size:22px;font-weight:700;color:#7c3aed;margin-top:4px"><?= $assigned_driver_count ?> Assigned</div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #f57c00">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Sugar Sector Divisions</div>
      <div style="font-size:22px;font-weight:700;color:#f57c00;margin-top:4px">Hingurana Estate</div>
    </div>
  </div>

  <!-- Filter & Search Toolbar -->
  <div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0">
      <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search unit name, code, IMEI..." style="width:240px;font-size:12px;padding:6px 10px;height:32px">
      
      <select name="type" style="width:175px;font-size:12px;padding:4px 8px;height:32px" onchange="this.form.submit()">
        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Unit Types</option>
        <optgroup label="👨‍💼 Field Personnel">
          <option value="field_staff" <?= $type_filter === 'field_staff' ? 'selected' : '' ?>>👨‍🌾 Field Staff / Officers</option>
          <option value="supervisor" <?= $type_filter === 'supervisor' ? 'selected' : '' ?>>👷‍♂️ Field Supervisors</option>
          <option value="manager" <?= $type_filter === 'manager' ? 'selected' : '' ?>>👔 Division / Managers</option>
          <option value="security" <?= $type_filter === 'security' ? 'selected' : '' ?>>🛡️ Security / Watchers</option>
          <option value="staff" <?= $type_filter === 'staff' ? 'selected' : '' ?>>📱 Mobile Staff (App)</option>
        </optgroup>
        <optgroup label="🚜 Estate Machinery">
          <option value="tractor" <?= $type_filter === 'tractor' ? 'selected' : '' ?>>🚜 Tractors</option>
          <option value="harvester" <?= $type_filter === 'harvester' ? 'selected' : '' ?>>🌾 Sugarcane Harvesters</option>
          <option value="infielder" <?= $type_filter === 'infielder' ? 'selected' : '' ?>>🚛 Infielders</option>
          <option value="excavator" <?= $type_filter === 'excavator' ? 'selected' : '' ?>>🏗️ Backhoes & Excavators</option>
        </optgroup>
        <optgroup label="🚚 Vehicles & Transport">
          <option value="lorry" <?= $type_filter === 'lorry' ? 'selected' : '' ?>>🚚 Cane Transport Lorries</option>
          <option value="bolero" <?= $type_filter === 'bolero' ? 'selected' : '' ?>>🚙 Bolero / Pickups (4x4)</option>
          <option value="motorcycle" <?= $type_filter === 'motorcycle' ? 'selected' : '' ?>>🏍️ Field Motorcycles</option>
          <option value="bicycle" <?= $type_filter === 'bicycle' ? 'selected' : '' ?>>🚲 Service Bicycles</option>
        </optgroup>
      </select>

      <select name="status" style="width:130px;font-size:12px;padding:4px 8px;height:32px" onchange="this.form.submit()">
        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active Fleet</option>
        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
      </select>

      <button class="btn sm" type="submit" style="height:32px">Filter</button>
      <?php if ($search || $type_filter !== 'all' || $status_filter !== 'all'): ?>
        <a href="units.php" class="btn ghost sm" style="height:32px">Reset</a>
      <?php endif; ?>
    </form>
    <div style="font-size:12px;color:#78909c">
      Showing <strong><?= count($units) ?></strong> fleet units
    </div>
  </div>

  <!-- Units Table -->
  <div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;text-align:left;color:#475569">
          <th style="padding:10px 14px;width:35px">#</th>
          <th style="padding:10px 14px;width:40px">Icon</th>
          <th style="padding:10px 14px">Unit Name & Code</th>
          <th style="padding:10px 14px">Device Hardware & IMEI</th>
          <th style="padding:10px 14px">Assigned Driver</th>
          <th style="padding:10px 14px">Latest Telemetry Status</th>
          <th style="padding:10px 14px">Last Connection</th>
          <th style="padding:10px 14px;text-align:center">State</th>
          <th style="padding:10px 14px;text-align:right">Unit Settings & Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($units as $idx => $u): 
          $iconEmoji = match($u['unit_type'] ?? '') {
              'field_staff' => '👨‍🌾',
              'supervisor'  => '👷‍♂️',
              'manager'     => '👔',
              'security'    => '🛡️',
              'staff'       => '👨‍🌾',
              'tractor'     => '🚜',
              'harvester'   => '🌾',
              'infielder'   => '🚛',
              'excavator'   => '🏗️',
              'lorry', 'truck' => '🚚',
              'bolero', 'car', 'vehicle' => '🚙',
              'motorcycle', 'bike' => '🏍️',
              'bicycle'     => '🚲',
              'phone'       => '📱',
              default       => '🚜'
          };
          $speed = (float)($u['latest_speed'] ?? 0);
          $isMoving = $speed > 0;
        ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $u['active'] ? '' : 'opacity:0.55;background:#fafafa' ?>">
            <td style="padding:10px 14px;color:#94a3b8;font-size:11px"><?= $idx + 1 ?></td>
            <td style="padding:10px 14px;font-size:20px;text-align:center">
              <?= $iconEmoji ?>
            </td>
            <td style="padding:10px 14px">
              <div style="font-weight:700;color:#1e293b;font-size:13px">
                <a href="index.php?focus=<?= $u['id'] ?>" style="color:#0284c7;text-decoration:none">
                  <?= h($u['name']) ?>
                </a>
              </div>
              <div style="font-size:11px;color:#64748b;display:flex;gap:6px;margin-top:2px;align-items:center;flex-wrap:wrap">
                <span style="font-family:monospace;background:#f1f5f9;padding:1px 5px;border-radius:3px"><?= h($u['code']) ?></span>
                <span>• <?= ucfirst(str_replace('_', ' ', $u['unit_type'] ?: 'staff')) ?></span>
                <?php if (!empty($u['dept_name'])): ?>
                  <span style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:4px;font-weight:600;font-size:10.5px">🏢 <?= h($u['dept_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($u['sec_name'])): ?>
                  <span style="background:#ecfdf5;color:#047857;padding:1px 6px;border-radius:4px;font-weight:600;font-size:10.5px">🌾 <?= h($u['sec_name']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td style="padding:10px 14px">
              <div style="font-weight:600;color:#334155"><?= h($u['device_type'] ?: 'Teltonika FMB125') ?></div>
              <div style="font-size:11px;font-family:monospace;color:#64748b;margin-top:2px">
                IMEI: <?= h($u['unique_id'] ?: '35890' . sprintf('%010d', $u['id'] * 12345)) ?>
              </div>
              <?php if (!empty($u['gprs_traffic_val'])): ?>
                <div style="font-size:10.5px;color:#0369a1;margin-top:3px;display:flex;align-items:center;gap:3px;background:#f0f9ff;border:1px solid #bae6fd;padding:1px 6px;border-radius:4px;width:fit-content" title="Cellular GPRS/Network Data Consumed by App">
                  <span>📶 Data:</span>
                  <strong><?= $u['gprs_traffic_val'] >= 1024 ? round($u['gprs_traffic_val'] / 1024, 2) . ' MB' : $u['gprs_traffic_val'] . ' KB' ?></strong>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
              <?php if (!empty($u['driver_name'])): ?>
                <div style="display:inline-flex;align-items:center;gap:5px;background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:12px;font-weight:600;font-size:11.5px">
                  <span>👨‍✈️</span> <span><?= h($u['driver_name']) ?></span>
                </div>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px;font-style:italic">Unassigned Operator</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
              <?php if ($isMoving): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;color:#15803d;font-weight:700">
                  <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e"></span>
                  Moving: <?= $speed ?> km/h
                </span>
              <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:4px;color:#64748b">
                  <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#cbd5e1"></span>
                  Stationary (0 km/h)
                </span>
              <?php endif; ?>
              <?php if (!empty($u['latest_lat'])): ?>
                <div style="font-size:10.5px;color:#94a3b8;font-family:monospace;margin-top:2px">
                  <?= round($u['latest_lat'], 4) ?>, <?= round($u['latest_lng'], 4) ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;color:#64748b;font-size:11.5px;white-space:nowrap">
              <?= h($u['last_seen'] ?: 'Recent') ?>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <?php if (can('units', 'toggle')): ?>
                <form method="post" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="toggle_active">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" style="background:none;border:none;cursor:pointer" title="Toggle active status">
                    <?php if ($u['active']): ?>
                      <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">Active</span>
                    <?php else: ?>
                      <span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">Disabled</span>
                    <?php endif; ?>
                  </button>
                </form>
              <?php else: ?>
                <?php if ($u['active']): ?>
                  <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">Active</span>
                <?php else: ?>
                  <span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">Disabled</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap">
              <?php if (can('units', 'edit')): ?>
                <!-- Wialon Unit Properties Modal Button -->
                <button class="btn sm" onclick="openPropertiesModal(<?= $u['id'] ?>)" style="background:#37474f;color:#fff;padding:4px 8px;font-size:11.5px;margin-right:4px" title="Unit Properties (13 Wialon Tabs)">
                  <span>🔧</span> Properties
                </button>
                <button class="btn ghost sm" onclick="editUnit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)" style="padding:4px 8px;font-size:11.5px">Edit</button>
              <?php endif; ?>
              <?php if (can('units', 'delete')): ?>
                <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Delete unit <?= h($u['name']) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="delete_unit">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button class="btn ghost sm red" style="padding:4px 8px;font-size:11.5px">Del</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Unit Basic Modal Dialog -->
<div id="unit-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:500px;max-width:95vw;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden">
    <div style="background:#1e293b;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px">
        <span>🚜</span> <span id="modal-unit-title">New Machinery Unit</span>
      </div>
      <button onclick="document.getElementById('unit-modal').style.display='none'" style="background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer">&times;</button>
    </div>
    
    <form method="post" style="padding:16px;margin:0" id="unit-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_unit">
      <input type="hidden" name="id" id="unit_id" value="0">

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Unit Name *</label>
        <input type="text" name="name" id="unit_name" required placeholder="e.g. TRACTOR 49-1665" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Unit Code / Plate</label>
          <input type="text" name="code" id="unit_code" placeholder="e.g. 49-1665" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Unit Category / Type *</label>
          <select name="unit_type" id="unit_type" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
            <optgroup label="👨‍💼 Personnel & Field Staff">
              <option value="field_staff">👨‍🌾 Field Staff / Field Officer</option>
              <option value="supervisor">👷‍♂️ Field Supervisor</option>
              <option value="manager">👔 Division / Estate Manager</option>
              <option value="security">🛡️ Field Security / Watcher</option>
              <option value="staff">📱 WiaTag Mobile App / Staff</option>
            </optgroup>
            <optgroup label="🚜 Agricultural Machinery">
              <option value="tractor" selected>🚜 Agricultural Tractor</option>
              <option value="harvester">🌾 Sugarcane Harvester</option>
              <option value="infielder">🚛 Infield Transporter</option>
              <option value="excavator">🏗️ Backhoe / Excavator</option>
            </optgroup>
            <optgroup label="🚚 Fleet Vehicles & Transport">
              <option value="lorry">🚚 Cane Transport Lorry</option>
              <option value="bolero">🚙 Bolero Field Inspection (4x4)</option>
              <option value="motorcycle">🏍️ Field Motorcycle / Trail Bike</option>
              <option value="bicycle">🚲 Service Bicycle</option>
            </optgroup>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Device Hardware</label>
          <input type="text" name="device_type" id="device_type" placeholder="e.g. Teltonika FMB125" value="Teltonika FMB125" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Unique ID / IMEI</label>
          <input type="text" name="unique_id" id="unique_id" placeholder="e.g. 358901234567891" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Assigned Operator / Driver</label>
          <select name="driver_id" id="driver_id" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
            <option value="">-- No Driver (Pool) --</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['id'] ?>">👨‍✈️ <?= h($d['name']) ?> (<?= h($d['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">SIM / Phone Login</label>
          <input type="text" name="phone" id="unit_phone" placeholder="e.g. 0771234567" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Department</label>
          <select name="department_id" id="unit_dept" onchange="filterUnitSections()" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
            <option value="">-- No Department --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>">🏢 <?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Section</label>
          <select name="section_id" id="unit_sec" onchange="onUnitSecChange()" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
            <option value="">-- No Section --</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= $s['id'] ?>" data-dept="<?= $s['department_id'] ?>">🌾 <?= h($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Password <span style="font-weight:400;color:#94a3b8">(leave blank to keep current)</span></label>
        <input type="password" name="password" id="unit_password" placeholder="••••••••" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0">
        <button type="button" class="btn ghost" onclick="document.getElementById('unit-modal').style.display='none'">Cancel</button>
        <button type="submit" class="btn" style="background:#0284c7">Save Unit</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Container for 13-Tab Wialon Unit Properties -->
<div id="wialon-props-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.65);z-index:99999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:860px;max-width:96vw;height:85vh;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);overflow:hidden;display:flex;flex-direction:column">
    <iframe id="props-iframe" src="about:blank" style="width:100%;height:100%;border:none"></iframe>
  </div>
</div>

<script>
function openUnitModal() {
  document.getElementById('modal-unit-title').textContent = 'New Machinery Unit';
  document.getElementById('unit_id').value = '0';
  document.getElementById('unit_name').value = '';
  document.getElementById('unit_code').value = '';
  document.getElementById('unit_type').value = 'tractor';
  document.getElementById('device_type').value = 'Teltonika FMB125';
  document.getElementById('unique_id').value = '';
  document.getElementById('driver_id').value = '';
  document.getElementById('unit_phone').value = '';
  document.getElementById('unit_dept').value = '';
  document.getElementById('unit_sec').value = '';
  filterUnitSections();
  document.getElementById('unit-modal').style.display = 'flex';
}

function editUnit(u) {
  document.getElementById('modal-unit-title').textContent = 'Edit Unit: ' + u.name;
  document.getElementById('unit_id').value = u.id;
  document.getElementById('unit_name').value = u.name || '';
  document.getElementById('unit_code').value = u.code || '';
  document.getElementById('unit_type').value = u.unit_type || 'tractor';
  document.getElementById('device_type').value = u.device_type || 'Teltonika FMB125';
  document.getElementById('unique_id').value = u.unique_id || '';
  document.getElementById('driver_id').value = u.driver_id || '';
  document.getElementById('unit_phone').value = u.phone || '';
  document.getElementById('unit_dept').value = u.department_id || '';
  filterUnitSections();
  document.getElementById('unit_sec').value = u.section_id || '';
  document.getElementById('unit-modal').style.display = 'flex';
}

function onUnitSecChange() {
  const secSelect = document.getElementById('unit_sec');
  const secId = secSelect.value;
  if (!secId) return;
  const opt = secSelect.options[secSelect.selectedIndex];
  const deptId = opt ? opt.getAttribute('data-dept') : null;
  if (deptId) {
    document.getElementById('unit_dept').value = deptId;
    filterUnitSections();
    secSelect.value = secId;
  }
}

function filterUnitSections() {
  const deptId = document.getElementById('unit_dept').value;
  const secSelect = document.getElementById('unit_sec');
  const opts = secSelect.querySelectorAll('option');
  opts.forEach(opt => {
    if (!opt.value) { opt.style.display = ''; return; }
    const optDept = opt.getAttribute('data-dept');
    if (!deptId || optDept === deptId) {
      opt.style.display = '';
    } else {
      opt.style.display = 'none';
      if (secSelect.value === opt.value) secSelect.value = '';
    }
  });
}

function openPropertiesModal(unitId) {
  window.location.href = 'index.php?open_prop=' + unitId;
}

window.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('add') === '1' || urlParams.get('new') === '1') {
    openUnitModal();
  }
});
</script>

<?php layout_end(); ?>
