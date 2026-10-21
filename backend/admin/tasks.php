<?php
require __DIR__ . '/_common.php';
$me = require_admin();
require_perm('tasks', 'view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'create') {
        require_perm('tasks', 'create');
        $n = 0;
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $secId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;

        $geofenceId = !empty($_POST['geofence_id']) ? (int)$_POST['geofence_id'] : null;

        foreach ((array)($_POST['assigned_to'] ?? ['']) as $uid) {
            // If user selected, also infer user's department/section if not explicitly chosen
            $assignedUid = $uid ?: null;
            if ($assignedUid && (!$deptId || !$secId)) {
                $uInfo = q("SELECT department_id, section_id FROM users WHERE id=?", [$assignedUid])->fetch();
                if ($uInfo) {
                    if (!$deptId) $deptId = $uInfo['department_id'];
                    if (!$secId) $secId = $uInfo['section_id'];
                }
            }

            try {
                q('INSERT INTO tasks (title,description,assigned_to,department_id,section_id,geofence_id,address,lat,lng,due_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                    trim($_POST['title']), trim($_POST['description'] ?? '') ?: null, $assignedUid,
                    $deptId, $secId, $geofenceId,
                    trim($_POST['address'] ?? '') ?: null,
                    $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null,
                    $_POST['due_date'] ?: null, $me['id']]);
            } catch (Throwable $e) {
                // Fallback if department_id, section_id or geofence_id column is pending
                try {
                    q('INSERT INTO tasks (title,description,assigned_to,department_id,section_id,address,lat,lng,due_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                        trim($_POST['title']), trim($_POST['description'] ?? '') ?: null, $assignedUid,
                        $deptId, $secId,
                        trim($_POST['address'] ?? '') ?: null,
                        $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null,
                        $_POST['due_date'] ?: null, $me['id']]);
                } catch (Throwable $e2) {
                    q('INSERT INTO tasks (title,description,assigned_to,address,lat,lng,due_date,created_by) VALUES (?,?,?,?,?,?,?,?)', [
                        trim($_POST['title']), trim($_POST['description'] ?? '') ?: null, $assignedUid,
                        trim($_POST['address'] ?? '') ?: null,
                        $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null,
                        $_POST['due_date'] ?: null, $me['id']]);
                }
            }
            $n++;
        }
        flash("$n task(s) created successfully.");
    } elseif ($act === 'cancel') {
        require_perm('tasks', 'status');
        q("UPDATE tasks SET status='cancelled' WHERE id=? AND status<>'done'", [(int)$_POST['id']]);
        flash('Task cancelled.');
    }
    header('Location: tasks.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'user', 'date', 'dept', 'sec'])))); 
    exit;
}

