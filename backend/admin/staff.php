<?php
require __DIR__ . '/_common.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name']); 
            $phone = trim($_POST['phone']);
            $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $secId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
            $desigId = !empty($_POST['designation_id']) ? (int)$_POST['designation_id'] : null;
            
            // Get designation name
            $desigName = null;
            if ($desigId) {
                $desigName = q("SELECT name FROM designations WHERE id=?", [$desigId])->fetchColumn() ?: null;
            }

            if ($id) {
                q('UPDATE users SET name=?, phone=?, role=?, department_id=?, section_id=?, designation_id=?, designation=? WHERE id=?', 
                  [$name, $phone, $role, $deptId, $secId, $desigId, $desigName, $id]);
                if (!empty($_POST['password'])) {
                    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                    q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
                }
                flash('Staff member updated successfully');
            } else {
                if (strlen($_POST['password'] ?? '') < 4) throw new Exception('Password must be at least 4 characters');
                q('INSERT INTO users (name,phone,password_hash,role,department_id,section_id,designation_id,designation) VALUES (?,?,?,?,?,?,?,?)',
                  [$name, $phone, password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $deptId, $secId, $desigId, $desigName]);
                flash('Staff member added successfully');
            }
        } elseif ($act === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id !== (int)$me['id']) {
                q('UPDATE users SET active=1-active WHERE id=?', [$id]);
                q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
            }
            flash('Status changed');
        } elseif ($act === 'add_dept') {
            $dName = trim($_POST['dept_name'] ?? '');
            $dCode = trim($_POST['dept_code'] ?? '');
            if ($dName) {
                q("INSERT IGNORE INTO departments (name, code) VALUES (?, ?)", [$dName, $dCode ?: null]);
                flash("Department '$dName' created");
            }
        } elseif ($act === 'add_sec') {
            $sName = trim($_POST['sec_name'] ?? '');
            $sCode = trim($_POST['sec_code'] ?? '');
            $dId = !empty($_POST['sec_dept_id']) ? (int)$_POST['sec_dept_id'] : null;
            if ($sName) {
                q("INSERT IGNORE INTO sections (department_id, name, code) VALUES (?, ?, ?)", [$dId, $sName, $sCode ?: null]);
                flash("Section '$sName' created");
            }
        } elseif ($act === 'add_desig') {
            $desName = trim($_POST['desig_name'] ?? '');
            $desCode = trim($_POST['desig_code'] ?? '');
            if ($desName) {
                q("INSERT IGNORE INTO designations (name, code) VALUES (?, ?)", [$desName, $desCode ?: null]);
                flash("Designation '$desName' created");
            }
        } elseif ($act === 'del_master') {
            $type = $_POST['type'] ?? '';
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($type === 'dept' && $itemId) {
                q("DELETE FROM departments WHERE id=?", [$itemId]);
                q("UPDATE users SET department_id=NULL WHERE department_id=?", [$itemId]);
                q("UPDATE sections SET department_id=NULL WHERE department_id=?", [$itemId]);
                flash("Department removed");
            } elseif ($type === 'sec' && $itemId) {
                q("DELETE FROM sections WHERE id=?", [$itemId]);
                q("UPDATE users SET section_id=NULL WHERE section_id=?", [$itemId]);
                flash("Section removed");
            } elseif ($type === 'desig' && $itemId) {
                q("DELETE FROM designations WHERE id=?", [$itemId]);
                q("UPDATE users SET designation_id=NULL WHERE designation_id=?", [$itemId]);
                flash("Designation removed");
            }
        }
    } catch (Throwable $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'Record or phone number already exists' : $e->getMessage());
    }
    header('Location: staff.php'); exit;
}

// Fetch Master Lookups
$departments = q("SELECT * FROM departments WHERE active=1 ORDER BY name ASC")->fetchAll();
$sections = q("SELECT s.*, d.name dept_name FROM sections s LEFT JOIN departments d ON d.id=s.department_id WHERE s.active=1 ORDER BY s.name ASC")->fetchAll();
$designations = q("SELECT * FROM designations WHERE active=1 ORDER BY name ASC")->fetchAll();

