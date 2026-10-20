<?php
require __DIR__ . '/_common.php';
$me = require_admin();
require_perm('users', 'view');

// Handle Web User CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'save_user') {
            $id = (int)($_POST['id'] ?? 0);
            require_perm('users', $id > 0 ? 'edit' : 'create');

            $name = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $role = trim($_POST['role'] ?? 'manager');
            $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $secId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
            $active = isset($_POST['active']) ? 1 : 0;
            $password = $_POST['password'] ?? '';

            if (empty($name)) throw new Exception("Full name is required.");
            if (empty($username)) throw new Exception("Username / Phone login is required.");

            // Check username uniqueness
            $dup = q("SELECT id FROM users WHERE (phone=? OR name=?) AND id != ?", [$username, $username, $id])->fetch();
            if ($dup) throw new Exception("Username '$username' is already taken by another account.");

            // Process permissions matrix
            $perms = [];
            if ($role === 'admin') {
                $perms = ['*' => true];
            } else {
                $modules = ['monitoring', 'dashboard', 'history', 'photos', 'units', 'geofences', 'reports', 'tasks', 'drivers', 'messages', 'notifications', 'staff', 'users', 'settings'];
                foreach ($modules as $mod) {
                    if (!empty($_POST['perm'][$mod])) {
                        $perms[$mod] = [];
                        foreach ($_POST['perm'][$mod] as $action => $val) {
                            if ($val === '1' || $val === 'on' || $val === true) {
                                $perms[$mod][$action] = true;
                            }
                        }
                    }
                }
            }
            $permsJson = json_encode($perms);

            if ($id > 0) {
                // Update existing user
                q("UPDATE users SET name=?, phone=?, role=?, web_access=1, department_id=?, section_id=?, active=?, permissions_json=? WHERE id=?",
                  [$name, $username, $role, $deptId, $secId, $active, $permsJson, $id]);

                if (!empty($password)) {
                    if (strlen($password) < 4) throw new Exception("Password must be at least 4 characters.");
                    q("UPDATE users SET password_hash=? WHERE id=?", [password_hash($password, PASSWORD_DEFAULT), $id]);
                    q("DELETE FROM api_tokens WHERE user_id=?", [$id]);
                }
                flash("Web user '{$name}' updated successfully.");
            } else {
                // Create new web user
                if (empty($password)) throw new Exception("Password is required for new accounts.");
                if (strlen($password) < 4) throw new Exception("Password must be at least 4 characters.");

                q("INSERT INTO users (name, code, phone, password_hash, role, web_access, unit_type, department_id, section_id, active, permissions_json)
                   VALUES (?,?,?,?,?,1,'staff',?,?,?,?)",
                  [$name, 'WEB-' . time(), $username, password_hash($password, PASSWORD_DEFAULT), $role, $deptId, $secId, $active, $permsJson]);

                flash("New web user '{$name}' created successfully with customized permissions.");
            }
        } elseif ($act === 'toggle_active') {
            require_perm('users', 'edit');
            $id = (int)$_POST['id'];
            if ($id === (int)$me['id']) throw new Exception("You cannot deactivate your own logged-in account.");
            q("UPDATE users SET active = 1 - active WHERE id = ?", [$id]);
            flash("User account status updated.");
        } elseif ($act === 'delete_user') {
            require_perm('users', 'delete');
            $id = (int)$_POST['id'];
            if ($id === (int)$me['id']) throw new Exception("You cannot delete your own logged-in account.");
            q("DELETE FROM users WHERE id = ?", [$id]);
            q("DELETE FROM api_tokens WHERE user_id = ?", [$id]);
            flash("User account permanently removed.");
        }
    } catch (Throwable $e) {
        flash("Error: " . $e->getMessage());
    }

    header('Location: users.php');
    exit;
}

