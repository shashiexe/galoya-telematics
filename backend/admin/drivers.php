<?php
require __DIR__ . '/_common.php';
$me = require_admin();

// Handle Driver CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $license_no = trim($_POST['license_no'] ?? '');
            $rfid_code = trim($_POST['rfid_code'] ?? '');
            $assigned_unit_id = !empty($_POST['assigned_unit_id']) ? (int)$_POST['assigned_unit_id'] : null;

            if (empty($name)) {
                throw new Exception("Driver name is required");
            }

            if ($id > 0) {
                q("UPDATE drivers SET name=?, code=?, phone=?, license_no=?, rfid_code=?, assigned_unit_id=? WHERE id=?",
                  [$name, $code, $phone, $license_no, $rfid_code, $assigned_unit_id, $id]);
                flash("Driver '{$name}' updated successfully");
            } else {
                q("INSERT INTO drivers (name, code, phone, license_no, rfid_code, assigned_unit_id, active) VALUES (?,?,?,?,?,?,1)",
                  [$name, $code, $phone, $license_no, $rfid_code, $assigned_unit_id]);
                flash("New driver '{$name}' added to roster");
            }
        } elseif ($act === 'toggle_active') {
            $id = (int)$_POST['id'];
            q("UPDATE drivers SET active = 1 - active WHERE id = ?", [$id]);
            flash("Driver status updated");
        } elseif ($act === 'unbind_unit') {
            $id = (int)$_POST['id'];
            q("UPDATE drivers SET assigned_unit_id = NULL WHERE id = ?", [$id]);
            flash("Machine unassigned from driver");
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            q("DELETE FROM drivers WHERE id = ?", [$id]);
            flash("Driver removed from system");
        }
    } catch (Throwable $e) {
        flash("Error: " . $e->getMessage());
    }

    header('Location: drivers.php');
    exit;
}

// Fetch all available units (machines/staff) for assignment dropdown
$units = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY name ASC")->fetchAll();

// Fetch drivers with assigned unit info
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? 'all';

$sql = "SELECT d.*, u.name as unit_name, u.code as unit_code, u.unit_type 
        FROM drivers d 
        LEFT JOIN users u ON d.assigned_unit_id = u.id 
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (d.name LIKE ? OR d.code LIKE ? OR d.phone LIKE ? OR d.license_no LIKE ? OR d.rfid_code LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($filter_status === 'active') {
    $sql .= " AND d.active = 1";
} elseif ($filter_status === 'inactive') {
    $sql .= " AND d.active = 0";
} elseif ($filter_status === 'assigned') {
    $sql .= " AND d.assigned_unit_id IS NOT NULL";
} elseif ($filter_status === 'unassigned') {
    $sql .= " AND d.assigned_unit_id IS NULL";
}

$sql .= " ORDER BY d.active DESC, d.name ASC";
$drivers = q($sql, $params)->fetchAll();

// Driver to edit if requested
$edit_driver = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_driver = q("SELECT * FROM drivers WHERE id=?", [$edit_id])->fetch();
}

layout_start('Drivers Management', 'drivers.php');
?>

