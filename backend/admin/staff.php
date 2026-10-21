<?php
require __DIR__ . '/_common.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            require_perm('staff', $id > 0 ? 'edit' : 'create');
            $name = trim($_POST['name']); 
            $phone = trim($_POST['phone']);
            $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $secId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
            $desigId = !empty($_POST['designation_id']) ? (int)$_POST['designation_id'] : null;
            
            // Auto-sync department_id from section if section was chosen
            if ($secId) {
                $pDept = q("SELECT department_id FROM sections WHERE id=?", [$secId])->fetchColumn();
                if ($pDept) {
                    $deptId = (int)$pDept;
                }
            }
            
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
            require_perm('staff', 'edit');
            $id = (int)$_POST['id'];
            if ($id !== (int)$me['id']) {
                q('UPDATE users SET active=1-active WHERE id=?', [$id]);
                q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
            }
            flash('Status changed');
        } elseif ($act === 'add_dept') {
            require_perm('staff', 'edit');
            $dName = trim($_POST['dept_name'] ?? '');
            $dCode = trim($_POST['dept_code'] ?? '');
            if ($dName) {
                q("INSERT IGNORE INTO departments (name, code) VALUES (?, ?)", [$dName, $dCode ?: null]);
                flash("Department '$dName' created");
            }
        } elseif ($act === 'add_sec') {
            require_perm('staff', 'edit');
            $sName = trim($_POST['sec_name'] ?? '');
            $sCode = trim($_POST['sec_code'] ?? '');
            $dId = !empty($_POST['sec_dept_id']) ? (int)$_POST['sec_dept_id'] : null;
            if ($sName) {
                q("INSERT IGNORE INTO sections (department_id, name, code) VALUES (?, ?, ?)", [$dId, $sName, $sCode ?: null]);
                flash("Section '$sName' created");
            }
        } elseif ($act === 'add_desig') {
            require_perm('staff', 'edit');
            $desName = trim($_POST['desig_name'] ?? '');
            $desCode = trim($_POST['desig_code'] ?? '');
            if ($desName) {
                q("INSERT IGNORE INTO designations (name, code) VALUES (?, ?)", [$desName, $desCode ?: null]);
                flash("Designation '$desName' created");
            }
        } elseif ($act === 'del_master') {
            require_perm('staff', 'delete');
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

// Filter staff list by user section scope
$filterDept = (int)($_GET['dept'] ?? 0);
$filterSec = (int)($_GET['sec'] ?? 0);
$where = [user_section_scope_sql('u')];
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
    <form method="get" id="staff-filter-form" style="display:inline-flex;gap:8px;align-items:center">
      <select name="dept" id="filter-dept" onchange="onFilterDeptChange()" style="padding:5px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="sec" id="filter-sec" onchange="onFilterSecChange()" style="padding:5px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
        <option value="">All Sections</option>
        <?php foreach ($sections as $s): ?>
          <?php if (!$filterDept || (int)$s['department_id'] === $filterDept): ?>
            <option value="<?= $s['id'] ?>" data-dept="<?= (int)$s['department_id'] ?>" <?= $filterSec === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?> (<?= h($s['dept_name'] ?? 'General') ?>)</option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <?php if ($filterDept || $filterSec): ?>
        <a href="staff.php" class="btn ghost sm">Clear Filter</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (can('staff', 'edit')): ?>
    <button class="btn ghost sm" onclick="document.getElementById('org-modal').style.display='flex'">
      🏢 Manage Departments & Sections
    </button>
  <?php endif; ?>
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
              <?php if (can('staff', 'edit')): ?>
                <a class="btn ghost sm" href="?edit=<?= $u['id'] ?>">Edit</a>
                <?php if ($u['id'] != $me['id']): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button class="btn sm <?= $u['active'] ? 'red' : '' ?>" style="padding:3px 8px;font-size:11px"><?= $u['active'] ? 'Disable' : 'Enable' ?></button></form>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">—</span>
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
  <?php if (($edit && can('staff', 'edit')) || (!$edit && can('staff', 'create'))): ?>
    <form method="post" class="card" id="staff-form" style="padding:18px">
      <h3 style="margin:0 0 14px 0;font-size:15px;color:#1e293b"><?= $edit ? 'Edit Staff / User' : 'Add New Staff Member' ?></h3>
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Full Name *</label>
      <input name="name" required value="<?= h($edit['name'] ?? '') ?>" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">

      <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">Phone / Login Mobile *</label>
      <input name="phone" required value="<?= h($edit['phone'] ?? '') ?>" placeholder="077xxxxxxx" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">

      <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">
        Designation / Job Title <span style="font-size:11px;color:#7c3aed;font-weight:normal">(Universal for all staff)</span>
      </label>
      <select name="designation_id" id="user-desig" style="width:100%;margin-bottom:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
        <option value="">Select Designation...</option>
        <?php foreach ($designations as $ds): ?>
          <option value="<?= $ds['id'] ?>" <?= ((int)($edit['designation_id'] ?? 0) === (int)$ds['id']) ? 'selected' : '' ?>><?= h($ds['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:12px">
        <label style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">
          <span>Section / Division</span>
          <span style="font-size:10.5px;color:#0284c7;font-weight:600">⚡ Auto-selects Department</span>
        </label>
        <select name="section_id" id="user-sec" onchange="onUserSecChange()" style="width:100%;margin-bottom:10px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
          <option value="">Select Section...</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= $s['id'] ?>" data-dept="<?= (int)$s['department_id'] ?>" <?= ((int)($edit['section_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
              <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569">
          <span>Department</span>
          <span style="font-size:10.5px;color:#64748b;font-weight:500">Filters sections</span>
        </label>
        <select name="department_id" id="user-dept" onchange="onUserDeptChange()" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box;transition:border-color .3s, background-color .3s">
          <option value="">Select Department...</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ((int)($edit['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?> (<?= h($d['code'] ?: 'Dept') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

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
  <?php else: ?>
    <div class="card" style="padding:28px 20px;text-align:center;color:#64748b;font-size:12px">
      <div style="font-size:32px;margin-bottom:8px">🛡️</div>
      <strong style="color:#1e293b;font-size:13.5px;display:block">Staff Management Restricted</strong>
      <div style="margin-top:6px;line-height:1.5">You have read-only permissions for staff directory.<br>Please contact an Administrator to modify personnel profiles.</div>
    </div>
  <?php endif; ?>
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
        <strong style="color:#059669;font-size:13px">2. Sections & Divisions (Department-Wise)</strong>
      </div>
      <form method="post" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_sec">
        <select name="sec_dept_id" required style="width:180px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
          <option value="">Under Department... *</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="sec_name" required placeholder="Section Name (e.g. Milling, Boiler, Sector D)" style="flex:2;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <input name="sec_code" placeholder="Code (e.g. MILL, BLR)" style="width:90px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
        <button class="btn sm" style="white-space:nowrap">+ Add Section</button>
      </form>
      
      <?php
      $sectionsByDept = [];
      foreach ($sections as $s) {
          $dId = (int)($s['department_id'] ?? 0);
          $sectionsByDept[$dId][] = $s;
      }
      ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($departments as $d): 
          $deptSecs = $sectionsByDept[(int)$d['id']] ?? [];
        ?>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
              <span style="font-size:12px;font-weight:700;color:#0f172a">🏢 <?= h($d['name']) ?></span>
              <span style="font-size:10.5px;color:#64748b"><?= count($deptSecs) ?> section<?= count($deptSecs) === 1 ? '' : 's' ?></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              <?php if (empty($deptSecs)): ?>
                <span style="font-size:11px;color:#94a3b8;font-style:italic">No sections in this department yet.</span>
              <?php else: ?>
                <?php foreach ($deptSecs as $s): ?>
                  <span style="display:inline-flex;align-items:center;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:3px 8px;font-size:12px;gap:6px">
                    <span style="color:#047857;font-weight:600"><?= h($s['name']) ?></span>
                    <?= !empty($s['code']) ? '<span style="color:#64748b;font-size:10px">(' . h($s['code']) . ')</span>' : '' ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remove section <?= h($s['name']) ?>?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="del_master">
                      <input type="hidden" name="type" value="sec">
                      <input type="hidden" name="item_id" value="<?= $s['id'] ?>">
                      <button style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0;line-height:1" title="Delete section">✕</button>
                    </form>
                  </span>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        
        <?php if (!empty($sectionsByDept[0])): ?>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px">
            <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px">General / Unassigned</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              <?php foreach ($sectionsByDept[0] as $s): ?>
                <span style="display:inline-flex;align-items:center;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:3px 8px;font-size:12px;gap:6px">
                  <span><?= h($s['name']) ?></span>
                  <form method="post" style="display:inline" onsubmit="return confirm('Remove section?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="del_master">
                    <input type="hidden" name="type" value="sec">
                    <input type="hidden" name="item_id" value="<?= $s['id'] ?>">
                    <button style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0;line-height:1">✕</button>
                  </form>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
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
var allSections = <?= json_encode(array_values(array_map(function($s) {
  return [
    'id' => (int)$s['id'],
    'name' => $s['name'],
    'code' => $s['code'] ?? '',
    'dept_id' => (int)($s['department_id'] ?? 0),
    'dept_name' => $s['dept_name'] ?? 'General'
  ];
}, $sections))) ?>;

function updateSectionDropdown(deptId, selectedSecId) {
  var secSelect = document.getElementById('user-sec');
  if (!secSelect) return;
  
  var previousVal = (selectedSecId !== undefined && selectedSecId !== null && selectedSecId !== '') 
    ? String(selectedSecId) 
    : secSelect.value;
    
  secSelect.innerHTML = '<option value="">Select Section...</option>';
  
  var filtered = deptId 
    ? allSections.filter(function(s) { return String(s.dept_id) === String(deptId); })
    : allSections;
    
  filtered.forEach(function(s) {
    var opt = document.createElement('option');
    opt.value = s.id;
    opt.setAttribute('data-dept', s.dept_id);
    var label = s.name + (s.code ? ' (' + s.code + ')' : '');
    if (!deptId && s.dept_name) {
      label += ' [' + s.dept_name + ']';
    }
    opt.textContent = label;
    if (previousVal && String(s.id) === previousVal) {
      opt.selected = true;
    }
    secSelect.appendChild(opt);
  });
}

function onUserSecChange() {
  var secSelect = document.getElementById('user-sec');
  if (!secSelect) return;
  var secId = secSelect.value;
  if (!secId) return;
  
  var sec = allSections.find(function(s) { return String(s.id) === String(secId); });
  if (sec && sec.dept_id) {
    var deptSelect = document.getElementById('user-dept');
    if (deptSelect) {
      deptSelect.value = sec.dept_id;
      // Filter section list to this department, keeping current section selected
      updateSectionDropdown(sec.dept_id, secId);
      
      // Visual feedback highlight
      deptSelect.style.borderColor = '#10b981';
      deptSelect.style.backgroundColor = '#ecfdf5';
      setTimeout(function() {
        deptSelect.style.borderColor = '#cbd5e1';
        deptSelect.style.backgroundColor = '';
      }, 1000);
    }
  }
}

function onUserDeptChange() {
  var deptSelect = document.getElementById('user-dept');
  if (!deptSelect) return;
  var deptId = deptSelect.value;
  var secSelect = document.getElementById('user-sec');
  var currentSecId = secSelect ? secSelect.value : '';
  
  var stillValid = allSections.some(function(s) { 
    return String(s.id) === String(currentSecId) && String(s.dept_id) === String(deptId); 
  });
  updateSectionDropdown(deptId, stillValid ? currentSecId : '');
}

function onFilterDeptChange() {
  var secSelect = document.getElementById('filter-sec');
  if (secSelect) secSelect.value = '';
  document.getElementById('staff-filter-form').submit();
}

function onFilterSecChange() {
  var secSelect = document.getElementById('filter-sec');
  if (!secSelect) return;
  var secId = secSelect.value;
  if (secId) {
    var found = allSections.find(function(s) { return String(s.id) === String(secId); });
    if (found && found.dept_id) {
      var filterDept = document.getElementById('filter-dept');
      if (filterDept) filterDept.value = found.dept_id;
    }
  }
  document.getElementById('staff-filter-form').submit();
}

document.addEventListener('DOMContentLoaded', function() {
  var deptEl = document.getElementById('user-dept');
  var currentDept = deptEl ? deptEl.value : '';
  var currentSec = "<?= (int)($edit['section_id'] ?? 0) ?>";
  updateSectionDropdown(currentDept, currentSec);
});
</script>
<?php layout_end();