// Fetch departments & sections for scoping
$departments = q("SELECT id, name FROM departments WHERE active=1 ORDER BY name ASC")->fetchAll();
$sections = q("SELECT id, department_id, name, code FROM sections WHERE active=1 ORDER BY name ASC")->fetchAll();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$sql = "SELECT u.*, dept.name as dept_name, sec.name as sec_name
        FROM users u
        LEFT JOIN departments dept ON dept.id = u.department_id
        LEFT JOIN sections sec ON sec.id = u.section_id
        WHERE (u.role != 'staff' OR u.web_access = 1)";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.phone LIKE ? OR u.code LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

if ($role_filter !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $role_filter;
}

if ($status_filter === 'active') {
    $sql .= " AND u.active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND u.active = 0";
}

$sql .= " ORDER BY (u.role='admin') DESC, u.name ASC";
$users = q($sql, $params)->fetchAll();

// KPI counts
$total_web_users = count($users);
$admin_count = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$active_count = count(array_filter($users, fn($u) => $u['active'] == 1));
$manager_count = count(array_filter($users, fn($u) => in_array($u['role'], ['manager', 'supervisor'])));

layout_start('Web User Management', 'users.php');
?>

<div style="padding:16px 20px">
  <!-- Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px;color:#263238">
        <span>👤</span> <span>Web User Management & Permissions (RBAC)</span>
      </h2>
      <div style="font-size:12px;color:#78909c;margin-top:2px">
        Manage system managers, division supervisors, and control operators with granular form and button restrictions
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if (can('users', 'create')): ?>
        <button class="btn" onclick="openUserModal()" style="display:inline-flex;align-items:center;gap:6px;background:#10b981;font-weight:700">
          <span>➕</span> <span>Add Web User</span>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPI Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:16px">
    <div class="card" style="padding:12px 16px;border-left:4px solid #10b981">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">Total Web Users</div>
      <div style="font-size:22px;font-weight:800;color:#0f172a;margin-top:2px"><?= $total_web_users ?></div>
      <div style="font-size:11px;color:#10b981;margin-top:2px">High-concurrency capacity (200+)</div>
    </div>
    <div class="card" style="padding:12px 16px;border-left:4px solid #6366f1">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">Super Administrators</div>
      <div style="font-size:22px;font-weight:800;color:#4f46e5;margin-top:2px"><?= $admin_count ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Unrestricted full-system access</div>
    </div>
    <div class="card" style="padding:12px 16px;border-left:4px solid #0284c7">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">Managers & Supervisors</div>
      <div style="font-size:22px;font-weight:800;color:#0369a1;margin-top:2px"><?= $manager_count ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Restricted form & button profiles</div>
    </div>
    <div class="card" style="padding:12px 16px;border-left:4px solid #f59e0b">
      <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase">Active Web Accounts</div>
      <div style="font-size:22px;font-weight:800;color:#d97706;margin-top:2px"><?= $active_count ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Authorized for Track360 Portal</div>
    </div>
  </div>

  <!-- Search & Filter Toolbar -->
  <div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0">
      <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search name, username, phone..." style="width:240px;font-size:12px;padding:6px 10px;height:32px">
      
      <select name="role" style="width:160px;font-size:12px;padding:4px 8px;height:32px" onchange="this.form.submit()">
        <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All Roles</option>
        <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Super Admin</option>
        <option value="manager" <?= $role_filter === 'manager' ? 'selected' : '' ?>>General Manager</option>
        <option value="supervisor" <?= $role_filter === 'supervisor' ? 'selected' : '' ?>>Field Supervisor</option>
        <option value="operator" <?= $role_filter === 'operator' ? 'selected' : '' ?>>Fleet Operator</option>
        <option value="viewer" <?= $role_filter === 'viewer' ? 'selected' : '' ?>>Auditor / Read-Only</option>
        <option value="custom" <?= $role_filter === 'custom' ? 'selected' : '' ?>>Custom Permissions</option>
      </select>

      <select name="status" style="width:130px;font-size:12px;padding:4px 8px;height:32px" onchange="this.form.submit()">
        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active Only</option>
        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
      </select>

      <button class="btn sm" type="submit" style="height:32px">Filter</button>
      <?php if ($search || $role_filter !== 'all' || $status_filter !== 'all'): ?>
        <a href="users.php" class="btn ghost sm" style="height:32px">Reset</a>
      <?php endif; ?>
    </form>
    <div style="font-size:12px;color:#78909c">
      Showing <strong><?= count($users) ?></strong> web accounts
    </div>
  </div>

  <!-- Users Table -->
  <div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;text-align:left;color:#475569">
          <th style="padding:10px 14px;width:35px">#</th>
          <th style="padding:10px 14px">User Profile & Login</th>
          <th style="padding:10px 14px">System Role</th>
          <th style="padding:10px 14px">Division / Department</th>
          <th style="padding:10px 14px">Granular Access Privileges</th>
          <th style="padding:10px 14px">Last Login</th>
          <th style="padding:10px 14px;text-align:center">Status</th>
          <th style="padding:10px 14px;text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $idx => $u): 
          $roleKey = $u['role'] ?? 'custom';
          $isSuper = ($roleKey === 'admin');
          $perms = !empty($u['permissions_json']) ? json_decode($u['permissions_json'], true) : [];
          $modCount = is_array($perms) ? count(array_filter($perms, fn($v) => !empty($v))) : 0;
          $roleBadge = match($roleKey) {
              'admin'      => ['background:#fdf2f8;color:#9d174d;border:1px solid #fbcfe8', '👑 SUPER ADMIN'],
              'manager'    => ['background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe', '👔 GENERAL MANAGER'],
              'supervisor' => ['background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0', '👷 FIELD SUPERVISOR'],
              'operator'   => ['background:#fffbeb;color:#92400e;border:1px solid #fde68a', '🚜 FLEET OPERATOR'],
              'viewer'     => ['background:#f1f5f9;color:#334155;border:1px solid #cbd5e1', '📋 READ-ONLY AUDITOR'],
              default      => ['background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe', '⚙️ CUSTOM ROLE'],
          };
        ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $u['active'] ? '' : 'opacity:0.55;background:#fafafa' ?>">
            <td style="padding:10px 14px;color:#94a3b8;font-size:11px"><?= $idx + 1 ?></td>
            <td style="padding:10px 14px">
              <div style="font-weight:700;color:#1e293b;font-size:13px;display:flex;align-items:center;gap:6px">
                <span><?= $isSuper ? '🛡️' : '👤' ?></span>
                <span><?= h($u['name']) ?></span>
                <?php if ((int)$u['id'] === (int)$me['id']): ?>
                  <span style="font-size:10px;background:#e2e8f0;color:#475569;padding:1px 5px;border-radius:4px">You</span>
                <?php endif; ?>
              </div>
              <div style="font-size:11px;color:#64748b;margin-top:2px;font-family:monospace">
                Login: <strong><?= h($u['phone']) ?></strong>
              </div>
            </td>
            <td style="padding:10px 14px">
              <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:10.5px;font-weight:700;<?= $roleBadge[0] ?>">
                <?= $roleBadge[1] ?>
              </span>
            </td>
            <td style="padding:10px 14px">
              <?php if (!empty($u['dept_name'])): ?>
                <div style="font-weight:600;color:#0369a1;font-size:11.5px">🏢 <?= h($u['dept_name']) ?></div>
                <?php if (!empty($u['sec_name'])): ?>
                  <div style="font-size:11px;color:#64748b">🌾 <?= h($u['sec_name']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">All Divisions (Global)</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
              <?php if ($isSuper): ?>
                <span style="color:#059669;font-weight:700;font-size:11.5px;display:inline-flex;align-items:center;gap:4px">
                  <span>✓</span> <span>Full Master Access (All Forms & Buttons)</span>
                </span>
              <?php else: ?>
                <div style="font-size:11.5px;color:#334155;font-weight:600">
                  <?= $modCount ?> Forms Allowed
                </div>
                <div style="font-size:10.5px;color:#64748b;margin-top:2px">
                  <?= empty($perms) ? 'No permissions assigned' : implode(', ', array_slice(array_keys($perms), 0, 4)) . ($modCount > 4 ? " + " . ($modCount - 4) . " more" : "") ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;color:#64748b;font-size:11.5px;white-space:nowrap">
              <?php if (!empty($u['last_login_at'])): ?>
                <div><?= h($u['last_login_at']) ?></div>
                <div style="font-size:10px;color:#94a3b8;font-family:monospace"><?= h($u['last_login_ip'] ?? '') ?></div>
              <?php else: ?>
                <span style="color:#94a3b8;font-style:italic">Never logged in</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <?php if (can('users', 'edit') && (int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="toggle_active">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" style="background:none;border:none;cursor:pointer" title="Toggle active status">
                    <?php if ($u['active']): ?>
                      <span style="background:#ecfdf5;color:#059669;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;border:1px solid #a7f3d0">Active</span>
                    <?php else: ?>
                      <span style="background:#fef2f2;color:#dc2626;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;border:1px solid #fecaca">Inactive</span>
                    <?php endif; ?>
                  </button>
                </form>
              <?php else: ?>
                <span style="background:#ecfdf5;color:#059669;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700">Active</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap">
              <?php if (can('users', 'edit')): ?>
                <button type="button" class="btn ghost sm" onclick='editUser(<?= json_encode($u) ?>)' style="padding:4px 8px;font-size:11px">
                  ✏️ Edit
                </button>
              <?php endif; ?>
              <?php if (can('users', 'delete') && (int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Permanently delete web user <?= htmlspecialchars($u['name']) ?>?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="delete_user">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn ghost sm" style="color:#dc2626;padding:4px 8px;font-size:11px">
                    🗑️
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="8" style="padding:32px;text-align:center;color:#94a3b8">
              No web user accounts found matching your filters.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add / Edit Web User Modal -->
<div id="user-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px;overflow-y:auto">
  <div style="background:#ffffff;border-radius:16px;width:100%;max-width:850px;box-shadow:0 25px 50px rgba(0,0,0,0.25);overflow:hidden;margin:auto">
    
    <!-- Modal Header -->
    <div style="padding:14px 20px;background:#1e293b;color:#f8fafc;display:flex;justify-content:space-between;align-items:center">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:18px">👤</span>
        <h3 id="modal-title" style="margin:0;font-size:15px;font-weight:700">Add New Web User</h3>
      </div>
      <button type="button" onclick="closeUserModal()" style="background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;line-height:1">&times;</button>
    </div>

    <!-- Modal Form -->
    <form method="post" id="user-form" style="padding:20px;max-height:82vh;overflow-y:auto;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_user">
      <input type="hidden" name="id" id="f-id" value="0">

      <!-- Profile Section -->
      <div style="font-weight:700;font-size:12.5px;color:#0f172a;margin-bottom:10px;text-transform:uppercase;letter-spacing:0.5px">
        1. User Identity & Authentication
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin-bottom:16px">
        <div>
          <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px">Full Name *</label>
          <input type="text" name="name" id="f-name" required placeholder="e.g. Sunil Weerasinghe" style="width:100%;padding:6px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px">
        </div>
        <div>
          <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px">Username / Phone Login *</label>
          <input type="text" name="username" id="f-username" required placeholder="e.g. sunil.m / 0771234567" style="width:100%;padding:6px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px">
        </div>
        <div>
          <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px">Password <span id="pwd-note" style="color:#64748b;font-weight:normal">(min 4 chars)</span></label>
          <input type="password" name="password" id="f-password" placeholder="Enter password" style="width:100%;padding:6px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin-bottom:20px">
        <div>
          <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px">System Role Preset *</label>
          <select name="role" id="f-role" onchange="applyRolePreset(this.value)" style="width:100%;padding:6px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px;font-weight:600">
            <option value="admin">👑 Super Administrator (Full Access to Everything)</option>
            <option value="manager" selected>👔 General Manager (Executive Views, Reports, Tasks)</option>
            <option value="supervisor">👷 Field Supervisor (Map, Tasks, Photos, Geofences)</option>
            <option value="operator">🚜 Fleet Operator (Units, Drivers, Machinery)</option>
            <option value="viewer">📋 Read-Only Auditor (View Analytics & Reports Only)</option>
            <option value="custom">⚙️ Custom Permissions (Choose Manually Below)</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px">Estate Division Scope</label>
          <select name="department_id" id="f-dept" style="width:100%;padding:6px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px">
            <option value="">Global / All Estate Divisions</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;align-items:center;margin-top:18px">
          <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#1e293b;cursor:pointer">
            <input type="checkbox" name="active" id="f-active" checked style="width:16px;height:16px;accent-color:#10b981">
            <span>Account Active (Can log in)</span>
          </label>
        </div>
      </div>

      <!-- Granular Permissions Matrix -->
      <div style="border-top:1px solid #e2e8f0;padding-top:14px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div>
            <div style="font-weight:700;font-size:12.5px;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px">
              2. Granular Form-wise & Button-wise Permissions Matrix
            </div>
            <div style="font-size:11px;color:#64748b">
              Control exactly which screens and buttons this user can view, create, edit, delete, or export
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <button type="button" class="btn sm ghost" onclick="toggleAllPerms(true)" style="padding:3px 8px;font-size:11px">Select All</button>
            <button type="button" class="btn sm ghost" onclick="toggleAllPerms(false)" style="padding:3px 8px;font-size:11px">Clear All</button>
          </div>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">
          <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
              <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;text-align:left">
                <th style="padding:8px 12px">Form / Screen Module</th>
                <th style="padding:8px 10px;text-align:center">View</th>
                <th style="padding:8px 10px;text-align:center">Add / Create</th>
                <th style="padding:8px 10px;text-align:center">Edit / Save</th>
                <th style="padding:8px 10px;text-align:center">Delete</th>
                <th style="padding:8px 10px;text-align:center">Special Action</th>
                <th style="padding:8px 10px;text-align:center">All for Form</th>
              </tr>
            </thead>
            <tbody id="perms-tbody">
              <?php
              $modulesMeta = [
                'monitoring'    => ['🛰️ Live Monitoring Map', ['view' => 'View Map', 'command' => 'Send Command']],
                'dashboard'     => ['📊 Telematics Dashboard', ['view' => 'View Dashboard']],
                'units'         => ['🚜 Fleet Units & Machinery', ['view' => 'View Units', 'create' => 'Add Unit', 'edit' => 'Edit Unit', 'delete' => 'Delete Unit', 'toggle' => 'Toggle Active']],
                'tasks'         => ['✓ Task Dispatching', ['view' => 'View Tasks', 'create' => 'Dispatch Task', 'edit' => 'Edit Task', 'delete' => 'Delete Task', 'status' => 'Change Status']],
                'geofences'     => ['⬡ Geofences & Zones', ['view' => 'View Zones', 'create' => 'Draw Zone', 'edit' => 'Edit Zone', 'delete' => 'Delete Zone']],
                'reports'       => ['📋 Telematics Reports', ['view' => 'Run Reports', 'export' => 'Export CSV/Excel']],
                'drivers'       => ['👨‍✈️ Driver Management', ['view' => 'View Drivers', 'create' => 'Add Driver', 'edit' => 'Edit Driver', 'delete' => 'Delete Driver']],
                'history'       => ['↝ Tracks & Playback', ['view' => 'Play Tracks', 'export' => 'Export KML']],
                'photos'        => ['📷 Field Photos Gallery', ['view' => 'View Photos', 'download' => 'Download Photos']],
                'messages'      => ['💬 Staff Messages', ['view' => 'Read Messages', 'send' => 'Send Message']],
                'notifications' => ['🔔 Alarms & SOS Alerts', ['view' => 'View Alarms', 'resolve' => 'Clear / Resolve SOS']],
                'staff'         => ['👥 Mobile Staff Directory', ['view' => 'View Staff', 'create' => 'Add Staff', 'edit' => 'Edit Staff', 'delete' => 'Delete Staff']],
                'users'         => ['👤 Web User Management', ['view' => 'View Users', 'create' => 'Add Web User', 'edit' => 'Edit Permissions', 'delete' => 'Delete User']],
                'settings'      => ['⚙️ System Configuration', ['view' => 'View Settings', 'save' => 'Save Settings']],
              ];

              foreach ($modulesMeta as $mKey => $mInfo): 
                $mTitle = $mInfo[0];
                $actions = $mInfo[1];
              ?>
                <tr style="border-bottom:1px solid #f1f5f9" class="perm-row" data-mod="<?= $mKey ?>">
                  <td style="padding:7px 12px;font-weight:600;color:#1e293b">
                    <?= $mTitle ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <?php if (isset($actions['view'])): ?>
                      <input type="checkbox" name="perm[<?= $mKey ?>][view]" value="1" class="perm-cb perm-<?= $mKey ?> perm-action-view" title="<?= $actions['view'] ?>">
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <?php if (isset($actions['create'])): ?>
                      <input type="checkbox" name="perm[<?= $mKey ?>][create]" value="1" class="perm-cb perm-<?= $mKey ?>" title="<?= $actions['create'] ?>">
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <?php if (isset($actions['edit'])): ?>
                      <input type="checkbox" name="perm[<?= $mKey ?>][edit]" value="1" class="perm-cb perm-<?= $mKey ?>" title="<?= $actions['edit'] ?>">
                    <?php elseif (isset($actions['save'])): ?>
                      <input type="checkbox" name="perm[<?= $mKey ?>][save]" value="1" class="perm-cb perm-<?= $mKey ?>" title="<?= $actions['save'] ?>">
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <?php if (isset($actions['delete'])): ?>
                      <input type="checkbox" name="perm[<?= $mKey ?>][delete]" value="1" class="perm-cb perm-<?= $mKey ?>" title="<?= $actions['delete'] ?>">
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <?php 
                    $specials = array_diff_key($actions, array_flip(['view', 'create', 'edit', 'delete', 'save']));
                    if (!empty($specials)): 
                      $spKey = array_key_first($specials);
                    ?>
                      <label style="font-size:10.5px;color:#0284c7;display:inline-flex;align-items:center;gap:3px;cursor:pointer" title="<?= $specials[$spKey] ?>">
                        <input type="checkbox" name="perm[<?= $mKey ?>][<?= $spKey ?>]" value="1" class="perm-cb perm-<?= $mKey ?>">
                        <span><?= ucfirst($spKey) ?></span>
                      </label>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;text-align:center">
                    <input type="checkbox" class="perm-row-all" onchange="toggleRowPerms('<?= $mKey ?>', this.checked)" title="Select all for <?= $mKey ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal Footer -->
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button type="button" class="btn ghost" onclick="closeUserModal()">Cancel</button>
        <button type="submit" class="btn" style="background:#10b981;font-weight:700">Save Web User</button>
      </div>
    </form>
  </div>
</div>

<script>
var modal = document.getElementById('user-modal');

function openUserModal() {
  document.getElementById('modal-title').textContent = 'Add New Web User';
  document.getElementById('f-id').value = '0';
  document.getElementById('f-name').value = '';
  document.getElementById('f-username').value = '';
  document.getElementById('f-password').value = '';
  document.getElementById('f-password').required = true;
  document.getElementById('pwd-note').textContent = '(min 4 chars)';
  document.getElementById('f-dept').value = '';
  document.getElementById('f-active').checked = true;
  document.getElementById('f-role').value = 'manager';
  applyRolePreset('manager');
  modal.style.display = 'flex';
}

function closeUserModal() {
  modal.style.display = 'none';
}

function editUser(u) {
  document.getElementById('modal-title').textContent = 'Edit Web User: ' + u.name;
  document.getElementById('f-id').value = u.id;
  document.getElementById('f-name').value = u.name;
  document.getElementById('f-username').value = u.phone;
  document.getElementById('f-password').value = '';
  document.getElementById('f-password').required = false;
  document.getElementById('pwd-note').textContent = '(Leave empty to keep existing password)';
  document.getElementById('f-dept').value = u.department_id || '';
  document.getElementById('f-active').checked = (u.active == 1);
  
  var role = u.role || 'custom';
  document.getElementById('f-role').value = role;

  toggleAllPerms(false);

  if (role === 'admin') {
    toggleAllPerms(true);
  } else if (u.permissions_json) {
    try {
      var perms = JSON.parse(u.permissions_json);
      for (var mod in perms) {
        if (typeof perms[mod] === 'object') {
          for (var act in perms[mod]) {
            var el = document.querySelector('input[name="perm[' + mod + '][' + act + ']"]');
            if (el) el.checked = true;
          }
        }
      }
    } catch(e) {}
  }
  updateRowAllCheckboxes();
  modal.style.display = 'flex';
}

function toggleAllPerms(checked) {
  var cbs = document.querySelectorAll('.perm-cb');
  cbs.forEach(function(cb) { cb.checked = checked; });
  var rowAlls = document.querySelectorAll('.perm-row-all');
  rowAlls.forEach(function(cb) { cb.checked = checked; });
}

function toggleRowPerms(mod, checked) {
  var cbs = document.querySelectorAll('.perm-' + mod);
  cbs.forEach(function(cb) { cb.checked = checked; });
}

function updateRowAllCheckboxes() {
  document.querySelectorAll('.perm-row').forEach(function(row) {
    var mod = row.getAttribute('data-mod');
    var cbs = row.querySelectorAll('.perm-cb');
    var allChecked = true;
    cbs.forEach(function(cb) { if (!cb.checked) allChecked = false; });
    var rowAll = row.querySelector('.perm-row-all');
    if (rowAll) rowAll.checked = (cbs.length > 0 && allChecked);
  });
}

function applyRolePreset(role) {
  toggleAllPerms(false);

  if (role === 'admin') {
    toggleAllPerms(true);
  } else if (role === 'manager') {
    // General Manager: View monitoring, dashboard, history, photos, fleet, geofences, reports (with export), tasks (all), drivers, messages, alarms
    setPermsFor('monitoring', ['view']);
    setPermsFor('dashboard', ['view']);
    setPermsFor('history', ['view', 'export']);
    setPermsFor('photos', ['view', 'download']);
    setPermsFor('units', ['view']);
    setPermsFor('geofences', ['view']);
    setPermsFor('reports', ['view', 'export']);
    setPermsFor('tasks', ['view', 'create', 'edit', 'status']);
    setPermsFor('drivers', ['view']);
    setPermsFor('messages', ['view', 'send']);
    setPermsFor('notifications', ['view', 'resolve']);
    setPermsFor('staff', ['view']);
  } else if (role === 'supervisor') {
    // Field Supervisor: Monitoring, Photos, Tasks (full), Geofences view, Fleet view, Messages
    setPermsFor('monitoring', ['view']);
    setPermsFor('photos', ['view', 'download']);
    setPermsFor('tasks', ['view', 'create', 'edit', 'status']);
    setPermsFor('geofences', ['view']);
    setPermsFor('units', ['view']);
    setPermsFor('messages', ['view', 'send']);
    setPermsFor('notifications', ['view', 'resolve']);
  } else if (role === 'operator') {
    // Fleet Operator: Units (all), Drivers (all), History, Monitoring
    setPermsFor('monitoring', ['view']);
    setPermsFor('units', ['view', 'create', 'edit', 'toggle']);
    setPermsFor('drivers', ['view', 'create', 'edit']);
    setPermsFor('history', ['view']);
  } else if (role === 'viewer') {
    // Auditor / Read-Only
    setPermsFor('monitoring', ['view']);
    setPermsFor('dashboard', ['view']);
    setPermsFor('history', ['view']);
    setPermsFor('photos', ['view']);
    setPermsFor('reports', ['view', 'export']);
    setPermsFor('units', ['view']);
    setPermsFor('geofences', ['view']);
  }
  updateRowAllCheckboxes();
}

function setPermsFor(mod, actions) {
  actions.forEach(function(act) {
    var el = document.querySelector('input[name="perm[' + mod + '][' + act + ']"]');
    if (el) el.checked = true;
  });
}

// Close modal when clicking outside
window.onclick = function(e) {
  if (e.target === modal) closeUserModal();
};
</script>

<?php layout_end(); ?>
