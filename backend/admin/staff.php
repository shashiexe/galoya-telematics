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

            // Handle profile photo upload
            $uploadedPhotoPath = null;
            if (!empty($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['profile_photo'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    global $CONFIG;
                    $upDir = __DIR__ . '/../' . ($CONFIG['upload_url'] ?? 'uploads') . '/profiles';
                    if (!is_dir($upDir)) @mkdir($upDir, 0755, true);
                    $fileName = 'profiles/profile_' . ($id ?: time()) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (@move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . ($CONFIG['upload_url'] ?? 'uploads') . '/' . $fileName)) {
                        $uploadedPhotoPath = $fileName;
                    }
                }
            }

            if ($id) {
                q('UPDATE users SET name=?, phone=?, role=?, department_id=?, section_id=?, designation_id=?, designation=? WHERE id=?', 
                  [$name, $phone, $role, $deptId, $secId, $desigId, $desigName, $id]);
                if ($uploadedPhotoPath) {
                    q('UPDATE users SET profile_photo=?, face_enrolled=1, face_enrolled_at=NOW() WHERE id=?', [$uploadedPhotoPath, $id]);
                }
                if (!empty($_POST['password'])) {
                    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                    q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
                }
                flash('Staff member updated successfully');
            } else {
                if (strlen($_POST['password'] ?? '') < 4) throw new Exception('Password must be at least 4 characters');
                q('INSERT INTO users (name,phone,password_hash,role,department_id,section_id,designation_id,designation,profile_photo,face_enrolled,face_enrolled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$name, $phone, password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $deptId, $secId, $desigId, $desigName, $uploadedPhotoPath, $uploadedPhotoPath ? 1 : 0, $uploadedPhotoPath ? date('Y-m-d H:i:s') : null]);
                flash('Staff member added successfully');
            }
        } elseif ($act === 'reset_face') {
            require_perm('staff', 'edit');
            $id = (int)($_POST['id'] ?? 0);
            $uRow = q('SELECT profile_photo FROM users WHERE id=?', [$id])->fetch();
            if ($uRow && !empty($uRow['profile_photo'])) {
                global $CONFIG;
                $upPath = __DIR__ . '/../' . ($CONFIG['upload_url'] ?? 'uploads') . '/' . ltrim($uRow['profile_photo'], '/');
                if (file_exists($upPath)) @unlink($upPath);
            }
            q('UPDATE users SET profile_photo=NULL, face_enrolled=0, face_enrolled_at=NULL WHERE id=?', [$id]);
            flash('Master Face ID successfully deleted & status reset to 0 (Pending). User will re-enroll on next app launch.');
            header('Location: staff.php' . (!empty($_GET['edit']) ? '?edit=' . (int)$_GET['edit'] : ''));
            exit;
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
$where[] = "(u.unit_type = 'staff' OR u.unit_type IS NULL OR u.unit_type = '') AND (u.role = 'staff' OR u.web_access = 0 OR u.web_access IS NULL) AND u.role NOT IN ('admin', 'super_user', 'facility', 'unit', 'device')";
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
   ORDER BY d.name, s.name, u.name", $params)->fetchAll();

layout_start('📱 App Users (Field Staff Officers)', 'staff.php');
?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div style="display:flex;align-items:center;gap:12px">
    <span style="font-size:26px">📱</span>
    <div>
      <strong style="color:#1e40af;font-size:14px;display:block">Mobile App Users (Field Staff Officers)</strong>
      <span style="font-size:12px;color:#3b82f6">Field officers who log into the GalOya Track360 Android app for plot inspections, GPS tracking & selfie attendance.</span>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:8px">
    <span style="font-size:12px;color:#64748b">Need to manage Web Portal logins?</span>
    <a href="users.php" class="btn sm" style="background:#475569;color:#fff;padding:5px 12px;font-size:11.5px;border-radius:4px;text-decoration:none;font-weight:600">💻 Switch to Web Users</a>
  </div>
</div>
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

  <div style="display:flex;align-items:center;gap:10px">
    <?php if (can('staff', 'create')): ?>
      <button type="button" class="btn sm" onclick="openStaffModal()" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;font-weight:700;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
        <span>➕</span> <span>Add Field Officer</span>
      </button>
    <?php endif; ?>
    <?php if (can('staff', 'edit')): ?>
      <button class="btn ghost sm" onclick="document.getElementById('org-modal').style.display='flex'">
        🏢 Manage Departments & Sections
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Full Width Staff Table Card -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.05)">
  <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-weight:700;font-size:14px;color:#1e293b">🌾 Mobile App Field Officers (<?= count($users) ?>)</span>
    </div>
    <span style="font-size:11.5px;color:#64748b">GPS Telematics & Face Verified Accounts</span>
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
              <div style="display:flex;align-items:center;gap:10px">
                <?php if (!empty($u['profile_photo'])): ?>
                  <div style="position:relative;width:38px;height:38px;border-radius:50%;overflow:hidden;border:2px solid #22c55e;flex-shrink:0" title="Master Face Enrolled 🟢">
                    <img src="<?= h(photo_url($u['profile_photo'])) ?>" style="width:100%;height:100%;object-fit:cover">
                  </div>
                <?php else: ?>
                  <div style="width:38px;height:38px;border-radius:50%;background:#f1f5f9;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:18px;flex-shrink:0" title="Face Not Enrolled Yet">
                    👤
                  </div>
                <?php endif; ?>
                <div>
                  <a href="javascript:void(0)" onclick='showStaffDetail(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="color:#0f172a;text-decoration:none;font-weight:700;display:block" title="Click to view full officer profile & live telematics">
                    <?= h($u['name']) ?>
                  </a>
                  <span style="font-size:11px;color:#64748b"><?= h($u['phone']) ?></span>
                  <?php if (!empty($u['profile_photo'])): ?>
                    <div style="display:flex;align-items:center;gap:6px">
                      <span style="font-size:10px;color:#16a34a;font-weight:700">✓ Face Active</span>
                      <form method="post" style="display:inline" onsubmit="return confirm('Delete Master Face ID for <?= h(addslashes($u['name'])) ?> and reset status to 0?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="reset_face">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:9.5px;font-weight:bold;text-decoration:underline;padding:0" title="Delete Master Photo and Reset Face Status to 0">Reset</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <span style="font-size:10px;color:#f59e0b;font-weight:600">Pending Face ID</span>
                  <?php endif; ?>
                </div>
              </div>
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
              <button type="button" class="btn sm" onclick='showStaffDetail(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="padding:4px 9px;font-size:11px;background:#0284c7;color:#fff;border:none;margin-right:4px;cursor:pointer;border-radius:4px" title="View Full Officer Details & Live Telematics">
                👁️ Details
              </button>
              <?php if (can('staff', 'edit')): ?>
                <button type="button" class="btn ghost sm" onclick='editStaff(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="padding:4px 8px;font-size:11px">
                  ✏️ Edit
                </button>
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

  <!-- Add / Edit Staff Centered Modal -->
  <?php if (can('staff', 'edit') || can('staff', 'create')): ?>
  <div id="staff-modal" style="display:<?= $edit ? 'flex' : 'none' ?>;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px;overflow-y:auto">
    <div style="background:#ffffff;border-radius:16px;width:100%;max-width:680px;box-shadow:0 25px 50px rgba(0,0,0,0.25);overflow:hidden;margin:auto">
      
      <!-- Modal Header -->
      <div style="padding:14px 20px;background:#1e293b;color:#f8fafc;display:flex;justify-content:space-between;align-items:center">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:18px">📱</span>
          <h3 id="staff-modal-title" style="margin:0;font-size:15px;font-weight:700"><?= $edit ? 'Edit Field Officer' : 'Add New Field Officer' ?></h3>
        </div>
        <button type="button" onclick="closeStaffModal()" style="background:none;border:none;color:#94a3b8;font-size:22px;cursor:pointer;line-height:1">&times;</button>
      </div>

      <!-- Modal Form -->
      <form method="post" enctype="multipart/form-data" id="staff-form" style="padding:20px;max-height:82vh;overflow-y:auto;margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" id="staff-f-id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <input type="hidden" name="role" value="staff">

        <!-- Name & Phone -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:12px;margin-bottom:14px">
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">Full Name *</label>
            <input name="name" id="staff-f-name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Sunil Perera" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">Phone / Login Mobile *</label>
            <input name="phone" id="staff-f-phone" required value="<?= h($edit['phone'] ?? '') ?>" placeholder="077xxxxxxx" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box">
          </div>
        </div>

        <!-- Designation -->
        <div style="margin-bottom:14px">
          <label style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">
            <span>Designation / Job Title</span>
            <span style="font-size:11px;color:#7c3aed;font-weight:normal">(Universal for all staff)</span>
          </label>
          <select name="designation_id" id="user-desig" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box">
            <option value="">Select Designation...</option>
            <?php foreach ($designations as $ds): ?>
              <option value="<?= $ds['id'] ?>" <?= ((int)($edit['designation_id'] ?? 0) === (int)$ds['id']) ? 'selected' : '' ?>><?= h($ds['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Section & Department (Auto-link) -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px">
            <div>
              <label style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">
                <span>Section / Division</span>
                <span style="font-size:10px;color:#0284c7;font-weight:700">⚡ Auto-selects Dept</span>
              </label>
              <select name="section_id" id="user-sec" onchange="onUserSecChange()" style="width:100%;padding:8px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box">
                <option value="">Select Section...</option>
                <?php foreach ($sections as $s): ?>
                  <option value="<?= $s['id'] ?>" data-dept="<?= (int)$s['department_id'] ?>" <?= ((int)($edit['section_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
                    <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">
                <span>Department</span>
                <span style="font-size:10px;color:#64748b;font-weight:500">Filters sections</span>
              </label>
              <select name="department_id" id="user-dept" onchange="onUserDeptChange()" style="width:100%;padding:8px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;transition:border-color .3s, background-color .3s">
                <option value="">Select Department...</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ((int)($edit['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?> (<?= h($d['code'] ?: 'Dept') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- App Account Info Banner -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:9px 12px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="font-size:20px">📱</span>
          <div>
            <div style="font-size:12px;font-weight:700;color:#166534">Mobile App Field Staff Account</div>
            <div style="font-size:11px;color:#15803d">Enables mobile app login, live GPS attendance, face verification & field tasks.</div>
          </div>
        </div>

        <!-- Master Face Photo Section -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#334155">
            👤 Master Profile Photo (For Face Verification)
          </label>
          <div id="staff-photo-preview" style="<?= !empty($edit['profile_photo']) ? 'display:flex' : 'display:none' ?>;align-items:center;gap:12px;margin-bottom:10px;padding:8px 10px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px">
            <img id="staff-preview-img" src="<?= !empty($edit['profile_photo']) ? h(photo_url($edit['profile_photo'])) : '' ?>" style="width:52px;height:52px;border-radius:8px;object-fit:cover;border:2px solid #22c55e">
            <div style="flex:1">
              <span style="font-size:11.5px;color:#16a34a;font-weight:700;display:block">✓ Master Face Active</span>
              <span id="staff-preview-date" style="font-size:10.5px;color:#64748b"><?= !empty($edit['face_enrolled_at']) ? 'Enrolled: ' . date('M d, Y', strtotime($edit['face_enrolled_at'])) : '' ?></span>
            </div>
            <?php if (!empty($edit['id'])): ?>
              <button type="button" onclick="if(confirm('Permanently delete this Master Face ID and reset enrollment status to 0?')) { document.getElementById('reset-face-form').submit(); }" style="padding:4px 8px;font-size:11px;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:6px;cursor:pointer;font-weight:700">
                🗑️ Reset Face
              </button>
            <?php endif; ?>
          </div>
          <input type="file" name="profile_photo" accept="image/*" style="font-size:12px;color:#475569;width:100%">
          <div style="font-size:10.5px;color:#64748b;margin-top:4px">Upload HR face photo used to verify field selfies & prevent proxy visits.</div>
        </div>

        <!-- Password -->
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155">
            Password <span id="staff-pwd-hint" style="font-weight:normal;color:#64748b"><?= $edit ? '(leave blank to keep unchanged)' : '*' ?></span>
          </label>
          <input name="password" id="staff-pwd" type="password" <?= $edit ? '' : 'required' ?> placeholder="••••••••" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box">
        </div>

        <!-- Modal Actions -->
        <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:12px;border-top:1px solid #e2e8f0">
          <button type="button" class="btn ghost" onclick="closeStaffModal()">Cancel</button>
          <button type="submit" class="btn" id="save-staff" style="background:#16a34a;color:#fff;font-weight:700;min-width:140px">
            <?= $edit ? 'Save Changes' : 'Create Field Officer' ?>
          </button>
        </div>
      </form>

      <!-- Hidden form for reset face -->
      <form id="reset-face-form" method="post" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="reset_face">
        <input type="hidden" name="id" id="reset-face-id" value="<?= (int)($edit['id'] ?? 0) ?>">
      </form>
    </div>
  </div>
  <?php endif; ?>

<!-- Officer Full Details & Live Telematics Modal -->
<div id="staff-detail-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:99999;align-items:center;justify-content:center;padding:16px;overflow-y:auto">
  <div style="background:#ffffff;border-radius:16px;width:100%;max-width:680px;box-shadow:0 25px 50px rgba(0,0,0,0.25);overflow:hidden;margin:auto">
    
    <!-- Modal Header -->
    <div style="padding:16px 20px;background:#1e293b;color:#f8fafc;display:flex;justify-content:space-between;align-items:center">
      <div style="display:flex;align-items:center;gap:14px">
        <div id="sd-avatar-wrap" style="position:relative;width:54px;height:54px;border-radius:50%;overflow:hidden;border:2.5px solid #22c55e;flex-shrink:0;background:#0f172a;display:flex;align-items:center;justify-content:center">
          <img id="sd-avatar-img" src="" style="width:100%;height:100%;object-fit:cover;display:none">
          <span id="sd-avatar-fallback" style="font-size:26px">👤</span>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <h3 id="sd-name" style="margin:0;font-size:16px;font-weight:800;color:#f8fafc">Officer Name</h3>
            <span id="sd-duty-badge" style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:12px;background:#10b981;color:#fff">ON DUTY</span>
          </div>
          <div style="font-size:12px;color:#94a3b8;margin-top:3px;display:flex;align-items:center;gap:8px">
            <span id="sd-desig" style="color:#cbd5e1;font-weight:600">Field Officer</span>
            <span>•</span>
            <span id="sd-phone" style="font-family:monospace;color:#38bdf8">077xxxxxxx</span>
          </div>
        </div>
      </div>
      <button type="button" onclick="closeStaffDetailModal()" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;line-height:1">&times;</button>
    </div>

    <!-- Modal Content Body -->
    <div style="padding:20px;max-height:80vh;overflow-y:auto">
      
      <!-- Live Telematics Banner -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:16px">
        <div style="font-size:11.5px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;gap:6px">
          <span>🛰️</span> <span>Real-Time GPS & Device Telematics</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:12px">
          <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="font-size:10.5px;color:#64748b">Last Signal</div>
            <div id="sd-last-seen" style="font-size:13px;font-weight:700;color:#1e293b;margin-top:2px">Just now</div>
          </div>
          <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="font-size:10.5px;color:#64748b">Battery Level</div>
            <div id="sd-battery" style="font-size:13px;font-weight:700;color:#16a34a;margin-top:2px">🔋 85%</div>
          </div>
          <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="font-size:10.5px;color:#64748b">Speed / Motion</div>
            <div id="sd-speed" style="font-size:13px;font-weight:700;color:#0284c7;margin-top:2px">0.0 km/h</div>
          </div>
          <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="font-size:10.5px;color:#64748b">App Data Usage</div>
            <div id="sd-data-usage" style="font-size:13px;font-weight:700;color:#7c3aed;margin-top:2px">0 MB</div>
          </div>
        </div>

        <div style="background:#fff;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0">
          <div style="font-size:10.5px;color:#64748b;margin-bottom:2px">Current GPS Location & Address</div>
          <div id="sd-address" style="font-size:12.5px;font-weight:600;color:#1e293b;line-height:1.4">Loading address...</div>
          <div id="sd-coords" style="font-size:10.5px;font-family:monospace;color:#94a3b8;margin-top:3px">Coords: 0.00000, 0.00000</div>
        </div>
      </div>

      <!-- Personnel & Division Details -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:8px">🏢 Division & Section</div>
          <div style="font-size:12.5px;color:#1e293b;font-weight:600" id="sd-dept">Department: —</div>
          <div style="font-size:12px;color:#475569;margin-top:4px" id="sd-sec">Section: —</div>
          <div style="font-size:11.5px;color:#059669;margin-top:6px;font-weight:600" id="sd-face-status">✓ Master Face Active</div>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:8px">📋 Field Tasks & Device</div>
          <div style="font-size:12.5px;color:#1e293b;font-weight:600" id="sd-tasks">0 Open Tasks · 0 Completed</div>
          <div style="font-size:12px;color:#475569;margin-top:4px" id="sd-device">Device: Android Phone</div>
          <div style="font-size:11.5px;color:#64748b;margin-top:6px" id="sd-enrolled-date">Enrolled: —</div>
        </div>
      </div>

      <!-- Quick Action Shortcuts -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding-top:16px">
        <a id="sd-btn-map" href="#" class="btn sm" style="background:#0284c7;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-weight:700">
          <span>🛰️</span> <span>Live Map</span>
        </a>
        <a id="sd-btn-history" href="#" class="btn sm" style="background:#475569;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-weight:700">
          <span>↝</span> <span>Route Track</span>
        </a>
        <a id="sd-btn-photos" href="#" class="btn sm" style="background:#059669;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-weight:700">
          <span>📷</span> <span>Field Photos</span>
        </a>
        <a id="sd-btn-call" href="#" class="btn ghost sm" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-weight:600">
          <span>📞</span> <span>Call Officer</span>
        </a>
        <button id="sd-btn-edit" type="button" class="btn ghost sm" style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-weight:600">
          <span>✏️</span> <span>Edit Profile</span>
        </button>
      </div>

    </div>
  </div>
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

function openStaffModal() {
  var modal = document.getElementById('staff-modal');
  if (!modal) return;
  modal.style.display = 'flex';
  document.getElementById('staff-modal-title').innerText = '➕ Add New Field Officer';
  document.getElementById('staff-form').reset();
  document.getElementById('staff-f-id').value = '0';
  var resetId = document.getElementById('reset-face-id');
  if (resetId) resetId.value = '0';
  document.getElementById('staff-pwd').required = true;
  document.getElementById('staff-pwd-hint').innerHTML = '<span style="color:#ef4444">*</span>';
  document.getElementById('save-staff').innerText = 'Create Field Officer';
  var photoPreview = document.getElementById('staff-photo-preview');
  if (photoPreview) photoPreview.style.display = 'none';
  updateSectionDropdown('', '');
}

function closeStaffModal() {
  var modal = document.getElementById('staff-modal');
  if (modal) modal.style.display = 'none';
  if (window.location.search.indexOf('edit=') !== -1) {
    history.replaceState(null, '', 'staff.php');
  }
}

function editStaff(u) {
  var modal = document.getElementById('staff-modal');
  if (!modal) return;
  modal.style.display = 'flex';
  document.getElementById('staff-modal-title').innerText = '✏️ Edit Field Officer: ' + u.name;
  document.getElementById('staff-f-id').value = u.id;
  var resetId = document.getElementById('reset-face-id');
  if (resetId) resetId.value = u.id;
  document.getElementById('staff-f-name').value = u.name || '';
  document.getElementById('staff-f-phone').value = u.phone || '';
  document.getElementById('user-desig').value = u.designation_id || '';
  document.getElementById('user-dept').value = u.department_id || '';
  updateSectionDropdown(u.department_id || '', u.section_id || '');

  var pwdInput = document.getElementById('staff-pwd');
  pwdInput.required = false;
  pwdInput.value = '';
  document.getElementById('staff-pwd-hint').innerHTML = '<span style="color:#64748b">(leave blank to keep unchanged)</span>';
  document.getElementById('save-staff').innerText = 'Save Changes';

  var photoPreview = document.getElementById('staff-photo-preview');
  if (photoPreview) {
    if (u.profile_photo) {
      photoPreview.style.display = 'flex';
      var img = document.getElementById('staff-preview-img');
      if (img) img.src = '../<?= $CONFIG['upload_url'] ?>/' + u.profile_photo.replace(/^\/+/, '');
      var dateSpan = document.getElementById('staff-preview-date');
      if (dateSpan) dateSpan.innerText = u.face_enrolled_at ? ('Enrolled: ' + u.face_enrolled_at.substring(0, 10)) : '';
    } else {
      photoPreview.style.display = 'none';
    }
  }
}

function showStaffDetail(u) {
  var modal = document.getElementById('staff-detail-modal');
  if (!modal) return;
  modal.style.display = 'flex';

  // Basic Info
  document.getElementById('sd-name').innerText = u.name || 'Officer';
  document.getElementById('sd-desig').innerText = u.desig_title || u.designation || 'Field Officer';
  document.getElementById('sd-phone').innerText = u.phone || '';
  
  // Avatar
  var img = document.getElementById('sd-avatar-img');
  var fallback = document.getElementById('sd-avatar-fallback');
  var wrap = document.getElementById('sd-avatar-wrap');
  if (u.profile_photo) {
    img.src = '../<?= $CONFIG['upload_url'] ?>/' + u.profile_photo.replace(/^\/+/, '');
    img.style.display = 'block';
    fallback.style.display = 'none';
    wrap.style.borderColor = '#22c55e';
  } else {
    img.style.display = 'none';
    fallback.style.display = 'block';
    wrap.style.borderColor = '#94a3b8';
  }

  // Duty Badge
  var dutyBadge = document.getElementById('sd-duty-badge');
  if (parseInt(u.on_duty) === 1) {
    dutyBadge.innerText = 'ON DUTY 🟢';
    dutyBadge.style.background = '#10b981';
  } else {
    dutyBadge.innerText = 'OFF DUTY ⚪';
    dutyBadge.style.background = '#64748b';
  }

  // Telematics
  var lastSeenEl = document.getElementById('sd-last-seen');
  lastSeenEl.innerText = u.last_seen ? u.last_seen.substring(0, 16) : 'No signal yet';

  var battEl = document.getElementById('sd-battery');
  battEl.innerText = (u.last_battery !== null && u.last_battery !== undefined && u.last_battery !== '') ? ('🔋 ' + u.last_battery + '%') : '🔋 N/A';

  var spdEl = document.getElementById('sd-speed');
  var spd = parseFloat(u.speed || 0);
  spdEl.innerText = spd > 0 ? (spd.toFixed(1) + ' km/h (Moving)') : '0.0 km/h (Stationary)';

  var dataUsageEl = document.getElementById('sd-data-usage');
  var gprs = parseInt(u.gprs_traffic_val || 0);
  dataUsageEl.innerText = gprs >= 1024 ? ((gprs / 1024).toFixed(2) + ' MB') : (gprs + ' KB');

  // Address & Coords
  var lat = parseFloat(u.last_lat || 0);
  var lng = parseFloat(u.last_lng || 0);
  var coordsEl = document.getElementById('sd-coords');
  var addrEl = document.getElementById('sd-address');
  if (lat && lng) {
    coordsEl.innerText = 'GPS: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    addrEl.innerText = 'Detecting location in Hingurana / Ampara...';
    fetch('index.php?geocode=1&lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        addrEl.innerText = (d && d.ok && d.address) ? d.address : (lat.toFixed(5) + ', ' + lng.toFixed(5));
      })
      .catch(function() {
        addrEl.innerText = 'Coordinates: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
      });
  } else {
    coordsEl.innerText = 'Coordinates: No GPS fix yet';
    addrEl.innerText = 'No recent GPS location broadcasted from mobile app.';
  }

  // Division / Section
  document.getElementById('sd-dept').innerText = 'Department: ' + (u.dept_name || 'None Assigned');
  document.getElementById('sd-sec').innerText = 'Section: ' + (u.sec_name ? (u.sec_name + (u.sec_code ? ' (' + u.sec_code + ')' : '')) : 'None Assigned');

  // Face status
  var faceStatus = document.getElementById('sd-face-status');
  if (u.profile_photo && parseInt(u.face_enrolled) === 1) {
    faceStatus.innerText = '✓ Master Face Enrolled & Active';
    faceStatus.style.color = '#16a34a';
  } else {
    faceStatus.innerText = '⚠️ Master Face ID Pending';
    faceStatus.style.color = '#f59e0b';
  }

  // Enrolled date
  document.getElementById('sd-enrolled-date').innerText = 'Enrolled: ' + (u.face_enrolled_at ? u.face_enrolled_at.substring(0, 10) : 'Pending');

  // Tasks
  document.getElementById('sd-tasks').innerText = (u.open || 0) + ' Open Tasks · ' + (u.done || 0) + ' Completed';
  document.getElementById('sd-device').innerText = 'Device: ' + (u.device_type || 'Android Mobile App');

  // Quick Action Buttons
  document.getElementById('sd-btn-map').href = 'index.php?focus=' + u.id;
  document.getElementById('sd-btn-history').href = 'history.php?user=' + u.id;
  document.getElementById('sd-btn-photos').href = 'photos.php?user=' + u.id;
  document.getElementById('sd-btn-call').href = 'tel:' + (u.phone || '');

  document.getElementById('sd-btn-edit').onclick = function() {
    closeStaffDetailModal();
    editStaff(u);
  };
}

function closeStaffDetailModal() {
  var modal = document.getElementById('staff-detail-modal');
  if (modal) modal.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
  var deptEl = document.getElementById('user-dept');
  var currentDept = deptEl ? deptEl.value : '';
  var currentSec = "<?= (int)($edit['section_id'] ?? 0) ?>";
  updateSectionDropdown(currentDept, currentSec);

  var staffModal = document.getElementById('staff-modal');
  if (staffModal) {
    staffModal.addEventListener('click', function(e) {
      if (e.target === this) {
        closeStaffModal();
      }
    });
  }

  var detailModal = document.getElementById('staff-detail-modal');
  if (detailModal) {
    detailModal.addEventListener('click', function(e) {
      if (e.target === this) {
        closeStaffDetailModal();
      }
    });
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeStaffModal();
    closeStaffDetailModal();
    var orgModal = document.getElementById('org-modal');
    if (orgModal) orgModal.style.display = 'none';
  }
});
</script>
<?php layout_end();