// Master Lookups
$departments = q("SELECT id, name, code FROM departments WHERE active=1 ORDER BY name ASC")->fetchAll();
$sections = q("SELECT id, department_id, name, code FROM sections WHERE active=1 ORDER BY name ASC")->fetchAll();
// Master Geofence Groups & Initial Lookups
$gfGroups = [];
try {
    $gfGroups = q("SELECT DISTINCT group_name FROM geofences WHERE active=1 AND group_name IS NOT NULL AND group_name != '' ORDER BY group_name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$initialGeofences = [];
try {
    $initialGeofences = q("SELECT id, name, code, group_name, coordinates FROM geofences WHERE active=1 ORDER BY name ASC LIMIT 40")->fetchAll();
    foreach ($initialGeofences as &$gf) {
        $coords = json_decode($gf['coordinates'] ?? '[]', true);
        $lat = ''; $lng = '';
        if (is_array($coords) && count($coords) > 0) {
            $sumLat = 0; $sumLng = 0; $n = count($coords);
            foreach ($coords as $c) { $sumLat += (float)$c[0]; $sumLng += (float)$c[1]; }
            $lat = round($sumLat / $n, 6);
            $lng = round($sumLng / $n, 6);
        }
        $gf['center_lat'] = $lat;
        $gf['center_lng'] = $lng;
    }
    unset($gf);
} catch (Throwable $e) {}

// Handle AJAX search for 5,000+ Geofences / Plots in Create Task Modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_geofences') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $group = trim($_GET['group'] ?? '');
    
    $where = ["active = 1"];
    $params = [];
    
    if ($group !== '') {
        $where[] = "group_name = ?";
        $params[] = $group;
    }
    
    if ($q !== '') {
        $where[] = "(name LIKE ? OR code LIKE ? OR description LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    
    $rows = [];
    try {
        $rows = q("SELECT id, name, code, group_name, coordinates FROM geofences WHERE " . implode(' AND ', $where) . " ORDER BY name ASC LIMIT 60", $params)->fetchAll();
    } catch (Throwable $e) {}
    
    $out = [];
    foreach ($rows as $gf) {
        $coords = json_decode($gf['coordinates'] ?? '[]', true);
        $lat = ''; $lng = '';
        if (is_array($coords) && count($coords) > 0) {
            $sumLat = 0; $sumLng = 0; $n = count($coords);
            foreach ($coords as $c) { $sumLat += (float)$c[0]; $sumLng += (float)$c[1]; }
            $lat = round($sumLat / $n, 6);
            $lng = round($sumLng / $n, 6);
        }
        $out[] = [
            'id' => (int)$gf['id'],
            'name' => $gf['name'],
            'code' => $gf['code'] ?? '',
            'group' => $gf['group_name'] ?? '',
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
    echo json_encode(['ok' => true, 'count' => count($out), 'plots' => $out]);
    exit;
}

// CSV export / filters
$where = [user_section_scope_sql('u')]; 
$p = [];
if (!empty($_GET['status'])) { $where[] = 't.status=?'; $p[] = $_GET['status']; }
if (!empty($_GET['user'])) { $where[] = 't.assigned_to=?'; $p[] = (int)$_GET['user']; }
if (!empty($_GET['dept'])) { $where[] = '(t.department_id=? OR u.department_id=?)'; array_push($p, (int)$_GET['dept'], (int)$_GET['dept']); }
if (!empty($_GET['sec'])) { $where[] = '(t.section_id=? OR u.section_id=?)'; array_push($p, (int)$_GET['sec'], (int)$_GET['sec']); }
if (!empty($_GET['date'])) { 
    $where[] = '(DATE(t.created_at)=? OR DATE(t.completed_at)=? OR t.due_date=?)'; 
    array_push($p, $_GET['date'], $_GET['date'], $_GET['date']); 
}

$sql = "SELECT t.*, u.name staff, u.code staff_code, u.designation staff_desig,
   s.name section_name, d.name department_name,
   gf.name geofence_name, gf.code geofence_code,
   (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='before' ORDER BY id DESC LIMIT 1) bp,
   (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='after' ORDER BY id DESC LIMIT 1) ap
   FROM tasks t 
   LEFT JOIN users u ON u.id=t.assigned_to 
   LEFT JOIN sections s ON s.id=t.section_id
   LEFT JOIN departments d ON d.id=t.department_id
   LEFT JOIN geofences gf ON gf.id=t.geofence_id
   WHERE " . implode(' AND ', $where) . " ORDER BY t.id DESC LIMIT 500";
$tasks = q($sql, $p)->fetchAll();

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv'); 
    header('Content-Disposition: attachment; filename="tasks_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['ID', 'Title', 'Staff', 'Department', 'Section', 'Status', 'Due', 'Started', 'Completed', 'Minutes', 'Note']);
    foreach ($tasks as $t) {
        $mins = $t['started_at'] && $t['completed_at'] ? round((strtotime($t['completed_at']) - strtotime($t['started_at'])) / 60) : '';
        fputcsv($o, [$t['id'], $t['title'], $t['staff'], $t['department_name'], $t['section_name'], $t['status'], $t['due_date'], $t['started_at'], $t['completed_at'], $mins, $t['staff_note']]);
    }
    exit;
}

$secScopeU = user_section_scope_sql('u');
$staff = q("SELECT u.id, u.name, u.code, u.department_id, u.section_id, u.designation,
                   d.name dept_name, s.name sec_name 
            FROM users u 
            LEFT JOIN departments d ON d.id=u.department_id 
            LEFT JOIN sections s ON s.id=u.section_id 
            WHERE u.role='staff' AND u.active=1 AND $secScopeU
            ORDER BY u.name")->fetchAll();

layout_start('Tasks & Assignments', 'tasks.php', true);
?>
<!-- Tasks Filter Toolbar -->
<div class="w-subbar">
  <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <span style="font-weight:600;color:#334155;font-size:12px">Filter:</span>
    <select name="status" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
      <option value="">All Statuses</option>
      <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="in_progress" <?= ($_GET['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
      <option value="done" <?= ($_GET['status'] ?? '') === 'done' ? 'selected' : '' ?>>Completed</option>
      <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>

    <select name="dept" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
      <option value="">All Departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= ($_GET['dept'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="sec" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
      <option value="">All Sections</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= $s['id'] ?>" <?= ($_GET['sec'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="user" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
      <option value="">All Staff / Units</option>
      <?php foreach ($staff as $s): ?>
        <option value="<?= $s['id'] ?>" <?= ($_GET['user'] ?? '') == $s['id'] ? 'selected' : '' ?>>
          <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="date" value="<?= h($_GET['date'] ?? '') ?>" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">

    <button type="submit" class="w-icon-btn primary" style="padding:4px 10px">Apply Filter</button>
    <a href="?<?= h(http_build_query($_GET + ['csv' => 1])) ?>" class="w-icon-btn" style="padding:4px 10px">📥 Export CSV</a>
  </form>

  <?php if (can('tasks', 'create')): ?>
    <button class="w-icon-btn primary" onclick="openCreateTaskModal()">
      + Create New Task
    </button>
  <?php endif; ?>
</div>

<div class="w-page-container">
  <!-- Tasks Table -->
  <div class="w-card">
    <table class="w-table">
      <thead>
        <tr>
          <th style="width:40px">#</th>
          <th>Task Title & Address</th>
          <th>Section / Department</th>
          <th>Assigned Staff / Unit</th>
          <th>Status</th>
          <th>Before / After Photos</th>
          <th>Due Date</th>
          <th>Completed</th>
          <th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td style="color:#64748b;font-weight:600">#<?= $t['id'] ?></td>
            <td>
              <a href="task.php?id=<?= $t['id'] ?>" style="font-weight:600;font-size:13px"><?= h($t['title']) ?></a>
              <?php if (!empty($t['geofence_name'])): ?>
                <div style="margin-top:2px">
                  <span style="display:inline-block;padding:1px 6px;background:#dcfce7;color:#15803d;border-radius:4px;font-size:10px;font-weight:700">
                    🌾 <?= h($t['geofence_name']) ?> <?= !empty($t['geofence_code']) ? '(' . h($t['geofence_code']) . ')' : '' ?>
                  </span>
                  <?php if (!empty($t['lat']) && !empty($t['lng'])): ?>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $t['lat'] ?>,<?= $t['lng'] ?>" target="_blank" style="font-size:10px;color:#0284c7;text-decoration:none;margin-left:4px" title="Open Google Maps Directions">🚗 Directions ↗</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($t['address'])): ?>
                <div style="font-size:11px;color:#64748b"><?= h($t['address']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($t['section_name'])): ?>
                <span style="display:inline-block;padding:2px 7px;background:#ecfdf5;color:#047857;border-radius:4px;font-size:11px;font-weight:600">
                  📍 <?= h($t['section_name']) ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($t['department_name'])): ?>
                <div style="font-size:10px;color:#64748b;margin-top:2px"><?= h($t['department_name']) ?></div>
              <?php endif; ?>
              <?php if (empty($t['section_name']) && empty($t['department_name'])): ?>
                <span style="color:#94a3b8;font-size:11px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= h($t['staff'] ?? 'Unassigned') ?></strong>
              <?php if (!empty($t['staff_desig'])): ?>
                <div style="font-size:11px;color:#0284c7"><?= h($t['staff_desig']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php 
                $sc = ['pending' => '#f59e0b', 'in_progress' => '#3b82f6', 'done' => '#10b981', 'cancelled' => '#ef4444'][$t['status']] ?? '#94a3b8';
              ?>
              <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:#fff;background:<?= $sc ?>">
                <?= ucfirst(str_replace('_', ' ', $t['status'])) ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:6px;align-items:center">
                <?php if (!empty($t['bp'])): ?>
                  <a href="../<?= h($CONFIG['upload_url'] . '/' . $t['bp']) ?>" target="_blank" title="Before Photo">
                    <img src="../<?= h($CONFIG['upload_url'] . '/' . $t['bp']) ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid #10b981">
                  </a>
                <?php else: ?>
                  <span style="color:#cbd5e1;font-size:11px">Before: –</span>
                <?php endif; ?>
                <?php if (!empty($t['ap'])): ?>
                  <a href="../<?= h($CONFIG['upload_url'] . '/' . $t['ap']) ?>" target="_blank" title="After Photo">
                    <img src="../<?= h($CONFIG['upload_url'] . '/' . $t['ap']) ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid #3b82f6">
                  </a>
                <?php else: ?>
                  <span style="color:#cbd5e1;font-size:11px">| After: –</span>
                <?php endif; ?>
              </div>
            </td>
            <td><?= h($t['due_date'] ?? '–') ?></td>
            <td><?= h($t['completed_at'] ? substr($t['completed_at'], 0, 16) : '–') ?></td>
            <td style="text-align:right">
              <a href="task.php?id=<?= $t['id'] ?>" class="w-icon-btn primary" style="font-size:11px;padding:3px 8px">View Details</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tasks): ?>
          <tr><td colspan="9" style="text-align:center;padding:24px;color:#94a3b8">No tasks found matching filter criteria.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Dialog to Create New Task with Section Search & Dynamic Staff Filtering -->
<div id="task-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:580px;max-width:92vw;max-height:92vh;overflow-y:auto;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,0.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <h3 style="margin:0;font-size:16px;color:#1e293b">Create New Task</h3>
      <button onclick="document.getElementById('task-modal').style.display='none'" style="background:none;border:none;font-size:16px;cursor:pointer">✕</button>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Task Title *</label>
        <input name="title" required placeholder="e.g. Fertilizer Application Block 4, Harvesting Sector D" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      </div>

      <!-- Section & Department Filter Bar inside Modal -->
      <div style="background:#f8fafc;padding:12px;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:12px">
        <label style="display:block;font-size:11px;color:#0284c7;margin-bottom:6px;font-weight:700;text-transform:uppercase">
          🏢 Filter Staff by Section / Department:
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <select name="department_id" id="task-create-dept" onchange="filterModalStaff()" style="width:100%;padding:6px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
              <option value="">All Departments</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <select name="section_id" id="task-create-sec" onchange="onTaskCreateSecChange()" style="width:100%;padding:6px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
              <option value="">All Sections / Divisions</option>
              <?php foreach ($sections as $s): ?>
                <option value="<?= $s['id'] ?>" data-dept="<?= (int)$s['department_id'] ?>"><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <label style="font-size:12px;color:#475569;font-weight:600">Assign to Field Staff / Unit</label>
          <span id="staff-match-count" style="font-size:11px;color:#64748b"><?= count($staff) ?> staff available</span>
        </div>
        
        <!-- Live Instant Search Bar for Staff -->
        <input type="text" id="staff-search-input" onkeyup="filterModalStaff()" placeholder="🔍 Quick Search Staff (Name / Code / Designation)..." style="width:100%;padding:6px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px 4px 0 0;box-sizing:border-box;background:#fefefe">
        
        <select name="assigned_to[]" id="task-staff-select" size="5" style="width:100%;padding:4px;border:1px solid #cbd5e1;border-top:none;border-radius:0 0 4px 4px;font-size:12px">
          <option value="" selected>— Unassigned (Field staff can self-assign) —</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= $s['id'] ?>" 
                    data-dept="<?= (int)$s['department_id'] ?>" 
                    data-sec="<?= (int)$s['section_id'] ?>" 
                    data-text="<?= strtolower(h($s['name'] . ' ' . $s['code'] . ' ' . $s['designation'] . ' ' . $s['sec_name'])) ?>">
              <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?> <?= !empty($s['designation']) ? '— ' . h($s['designation']) : '' ?> <?= !empty($s['sec_name']) ? '📍 ' . h($s['sec_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Geofence / Plantation Plot Selector (Searchable across 5,000+ plots) -->
      <div style="background:#f0fdf4;padding:12px;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <label style="font-size:11px;color:#15803d;font-weight:700;text-transform:uppercase">
            🌾 Select Geofence / Plot (Auto-Attach GPS Location):
          </label>
          <div style="display:flex;align-items:center;gap:6px">
            <span id="gf-search-status" style="font-size:10.5px;color:#059669;font-weight:600">5,000+ plots</span>
            <span id="gf-coord-badge" style="display:none;font-size:10px;padding:2px 7px;border-radius:10px;background:#15803d;color:#fff;font-weight:600">GPS Attached ✓</span>
          </div>
        </div>

        <!-- Quick Search & Group Filters -->
        <div style="display:grid;grid-template-columns:1fr 140px;gap:6px;margin-bottom:6px">
          <div style="position:relative">
            <input type="text" id="task-geofence-search" placeholder="🔍 Quick Search 5,000+ Plots (e.g. 207, Hingurana, D/)..." 
                   oninput="onGeofenceSearchInput()" 
                   style="width:100%;padding:6px 26px 6px 8px;font-size:12px;border:1px solid #86efac;border-radius:4px;background:#fff;box-sizing:border-box">
            <button type="button" onclick="clearGeofenceSearch()" title="Clear Search" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;color:#94a3b8;cursor:pointer;font-size:13px;padding:0;line-height:1">✕</button>
          </div>
          <select id="task-geofence-group-filter" onchange="onGeofenceGroupChange()" style="padding:6px;font-size:11.5px;border:1px solid #86efac;border-radius:4px;background:#fff">
            <option value="">All Groups</option>
            <?php foreach ($gfGroups as $grp): ?>
              <option value="<?= h($grp) ?>"><?= h($grp) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Plots Dropdown -->
        <select name="geofence_id" id="task-geofence-select" onchange="onGeofenceSelected(this)" style="width:100%;padding:7px 10px;font-size:12px;border:1px solid #86efac;border-radius:4px;background:#fff;box-sizing:border-box">
          <option value="">— Optional: Link to Plantation Plot / Geofence —</option>
          <?php foreach ($initialGeofences as $gf): ?>
            <option value="<?= $gf['id'] ?>" 
                    data-name="<?= h($gf['name']) ?>" 
                    data-code="<?= h($gf['code'] ?? '') ?>"
                    data-group="<?= h($gf['group_name'] ?? '') ?>"
                    data-lat="<?= $gf['center_lat'] ?>" 
                    data-lng="<?= $gf['center_lng'] ?>">
              <?= h($gf['name']) ?> <?= !empty($gf['code']) ? '(' . h($gf['code']) . ')' : '' ?> <?= !empty($gf['group_name']) ? '— ' . h($gf['group_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Selected Plot Confirmation Banner -->
        <div id="gf-selected-banner" style="display:none;margin-top:6px;padding:6px 10px;background:#dcfce7;border:1px solid #86efac;border-radius:4px;font-size:11.5px;color:#166534;align-items:center;justify-content:space-between">
          <span id="gf-selected-text"></span>
          <button type="button" onclick="clearSelectedGeofence()" style="border:none;background:#bbf7d0;color:#15803d;padding:2px 8px;border-radius:4px;font-size:10.5px;cursor:pointer;font-weight:700">Remove</button>
        </div>

        <input type="hidden" name="lat" id="task-modal-lat" value="">
        <input type="hidden" name="lng" id="task-modal-lng" value="">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Due Date</label>
          <input type="date" name="due_date" value="<?= date('Y-m-d') ?>" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
        </div>
        <div>
          <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Address / Field Location</label>
          <input name="address" placeholder="e.g. Block D/207/01, Hingurana" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
        </div>
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Description / Work Instructions</label>
        <textarea name="description" rows="3" placeholder="Provide instructions for field staff (e.g. Apply 50kg urea per acre)..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px">
        <button type="button" class="w-icon-btn" onclick="document.getElementById('task-modal').style.display='none'">Cancel</button>
        <button type="submit" class="w-icon-btn primary" style="padding:6px 16px">Create Task</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateTaskModal() {
  document.getElementById('task-modal').style.display = 'flex';
  filterModalStaff();
}

function onTaskCreateSecChange() {
  var secSelect = document.getElementById('task-create-sec');
  var opt = secSelect.options[secSelect.selectedIndex];
  if (opt) {
    var deptId = opt.getAttribute('data-dept');
    if (deptId) {
      document.getElementById('task-create-dept').value = deptId;
    }
  }
  filterModalStaff();
}

function filterModalStaff() {
  var deptId = document.getElementById('task-create-dept').value;
  var secId = document.getElementById('task-create-sec').value;
  var search = document.getElementById('staff-search-input').value.toLowerCase().trim();
  
  // Also filter the section dropdown options by selected department
  var secSelect = document.getElementById('task-create-sec');
  var secOptions = secSelect.getElementsByTagName('option');
  for (var i = 1; i < secOptions.length; i++) {
    var sOpt = secOptions[i];
    var sDept = sOpt.getAttribute('data-dept');
    if (!deptId || !sDept || sDept === deptId) {
      sOpt.style.display = '';
    } else {
      sOpt.style.display = 'none';
      if (sOpt.selected) secSelect.value = '';
    }
  }

  // Filter staff select list
  var select = document.getElementById('task-staff-select');
  var options = select.getElementsByTagName('option');
  var visibleCount = 0;

  for (var j = 1; j < options.length; j++) {
    var opt = options[j];
    var optDept = opt.getAttribute('data-dept');
    var optSec = opt.getAttribute('data-sec');
    var optText = opt.getAttribute('data-text') || '';

    var matchDept = !deptId || optDept === deptId;
    var matchSec = !secId || optSec === secId;
    var matchSearch = !search || optText.indexOf(search) !== -1;

    if (matchDept && matchSec && matchSearch) {
      opt.style.display = '';
      visibleCount++;
    } else {
      opt.style.display = 'none';
      if (opt.selected) opt.selected = false;
    }
  }

  document.getElementById('staff-match-count').innerText = visibleCount + ' staff matching';
}

var gfSearchDebounce = null;

function onGeofenceSearchInput() {
  clearTimeout(gfSearchDebounce);
  gfSearchDebounce = setTimeout(function() {
    searchGeofencesAjax();
  }, 220);
}

function onGeofenceGroupChange() {
  searchGeofencesAjax();
}

function clearGeofenceSearch() {
  document.getElementById('task-geofence-search').value = '';
  document.getElementById('task-geofence-group-filter').value = '';
  searchGeofencesAjax();
}

function clearSelectedGeofence() {
  var sel = document.getElementById('task-geofence-select');
  sel.value = '';
  onGeofenceSelected(sel);
}

function searchGeofencesAjax() {
  var q = document.getElementById('task-geofence-search').value.trim();
  var grp = document.getElementById('task-geofence-group-filter').value;
  var statusSpan = document.getElementById('gf-search-status');
  var sel = document.getElementById('task-geofence-select');

  if (statusSpan) statusSpan.textContent = 'Searching...';

  var url = 'tasks.php?ajax=search_geofences&q=' + encodeURIComponent(q) + '&group=' + encodeURIComponent(grp);

  fetch(url)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data || !data.ok) return;
      var plots = data.plots || [];
      
      var currVal = sel.value;
      var currOpt = sel.options[sel.selectedIndex];
      var currOptSaved = (currVal && currOpt) ? {
        value: currVal,
        text: currOpt.text,
        name: currOpt.getAttribute('data-name'),
        code: currOpt.getAttribute('data-code'),
        group: currOpt.getAttribute('data-group'),
        lat: currOpt.getAttribute('data-lat'),
        lng: currOpt.getAttribute('data-lng')
      } : null;

      sel.innerHTML = '';
      
      var defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = plots.length === 0 
        ? '— No matching plots found —' 
        : '— Select matching plot (' + plots.length + ' found) —';
      sel.appendChild(defaultOpt);

      var foundCurrent = false;
      for (var i = 0; i < plots.length; i++) {
        var p = plots[i];
        if (currOptSaved && String(p.id) === String(currOptSaved.value)) foundCurrent = true;
        var opt = document.createElement('option');
        opt.value = p.id;
        opt.setAttribute('data-name', p.name || '');
        opt.setAttribute('data-code', p.code || '');
        opt.setAttribute('data-group', p.group || '');
        opt.setAttribute('data-lat', p.lat || '');
        opt.setAttribute('data-lng', p.lng || '');
        opt.textContent = p.name + (p.code ? ' (' + p.code + ')' : '') + (p.group ? ' — ' + p.group : '');
        sel.appendChild(opt);
      }

      if (currOptSaved && !foundCurrent) {
        var opt = document.createElement('option');
        opt.value = currOptSaved.value;
        opt.setAttribute('data-name', currOptSaved.name);
        opt.setAttribute('data-code', currOptSaved.code);
        opt.setAttribute('data-group', currOptSaved.group);
        opt.setAttribute('data-lat', currOptSaved.lat);
        opt.setAttribute('data-lng', currOptSaved.lng);
        opt.textContent = '✓ ' + currOptSaved.text;
        opt.selected = true;
        sel.insertBefore(opt, sel.children[1]);
      } else if (currOptSaved && foundCurrent) {
        sel.value = currOptSaved.value;
      }

      if (statusSpan) {
        statusSpan.textContent = plots.length + ' matching';
      }
    })
    .catch(function(err) {
      if (statusSpan) statusSpan.textContent = '5,000+ plots';
    });
}

function onGeofenceSelected(select) {
  var opt = select.options[select.selectedIndex];
  var addrInput = document.querySelector('input[name="address"]');
  var latInput = document.getElementById('task-modal-lat');
  var lngInput = document.getElementById('task-modal-lng');
  var badge = document.getElementById('gf-coord-badge');
  var banner = document.getElementById('gf-selected-banner');
  var bannerText = document.getElementById('gf-selected-text');

  if (opt && opt.value) {
    var name = opt.getAttribute('data-name') || '';
    var code = opt.getAttribute('data-code') || '';
    var group = opt.getAttribute('data-group') || '';
    var lat = opt.getAttribute('data-lat') || '';
    var lng = opt.getAttribute('data-lng') || '';

    var fullLoc = name + (code ? ' (' + code + ')' : '') + (group ? ', ' + group : '');
    if (addrInput) addrInput.value = fullLoc;
    if (latInput) latInput.value = lat;
    if (lngInput) lngInput.value = lng;
    if (badge) {
      badge.style.display = 'inline-block';
      badge.textContent = '📍 ' + lat + ', ' + lng;
    }
    if (banner && bannerText) {
      banner.style.display = 'flex';
      bannerText.textContent = '🌾 Selected: ' + fullLoc + (lat && lng ? ' (📍 ' + lat + ', ' + lng + ')' : '');
    }
  } else {
    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';
    if (badge) badge.style.display = 'none';
    if (banner) banner.style.display = 'none';
  }
}
</script>

<?php layout_end(); ?>