$edit = isset($_GET['edit']) ? q('SELECT * FROM users WHERE id=?', [(int)$_GET['edit']])->fetch() : null;

// Filter staff list
$filterDept = (int)($_GET['dept'] ?? 0);
$filterSec = (int)($_GET['sec'] ?? 0);
$where = ["1=1"];
$params = [];
if ($filterDept) { $where[] = "u.department_id=?"; $params[] = $filterDept; }
if ($filterSec) { $where[] = "u.section_id=?"; $params[] = $filterSec; }

$users = q("SELECT u.*,
   d.name dept_name, d.code dept_code,
   s.name sec_name, s.code sec_code,
   ds.name desig_title,
   (SELECT COUNT(*) FROM tasks WHERE assigned_to=u.id AND status='done') done,
   (SELECT COUNT(*) FROM tasks WHERE assigned_to=u.id AND status IN ('pending','in_progress')) open
   FROM users u 
   LEFT JOIN departments d ON d.id=u.department_id
   LEFT JOIN sections s ON s.id=u.section_id
   LEFT JOIN designations ds ON ds.id=u.designation_id
   WHERE " . implode(' AND ', $where) . "
   ORDER BY u.role, d.name, s.name, u.name", $params)->fetchAll();

layout_start('Staff & Organization Management', 'staff.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-weight:600;font-size:13px;color:#475569">Filter By:</span>
    <form method="get" style="display:inline-flex;gap:8px;align-items:center">
      <select name="dept" onchange="this.form.submit()" style="padding:5px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="sec" onchange="this.form.submit()" style="padding:5px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
        <option value="">All Sections</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSec === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?> (<?= h($s['dept_name'] ?? 'General') ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if ($filterDept || $filterSec): ?>
        <a href="staff.php" class="btn ghost sm">Clear Filter</a>
      <?php endif; ?>
    </form>
  </div>

  <button class="btn ghost sm" onclick="document.getElementById('org-modal').style.display='flex'">
    🏢 Manage Departments & Sections
  </button>
</div>

<div class="grid" style="grid-template-columns:1fr 360px;align-items:start;gap:18px">
  <!-- Staff Table -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
      <span style="font-weight:700;font-size:14px;color:#1e293b">Field Staff & System Users (<?= count($users) ?>)</span>
      <span style="font-size:11px;color:#64748b">Wialon & Mobile App Units</span>
    </div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;margin:0">
        <thead>
          <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1;text-align:left;font-size:11px;color:#475569;text-transform:uppercase">
            <th style="padding:10px 14px">Name & Phone</th>
            <th style="padding:10px 14px">Department & Section</th>
            <th style="padding:10px 14px">Designation / Role</th>
            <th style="padding:10px 14px">Duty</th>
            <th style="padding:10px 14px">Last Seen</th>
            <th style="padding:10px 14px">Tasks</th>
            <th style="padding:10px 14px;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr style="border-bottom:1px solid #e2e8f0;<?= $u['active'] ? '' : 'opacity:.45;background:#f8fafc' ?>">
            <td style="padding:10px 14px">
              <strong style="color:#0f172a;display:block"><?= h($u['name']) ?></strong>
              <span style="font-size:11px;color:#64748b"><?= h($u['phone']) ?></span>
            </td>
            <td style="padding:10px 14px">
              <?php if (!empty($u['dept_name'])): ?>
                <span style="display:inline-block;padding:2px 7px;background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:11px;font-weight:600">
                  <?= h($u['dept_name']) ?>
                </span>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">—</span>
              <?php endif; ?>
              
              <?php if (!empty($u['sec_name'])): ?>
                <div style="margin-top:3px;font-size:11px;color:#475569;font-weight:500">
                  📍 <?= h($u['sec_name']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
              <span style="font-size:12px;color:#334155;font-weight:600">
                <?= h($u['desig_title'] ?: ($u['designation'] ?: ($u['role'] === 'admin' ? 'Administrator' : 'Field Operator'))) ?>
              </span>
              <div style="font-size:10px;color:#94a3b8;text-transform:uppercase"><?= h($u['role']) ?></div>
            </td>
            <td style="padding:10px 14px">
              <span class="dot <?= $u['on_duty'] ? 'on' : 'off' ?>" style="display:inline-block" title="<?= $u['on_duty'] ? 'On Duty' : 'Off Duty' ?>"></span>
            </td>
            <td style="padding:10px 14px;font-size:11px;color:#64748b">
              <?= h($u['last_seen'] ? date('M d, H:i', strtotime($u['last_seen'])) : '—') ?>
            </td>
            <td style="padding:10px 14px;font-size:11px">
              <span style="color:#f59e0b;font-weight:600"><?= (int)$u['open'] ?> Open</span> / 
              <span style="color:#10b981;font-weight:600"><?= (int)$u['done'] ?> Done</span>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap">
              <a class="btn ghost sm" href="?edit=<?= $u['id'] ?>">Edit</a>
              <?php if ($u['id'] != $me['id']): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn sm <?= $u['active'] ? 'red' : '' ?>" style="padding:3px 8px;font-size:11px"><?= $u['active'] ? 'Disable' : 'Enable' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($users)): ?>
            <tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8">No users found for this filter criteria.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Add / Edit Staff Form -->
  <form method="post" class="card" id="staff-form" style="padding:18px">
    <h3 style="margin:0 0 14px 0;font-size:15px;color:#1e293b"><?= $edit ? 'Edit Staff / User' : 'Add New Staff Member' ?></h3>
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Full Name *</label>
    <input name="name" required value="<?= h($edit['name'] ?? '') ?>" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Phone / Login Mobile *</label>
    <input name="phone" required value="<?= h($edit['phone'] ?? '') ?>" placeholder="077xxxxxxx" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Department</label>
    <select name="department_id" id="user-dept" onchange="filterSectionsByDept()" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      <option value="">Select Department...</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= ((int)($edit['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?> (<?= h($d['code'] ?: 'Dept') ?>)</option>
      <?php endforeach; ?>
    </select>

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Section</label>
    <select name="section_id" id="user-sec" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      <option value="">Select Section...</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= $s['id'] ?>" data-dept="<?= (int)$s['department_id'] ?>" <?= ((int)($edit['section_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
          <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Designation / Role</label>
    <select name="designation_id" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      <option value="">Select Designation...</option>
      <?php foreach ($designations as $ds): ?>
        <option value="<?= $ds['id'] ?>" <?= ((int)($edit['designation_id'] ?? 0) === (int)$ds['id']) ? 'selected' : '' ?>><?= h($ds['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">System Access Role</label>
    <select name="role" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      <option value="staff" <?= ($edit['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff (Mobile App User)</option>
      <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin (Web Control Room)</option>
    </select>

    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">
      Password <?= $edit ? '<span style="font-weight:normal;color:#94a3b8">(leave blank to keep unchanged)</span>' : '*' ?>
    </label>
    <input name="password" type="password" <?= $edit ? '' : 'required' ?> placeholder="••••••••" style="width:100%;margin-bottom:16px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">

    <div style="display:flex;gap:8px">
      <button class="btn" style="flex:1" id="save-staff"><?= $edit ? 'Save Changes' : 'Create User' ?></button>
      <?php if ($edit): ?><a class="btn ghost" href="staff.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<!-- Modal: Master Management (Departments, Sections, Designations) -->
<div id="org-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;width:780px;max-width:94vw;max-height:90vh;overflow-y:auto;padding:22px;box-shadow:0 10px 40px rgba(0,0,0,0.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;color:#0f172a">🏢 Manage Organization: Departments, Sections & Designations</h3>
      <button onclick="document.getElementById('org-modal').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer">✕</button>
    </div>

    <!-- 1. Departments Section -->
    <div style="margin-bottom:24px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="color:#0369a1;font-size:13px">1. Departments</strong>
      </div>
      <form method="post" style="display:flex;gap:8px;margin-bottom:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_dept">
        <input name="dept_name" required placeholder="New Department Name (e.g. Agriculture, Fleet)" style="flex:2;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <input name="dept_code" placeholder="Code (e.g. AGRI)" style="width:90px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <button class="btn sm" style="white-space:nowrap">+ Add Department</button>
      </form>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($departments as $d): ?>
          <span style="display:inline-flex;align-items:center;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:3px 8px;font-size:12px;gap:6px">
            <strong><?= h($d['name']) ?></strong> <?= !empty($d['code']) ? '<span style="color:#64748b;font-size:10px">(' . h($d['code']) . ')</span>' : '' ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove department?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="del_master">
              <input type="hidden" name="type" value="dept">
              <input type="hidden" name="item_id" value="<?= $d['id'] ?>">
              <button style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0">✕</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 2. Sections Section -->
    <div style="margin-bottom:24px;border-top:1px solid #e2e8f0;padding-top:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="color:#059669;font-size:13px">2. Sections & Divisions</strong>
      </div>
      <form method="post" style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_sec">
        <select name="sec_dept_id" required style="width:160px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
          <option value="">Under Department...</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="sec_name" required placeholder="Section Name (e.g. Sector D, Central Garage)" style="flex:2;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <input name="sec_code" placeholder="Code (e.g. SEC-D)" style="width:90px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <button class="btn sm" style="white-space:nowrap">+ Add Section</button>
      </form>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($sections as $s): ?>
          <span style="display:inline-flex;align-items:center;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:3px 8px;font-size:12px;gap:6px">
            <span style="color:#047857;font-weight:600"><?= h($s['name']) ?></span>
            <span style="color:#64748b;font-size:10px"><?= h($s['dept_name'] ?? 'General') ?></span>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove section?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="del_master">
              <input type="hidden" name="type" value="sec">
              <input type="hidden" name="item_id" value="<?= $s['id'] ?>">
              <button style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0">✕</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 3. Designations Section -->
    <div style="border-top:1px solid #e2e8f0;padding-top:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="color:#7c3aed;font-size:13px">3. Designations & Job Titles</strong>
      </div>
      <form method="post" style="display:flex;gap:8px;margin-bottom:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_desig">
        <input name="desig_name" required placeholder="Designation Title (e.g. Field Officer, Agronomist)" style="flex:2;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <input name="desig_code" placeholder="Code (e.g. FO)" style="width:90px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <button class="btn sm" style="white-space:nowrap">+ Add Designation</button>
      </form>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($designations as $ds): ?>
          <span style="display:inline-flex;align-items:center;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:3px 8px;font-size:12px;gap:6px">
            <span style="color:#6d28d9;font-weight:600"><?= h($ds['name']) ?></span>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove designation?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="del_master">
              <input type="hidden" name="type" value="desig">
              <input type="hidden" name="item_id" value="<?= $ds['id'] ?>">
              <button style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0">✕</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="text-align:right;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:12px">
      <button type="button" class="btn ghost" onclick="document.getElementById('org-modal').style.display='none'">Close</button>
    </div>
  </div>
</div>

<script>
function filterSectionsByDept() {
  var deptId = document.getElementById('user-dept').value;
  var secSelect = document.getElementById('user-sec');
  var options = secSelect.getElementsByTagName('option');
  
  for (var i = 1; i < options.length; i++) {
    var opt = options[i];
    var optDept = opt.getAttribute('data-dept');
    if (!deptId || !optDept || optDept === deptId) {
      opt.style.display = '';
    } else {
      opt.style.display = 'none';
      if (opt.selected) secSelect.value = '';
    }
  }
}
</script>
<?php layout_end();