<div style="padding:16px 20px">
  <!-- Wialon Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px;color:#263238">
        <span>👨‍✈️</span> <span>Drivers Directory & Machine Assignments</span>
      </h2>
      <div style="font-size:12px;color:#78909c;margin-top:2px">
        Manage authorized machinery operators, RFID/iButton keys, and unit binding
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn" onclick="document.getElementById('driver-modal').style.display='flex'" style="display:flex;align-items:center;gap:6px;background:#0284c7">
        <span>➕</span> <span>New Driver</span>
      </button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:16px">
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #0284c7">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Total Operators</div>
      <div style="font-size:22px;font-weight:700;color:#263238;margin-top:4px"><?= count($drivers) ?></div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #2e7d32">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Assigned to Units</div>
      <div style="font-size:22px;font-weight:700;color:#2e7d32;margin-top:4px">
        <?= count(array_filter($drivers, fn($d) => !empty($d['assigned_unit_id']))) ?>
      </div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #f57c00">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">Available Pool</div>
      <div style="font-size:22px;font-weight:700;color:#f57c00;margin-top:4px">
        <?= count(array_filter($drivers, fn($d) => empty($d['assigned_unit_id']) && $d['active'])) ?>
      </div>
    </div>
    <div class="card" style="padding:12px 16px;background:#fff;border-left:4px solid #7c3aed">
      <div style="font-size:11px;color:#78909c;text-transform:uppercase;font-weight:700">RFID Keys Registered</div>
      <div style="font-size:22px;font-weight:700;color:#7c3aed;margin-top:4px">
        <?= count(array_filter($drivers, fn($d) => !empty($d['rfid_code']))) ?>
      </div>
    </div>
  </div>

  <!-- Filter & Search Toolbar -->
  <div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0">
      <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search name, code, RFID, phone..." style="width:260px;font-size:12px;padding:6px 10px;height:32px">
      <select name="status" style="width:160px;font-size:12px;padding:4px 8px;height:32px" onchange="this.form.submit()">
        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Drivers</option>
        <option value="assigned" <?= $filter_status === 'assigned' ? 'selected' : '' ?>>Assigned to Unit</option>
        <option value="unassigned" <?= $filter_status === 'unassigned' ? 'selected' : '' ?>>Unassigned Pool</option>
        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active Only</option>
        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
      </select>
      <button class="btn sm" type="submit" style="height:32px">Filter</button>
      <?php if ($search || $filter_status !== 'all'): ?>
        <a href="drivers.php" class="btn ghost sm" style="height:32px">Reset</a>
      <?php endif; ?>
    </form>
    <div style="font-size:12px;color:#78909c">
      Showing <strong><?= count($drivers) ?></strong> driver records
    </div>
  </div>

  <!-- Drivers Table -->
  <div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;text-align:left;color:#475569">
          <th style="padding:10px 14px;width:40px">#</th>
          <th style="padding:10px 14px">Driver Name</th>
          <th style="padding:10px 14px">Code</th>
          <th style="padding:10px 14px">Phone</th>
          <th style="padding:10px 14px">License No</th>
          <th style="padding:10px 14px">RFID / iButton Tag</th>
          <th style="padding:10px 14px">Assigned Machine / Unit</th>
          <th style="padding:10px 14px;text-align:center">Status</th>
          <th style="padding:10px 14px;text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($drivers)): ?>
          <tr>
            <td colspan="9" style="text-align:center;padding:30px;color:#94a3b8">No drivers found matching your filter criteria.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($drivers as $idx => $d): ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $d['active'] ? '' : 'opacity:0.55;background:#fafafa' ?>">
            <td style="padding:10px 14px;color:#94a3b8;font-size:11px"><?= $idx + 1 ?></td>
            <td style="padding:10px 14px">
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:32px;height:32px;border-radius:50%;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px">
                  <?= strtoupper(substr($d['name'], 0, 1)) ?>
                </div>
                <div>
                  <div style="font-weight:600;color:#1e293b"><?= h($d['name']) ?></div>
                  <div style="font-size:11px;color:#64748b">Operator ID: #<?= $d['id'] ?></div>
                </div>
              </div>
            </td>
            <td style="padding:10px 14px">
              <span style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:11px;font-weight:600">
                <?= h($d['code'] ?: '—') ?>
              </span>
            </td>
            <td style="padding:10px 14px;color:#334155">
              <?= h($d['phone'] ?: '—') ?>
            </td>
            <td style="padding:10px 14px;color:#334155">
              <span style="font-weight:500"><?= h($d['license_no'] ?: '—') ?></span>
            </td>
            <td style="padding:10px 14px">
              <?php if (!empty($d['rfid_code'])): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;background:#f3e8ff;color:#7e22ce;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600">
                  <span>🏷️</span> <?= h($d['rfid_code']) ?>
                </span>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11px">Not Paired</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
              <?php if (!empty($d['assigned_unit_id'])): ?>
                <div style="display:inline-flex;align-items:center;gap:6px;background:#e0f2fe;color:#0369a1;padding:4px 10px;border-radius:6px;font-weight:600;font-size:12px">
                  <span>🚜</span>
                  <span><?= h($d['unit_name']) ?></span>
                  <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Unbind machine from this driver?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="unbind_unit">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <button type="submit" title="Unbind Unit" style="background:none;border:none;cursor:pointer;color:#0284c7;font-size:12px;padding:0 2px">×</button>
                  </form>
                </div>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:11.5px;font-style:italic">Unassigned (Free Pool)</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:center">
              <form method="post" style="display:inline;margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="toggle_active">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button type="submit" style="background:none;border:none;cursor:pointer" title="Click to toggle status">
                  <?php if ($d['active']): ?>
                    <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">● Active</span>
                  <?php else: ?>
                    <span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700">○ Inactive</span>
                  <?php endif; ?>
                </button>
              </form>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap">
              <button class="btn ghost sm" onclick="editDriver(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)" style="padding:3px 8px;font-size:11.5px">Edit</button>
              <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Delete driver <?= h($d['name']) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn ghost sm red" style="padding:3px 8px;font-size:11.5px">Del</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Driver Modal Dialog -->
<div id="driver-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:480px;max-width:95vw;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden">
    <div style="background:#1e293b;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px">
        <span>👨‍✈️</span> <span id="modal-title">New Driver</span>
      </div>
      <button onclick="document.getElementById('driver-modal').style.display='none'" style="background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer">&times;</button>
    </div>
    
    <form method="post" style="padding:16px;margin:0" id="driver-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="driver_id" value="0">

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Full Name *</label>
        <input type="text" name="name" id="driver_name" required placeholder="e.g. A.B. Chandrasena" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Driver Code</label>
          <input type="text" name="code" id="driver_code" placeholder="e.g. DRV-101" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Mobile Phone</label>
          <input type="text" name="phone" id="driver_phone" placeholder="e.g. 0771234501" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Driving License No</label>
          <input type="text" name="license_no" id="driver_license" placeholder="e.g. B-7891234" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">RFID / iButton Key</label>
          <input type="text" name="rfid_code" id="driver_rfid" placeholder="e.g. RFID-8812" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#334155">Assign Machinery / Unit</label>
        <select name="assigned_unit_id" id="driver_unit" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px">
          <option value="">-- No Unit (Unassigned Pool) --</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>">🚜 <?= h($u['name']) ?> (<?= h($u['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0">
        <button type="button" class="btn ghost" onclick="document.getElementById('driver-modal').style.display='none'">Cancel</button>
        <button type="submit" class="btn" style="background:#0284c7">Save Driver</button>
      </div>
    </form>
  </div>
</div>

<script>
function editDriver(d) {
  document.getElementById('modal-title').textContent = 'Edit Driver: ' + d.name;
  document.getElementById('driver_id').value = d.id;
  document.getElementById('driver_name').value = d.name || '';
  document.getElementById('driver_code').value = d.code || '';
  document.getElementById('driver_phone').value = d.phone || '';
  document.getElementById('driver_license').value = d.license_no || '';
  document.getElementById('driver_rfid').value = d.rfid_code || '';
  document.getElementById('driver_unit').value = d.assigned_unit_id || '';
  document.getElementById('driver-modal').style.display = 'flex';
}
</script>

<?php layout_end(); ?>
