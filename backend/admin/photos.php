<?php
require __DIR__ . '/_common.php';
$me = require_admin();
require_perm('photos', 'view');

// -----------------------------------------------------------------------------
// Handle Photo Deletion with Super Admin Audit Logging
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete_photo') {
    check_csrf();
    require_perm('photos', 'delete');
    $photoId = (int)($_POST['id'] ?? 0);
    $photoType = $_POST['photo_type'] ?? 'field';
    $reason = trim($_POST['reason'] ?? 'Manual removal by Admin');

    if ($photoId > 0) {
        $table = ($photoType === 'task') ? 'task_photos' : 'field_photos';
        
        // Fetch record and officer details before deletion
        $row = null;
        try {
            $row = q("SELECT p.*, u.name as officer_name, u.code as officer_code 
                      FROM $table p 
                      LEFT JOIN users u ON u.id = p.user_id 
                      WHERE p.id = ?", [$photoId])->fetch();
        } catch (Throwable $e) {}

        if ($row) {
            // Ensure Audit Log Table Exists
            try {
                q("CREATE TABLE IF NOT EXISTS photo_delete_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    photo_id INT NULL,
                    photo_type VARCHAR(32) DEFAULT 'field',
                    file_path VARCHAR(255) NULL,
                    officer_name VARCHAR(128) NULL,
                    officer_code VARCHAR(64) NULL,
                    plot_name VARCHAR(128) NULL,
                    taken_at DATETIME NULL,
                    note TEXT NULL,
                    deleted_by_name VARCHAR(128) NOT NULL,
                    deleted_by_id INT NOT NULL,
                    deleted_at DATETIME NOT NULL,
                    ip_address VARCHAR(64) NULL,
                    reason VARCHAR(255) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $plotName = $row['selected_geofence'] ?? ($row['auto_geofence'] ?? ($row['geofence_name'] ?? ''));
                q("INSERT INTO photo_delete_logs (photo_id, photo_type, file_path, officer_name, officer_code, plot_name, taken_at, note, deleted_by_name, deleted_by_id, deleted_at, ip_address, reason)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
                  [
                      $photoId,
                      $photoType,
                      $row['file_path'],
                      $row['officer_name'] ?? ('Officer #' . ($row['user_id'] ?? 0)),
                      $row['officer_code'] ?? '',
                      $plotName,
                      $row['taken_at'] ?? null,
                      $row['note'] ?? '',
                      $me['name'] ?? 'Super Admin',
                      $me['id'] ?? 0,
                      $_SERVER['REMOTE_ADDR'] ?? '',
                      $reason ?: 'Manual deletion by Admin'
                  ]);
            } catch (Throwable $e) {}

            // Delete record from DB
            q("DELETE FROM $table WHERE id = ?", [$photoId]);

            // Remove physical file from uploads directory
            $uploadDir = __DIR__ . '/../' . ($CONFIG['upload_url'] ?? 'uploads');
            $filePath = $uploadDir . '/' . $row['file_path'];
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }

            flash("Photo #{$photoId} permanently removed and logged in Deletion Audit Log.");
        }
    }
    $qs = http_build_query(array_intersect_key($_GET, array_flip(['user', 'type', 'from', 'to', 'view'])));
    header('Location: photos.php' . ($qs ? "?$qs" : ''));
    exit;
}

// -----------------------------------------------------------------------------
// Parameters & Filter Setup
// -----------------------------------------------------------------------------
$staff = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY (code IS NOT NULL) DESC, name ASC")->fetchAll();

$uid = isset($_GET['user']) && $_GET['user'] !== '' ? (int)$_GET['user'] : null;
$from_dt = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to_dt = $_GET['to'] ?? date('Y-m-d 23:59:59');
$type = $_GET['type'] ?? 'all'; // all, field, task
$view_mode = $_GET['view'] ?? 'officer'; // 'officer' (default) or 'grid'

// Fetch Deletion Audit Log Count
$auditCount = 0;
$auditLogs = [];
try {
    q("CREATE TABLE IF NOT EXISTS photo_delete_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        photo_id INT NULL,
        photo_type VARCHAR(32) DEFAULT 'field',
        file_path VARCHAR(255) NULL,
        officer_name VARCHAR(128) NULL,
        officer_code VARCHAR(64) NULL,
        plot_name VARCHAR(128) NULL,
        taken_at DATETIME NULL,
        note TEXT NULL,
        deleted_by_name VARCHAR(128) NOT NULL,
        deleted_by_id INT NOT NULL,
        deleted_at DATETIME NOT NULL,
        ip_address VARCHAR(64) NULL,
        reason VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $auditCount = (int)q("SELECT COUNT(*) FROM photo_delete_logs")->fetchColumn();
    $auditLogs = q("SELECT * FROM photo_delete_logs ORDER BY id DESC LIMIT 50")->fetchAll();
} catch (Throwable $e) {}

// Build queries for field_photos and task_photos
$params1 = [$from_dt, $to_dt];
$params2 = [$from_dt, $to_dt];

$sql1 = "SELECT fp.id, fp.user_id, fp.file_path, fp.lat, fp.lng, fp.note, fp.geofence_name, 
                COALESCE(fp.activity_type, 'Field Photo') as activity_type,
                fp.auto_geofence, fp.selected_geofence,
                fp.taken_at, 'field' as photo_type, 
                u.name as user_name, u.code as user_code, u.unit_type,
                d.name as dept_name, s.name as sec_name
         FROM field_photos fp
         JOIN users u ON u.id = fp.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN sections s ON s.id = u.section_id
         WHERE fp.taken_at BETWEEN ? AND ?";

$sql2 = "SELECT tp.id, tp.user_id, tp.file_path, tp.lat, tp.lng, 
                CONCAT('Task #', tp.task_id, ' (', tp.type, ')', IF(t.title IS NOT NULL, CONCAT(' - ', t.title), '')) as note, 
                NULL as geofence_name,
                'Task Work' as activity_type,
                NULL as auto_geofence, NULL as selected_geofence,
                tp.taken_at, 'task' as photo_type,
                u.name as user_name, u.code as user_code, u.unit_type,
                d.name as dept_name, s.name as sec_name
         FROM task_photos tp
         JOIN users u ON u.id = tp.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN sections s ON s.id = u.section_id
         LEFT JOIN tasks t ON t.id = tp.task_id
         WHERE tp.taken_at BETWEEN ? AND ?";

if ($uid) {
    $sql1 .= " AND fp.user_id = ?";
    $params1[] = $uid;
    $sql2 .= " AND tp.user_id = ?";
    $params2[] = $uid;
}

$photos = [];
if ($type === 'all' || $type === 'field') {
    try {
        $rows1 = q($sql1, $params1)->fetchAll();
        $photos = array_merge($photos, $rows1);
    } catch (Throwable $e) {}
}
if ($type === 'all' || $type === 'task') {
    try {
        $rows2 = q($sql2, $params2)->fetchAll();
        $photos = array_merge($photos, $rows2);
    } catch (Throwable $e) {}
}

// Sort all photos chronologically descending
usort($photos, function($a, $b) {
    return strcmp($b['taken_at'], $a['taken_at']);
});

// Group photos by Field Officer
$officerGroups = [];
foreach ($photos as $p) {
    $uId = (int)$p['user_id'];
    if (!isset($officerGroups[$uId])) {
        $officerGroups[$uId] = [
            'user_id' => $uId,
            'name' => $p['user_name'] ?: 'Officer #' . $uId,
            'code' => $p['user_code'] ?? '',
            'unit_type' => $p['unit_type'] ?? '',
            'dept_name' => $p['dept_name'] ?? '',
            'sec_name' => $p['sec_name'] ?? '',
            'first_time' => $p['taken_at'],
            'last_time' => $p['taken_at'],
            'photos' => [],
            'plots' => []
        ];
    }
    
    // Track earliest and latest visit times
    if (strtotime($p['taken_at']) < strtotime($officerGroups[$uId]['first_time'])) {
        $officerGroups[$uId]['first_time'] = $p['taken_at'];
    }
    if (strtotime($p['taken_at']) > strtotime($officerGroups[$uId]['last_time'])) {
        $officerGroups[$uId]['last_time'] = $p['taken_at'];
    }

    $plot = trim((string)($p['selected_geofence'] ?: ($p['auto_geofence'] ?: ($p['geofence_name'] ?: ''))));
    if ($plot !== '') {
        $officerGroups[$uId]['plots'][$plot] = true;
    }

    $officerGroups[$uId]['photos'][] = $p;
}

layout_start('Photos & Field Media', 'photos.php');
?>
<div style="padding:16px 20px;max-width:1440px;margin:0 auto">
  
  <!-- Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;color:#1e293b;display:flex;align-items:center;gap:8px">
        <span>📷</span> Field Officer Photos & Inspection Gallery
      </h2>
      <div style="font-size:12px;color:#64748b;margin-top:2px">
        Photos taken by mobile apps, categorized field officer-wise with plot verification and GPS telemetry.
      </div>
    </div>
    
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <!-- Total Photos Badge -->
      <div style="font-size:12.5px;color:#0284c7;font-weight:700;background:#f0f9ff;border:1px solid #bae6fd;padding:5px 12px;border-radius:20px">
        📷 Total: <?= count($photos) ?> Photos
      </div>
      <!-- Active Officers Badge -->
      <div style="font-size:12.5px;color:#16a34a;font-weight:700;background:#f0fdf4;border:1px solid #bbf7d0;padding:5px 12px;border-radius:20px">
        👨‍🌾 <?= count($officerGroups) ?> Active Staff
      </div>
      <!-- View Switcher -->
      <div style="display:inline-flex;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;padding:2px">
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'officer'])) ?>" 
           style="padding:4px 10px;font-size:11.5px;font-weight:700;text-decoration:none;border-radius:4px;color:<?= $view_mode === 'officer' ? '#fff' : '#64748b' ?>;background:<?= $view_mode === 'officer' ? '#0284c7' : 'transparent' ?>">
          👥 Officer-Wise
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'grid'])) ?>" 
           style="padding:4px 10px;font-size:11.5px;font-weight:700;text-decoration:none;border-radius:4px;color:<?= $view_mode === 'grid' ? '#fff' : '#64748b' ?>;background:<?= $view_mode === 'grid' ? '#0284c7' : 'transparent' ?>">
          ▦ All Grid
        </a>
      </div>
      <!-- Deletion Audit Log Button -->
      <?php if (can('photos', 'delete')): ?>
        <button type="button" onclick="openAuditLogModal()" class="btn ghost" style="padding:5px 12px;font-size:12px;font-weight:700;border:1px solid #fca5a5;color:#dc2626;background:#fff;border-radius:6px;display:inline-flex;align-items:center;gap:6px">
          📜 Audit Log (<?= $auditCount ?>)
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Compact Filter Bar -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin-bottom:14px;box-shadow:0 1px 2px rgba(0,0,0,0.03)">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <input type="hidden" name="view" value="<?= h($view_mode) ?>">
      
      <!-- Unit Selection -->
      <div style="flex:1;min-width:180px">
        <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:700;text-transform:uppercase">Field Officer / Unit:</label>
        <select name="user" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
          <option value="">All Field Officers</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $uid === (int)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Type Filter -->
      <div style="min-width:130px">
        <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:700;text-transform:uppercase">Category:</label>
        <select name="type" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
          <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Photos</option>
          <option value="field" <?= $type === 'field' ? 'selected' : '' ?>>Field Snaps 📷</option>
          <option value="task" <?= $type === 'task' ? 'selected' : '' ?>>Task Verification ✓</option>
        </select>
      </div>

      <!-- Date Presets -->
      <div style="min-width:160px">
        <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:700;text-transform:uppercase">From Date:</label>
        <input type="datetime-local" name="from" value="<?= date('Y-m-d\TH:i', strtotime($from_dt)) ?>" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
      </div>

      <div style="min-width:160px">
        <label style="font-size:11px;color:#475569;display:block;margin-bottom:3px;font-weight:700;text-transform:uppercase">To Date:</label>
        <input type="datetime-local" name="to" value="<?= date('Y-m-d\TH:i', strtotime($to_dt)) ?>" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
      </div>

      <div>
        <button type="submit" class="w-icon-btn primary" style="padding:6px 16px;font-weight:700;font-size:12px;border-radius:4px">
          🔍 Filter
        </button>
      </div>
    </form>
  </div>

  <!-- Empty State -->
  <?php if (empty($photos)): ?>
    <div style="background:#fff;border:1px dashed #cbd5e1;border-radius:8px;padding:48px 20px;text-align:center;color:#64748b">
      <div style="font-size:48px;margin-bottom:12px">📷</div>
      <h3 style="margin:0 0 6px;color:#334155">No field photos found</h3>
      <p style="margin:0;font-size:13px">When field officers take crop photos using the Galoya mobile app, they will automatically appear here grouped officer-wise with plot verification.</p>
    </div>

  <?php elseif ($view_mode === 'officer'): ?>
    <!-- ========================================================================= -->
    <!-- 👥 OFFICER-WISE VIEW (GROUPED BY FIELD OFFICER WITH ~10 THUMBS PER ROW) -->
    <!-- ========================================================================= -->
    <?php foreach ($officerGroups as $uId => $grp): ?>
      <div class="card" style="margin-bottom:14px;padding:0;overflow:hidden;border:1px solid #cbd5e1;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
        
        <!-- Officer Header Bar -->
        <div style="padding:9px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:18px">👨‍🌾</span>
            <div>
              <strong style="font-size:13.5px;color:#0f172a"><?= h($grp['name']) ?></strong>
              <?php if (!empty($grp['code'])): ?>
                <span style="font-size:11px;color:#64748b;margin-left:4px">Code: <code><?= h($grp['code']) ?></code></span>
              <?php endif; ?>
              <?php if (!empty($grp['sec_name'])): ?>
                <span style="font-size:11px;background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:10px;font-weight:600;margin-left:6px"><?= h($grp['sec_name']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Summary Badges on Right -->
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="font-size:11.5px;font-weight:700;color:#0284c7;background:#f0f9ff;border:1px solid #bae6fd;padding:2px 8px;border-radius:10px">
              📷 <?= count($grp['photos']) ?> Photos
            </span>
            <?php if (!empty($grp['first_time']) && !empty($grp['last_time'])): ?>
              <span style="font-size:11px;color:#475569">
                🕒 <?= date('h:i A', strtotime($grp['first_time'])) ?> – <?= date('h:i A', strtotime($grp['last_time'])) ?>
              </span>
            <?php endif; ?>
            <!-- Plots Inspected -->
            <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">
              <?php 
              $pPlots = array_keys($grp['plots']);
              foreach (array_slice($pPlots, 0, 4) as $pTag): ?>
                <span style="font-size:10.5px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-weight:700;padding:1px 6px;border-radius:4px">
                  🌾 <?= h($pTag) ?>
                </span>
              <?php endforeach; ?>
              <?php if (count($pPlots) > 4): ?>
                <span style="font-size:10px;color:#64748b;font-weight:600">+<?= count($pPlots) - 4 ?> more</span>
              <?php endif; ?>
            </div>
            <!-- Audit Timeline Link -->
            <a href="reports.php?type=staff_audit&unit_id=<?= $uId ?>" target="_blank" 
               class="btn ghost" style="padding:2px 8px;font-size:10.5px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;font-weight:600;text-decoration:none">
              📱 Audit Timeline
            </a>
          </div>
        </div>

        <!-- Compact 10-per-row Thumbnails Grid -->
        <div style="padding:10px;display:grid;grid-template-columns:repeat(auto-fill, minmax(102px, 1fr));gap:8px">
          <?php foreach ($grp['photos'] as $p): 
            $pUrl = photo_url($p['file_path']);
            $plotName = $p['selected_geofence'] ?: ($p['auto_geofence'] ?: ($p['geofence_name'] ?: ''));
            $hasCoords = !empty($p['lat']) && !empty($p['lng']);
          ?>
            <div class="photo-thumb-card" 
                 style="position:relative;width:100%;aspect-ratio:1/1;background:#0f172a;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,0.06)"
                 onclick="openLightbox('<?= h($pUrl) ?>', '<?= h(addslashes($p['user_name'])) ?>', '<?= h(addslashes($plotName)) ?>', '<?= h($p['taken_at']) ?>', '<?= h(addslashes($p['note'] ?? '')) ?>', '<?= $p['lat'] ?>', '<?= $p['lng'] ?>', <?= (int)$p['id'] ?>, '<?= $p['photo_type'] ?>', '<?= h($p['file_path']) ?>')"
                 title="<?= h($p['user_name']) ?> | <?= h($plotName) ?> (<?= date('h:i:s A', strtotime($p['taken_at'])) ?>) <?= !empty($p['note']) ? ' - ' . h($p['note']) : '' ?>">
              
              <!-- Thumbnail Image -->
              <img src="<?= h($pUrl) ?>" alt="Field Photo" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">
              
              <!-- Top Left Time Badge -->
              <div style="position:absolute;top:3px;left:3px;background:rgba(15,23,42,0.82);color:#fff;font-size:9.5px;font-weight:700;padding:1px 5px;border-radius:3px;backdrop-filter:blur(2px)">
                <?= date('H:i', strtotime($p['taken_at'])) ?>
              </div>

              <!-- Top Right Delete Button (Super Admin / Authorized) -->
              <?php if (can('photos', 'delete')): ?>
                <button type="button" 
                        onclick="event.stopPropagation(); triggerDeleteModal(<?= (int)$p['id'] ?>, '<?= $p['photo_type'] ?>', '<?= h(addslashes($p['user_name'])) ?>', '<?= h($p['taken_at']) ?>', '<?= h(addslashes($plotName)) ?>')" 
                        style="position:absolute;top:3px;right:3px;background:rgba(220,38,38,0.9);color:#fff;border:none;border-radius:3px;width:18px;height:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:10px;font-weight:bold" 
                        title="Permanently Delete Photo">
                  ✕
                </button>
              <?php endif; ?>

              <!-- Bottom Plot Name Badge -->
              <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top, rgba(15,23,42,0.92) 0%, rgba(15,23,42,0.7) 70%, transparent 100%);padding:14px 4px 3px 4px;text-align:center">
                <div style="font-size:10px;font-weight:700;color:#86efac;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= h($plotName ?: 'Field Plot') ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <!-- ========================================================================= -->
    <!-- ▦ CONTINUOUS COMPACT GRID VIEW (~10 THUMBS PER ROW ACROSS ALL USERS)     -->
    <!-- ========================================================================= -->
    <div class="card" style="padding:12px;display:grid;grid-template-columns:repeat(auto-fill, minmax(102px, 1fr));gap:8px;background:#fff;border:1px solid #cbd5e1;border-radius:8px">
      <?php foreach ($photos as $p): 
        $pUrl = photo_url($p['file_path']);
        $plotName = $p['selected_geofence'] ?: ($p['auto_geofence'] ?: ($p['geofence_name'] ?: ''));
      ?>
        <div class="photo-thumb-card" 
             style="position:relative;width:100%;aspect-ratio:1/1;background:#0f172a;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,0.06)"
             onclick="openLightbox('<?= h($pUrl) ?>', '<?= h(addslashes($p['user_name'])) ?>', '<?= h(addslashes($plotName)) ?>', '<?= h($p['taken_at']) ?>', '<?= h(addslashes($p['note'] ?? '')) ?>', '<?= $p['lat'] ?>', '<?= $p['lng'] ?>', <?= (int)$p['id'] ?>, '<?= $p['photo_type'] ?>', '<?= h($p['file_path']) ?>')"
             title="<?= h($p['user_name']) ?> | <?= h($plotName) ?> (<?= date('h:i:s A', strtotime($p['taken_at'])) ?>)">
          <img src="<?= h($pUrl) ?>" alt="Field Photo" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">
          
          <div style="position:absolute;top:3px;left:3px;background:rgba(15,23,42,0.82);color:#fff;font-size:9.5px;font-weight:700;padding:1px 5px;border-radius:3px">
            <?= date('H:i', strtotime($p['taken_at'])) ?>
          </div>

          <?php if (can('photos', 'delete')): ?>
            <button type="button" 
                    onclick="event.stopPropagation(); triggerDeleteModal(<?= (int)$p['id'] ?>, '<?= $p['photo_type'] ?>', '<?= h(addslashes($p['user_name'])) ?>', '<?= h($p['taken_at']) ?>', '<?= h(addslashes($plotName)) ?>')" 
                    style="position:absolute;top:3px;right:3px;background:rgba(220,38,38,0.9);color:#fff;border:none;border-radius:3px;width:18px;height:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:10px" 
                    title="Delete Photo">
              ✕
            </button>
          <?php endif; ?>

          <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top, rgba(15,23,42,0.92) 0%, rgba(15,23,42,0.7) 70%, transparent 100%);padding:14px 4px 3px 4px;text-align:center">
            <div style="font-size:10px;font-weight:700;color:#86efac;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= h($plotName ?: $p['user_name']) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- ========================================================================= -->
<!-- 🔍 LIGHTBOX MODAL WITH FULL RES & METADATA                                -->
<!-- ========================================================================= -->
<div id="lightbox-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.88);z-index:9999;align-items:center;justify-content:center;flex-direction:column;padding:16px" onclick="closeLightbox()">
  <div style="position:relative;max-width:92vw;max-height:88vh;background:#0f172a;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.6);border:1px solid #334155" onclick="event.stopPropagation()">
    
    <!-- Modal Header -->
    <div style="padding:10px 16px;background:#1e293b;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between">
      <div style="color:#f8fafc;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px">
        <span id="lb-officer">Officer Name</span>
        <span id="lb-plot" style="background:#166534;color:#bbf7d0;font-size:11px;padding:2px 8px;border-radius:4px">Plot</span>
        <span id="lb-time" style="color:#94a3b8;font-size:11.5px;font-weight:normal">Time</span>
      </div>
      <button type="button" onclick="closeLightbox()" style="background:transparent;border:none;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px">✕</button>
    </div>

    <!-- Image Display -->
    <div style="position:relative;max-height:70vh;text-align:center;background:#020617;display:flex;align-items:center;justify-content:center;overflow:hidden">
      <img id="lightbox-img" src="" style="max-width:100%;max-height:68vh;object-fit:contain;display:block">
    </div>

    <!-- Modal Footer & Metadata -->
    <div style="padding:10px 16px;background:#1e293b;border-top:1px solid #334155;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div style="color:#cbd5e1;font-size:12px;max-width:650px">
        <div id="lb-note" style="margin-bottom:4px;color:#f1f5f9;font-weight:500"></div>
        <div id="lb-coords" style="font-size:11px;color:#38bdf8"></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <a id="lightbox-dl" href="#" class="btn" style="background:#0284c7;color:#fff;display:inline-flex;padding:6px 14px;font-size:12px;border-radius:4px;text-decoration:none;font-weight:700">
          ⬇️ Download High Res
        </a>
        <?php if (can('photos', 'delete')): ?>
          <button type="button" id="lb-delete-btn" onclick="triggerDeleteFromLightbox()" class="btn" style="background:#dc2626;color:#fff;padding:6px 12px;font-size:12px;border-radius:4px;font-weight:700;border:none;cursor:pointer">
            🗑️ Delete Photo
          </button>
        <?php endif; ?>
        <button type="button" onclick="closeLightbox()" class="btn ghost" style="padding:6px 12px;font-size:12px;border-radius:4px;background:#334155;color:#f8fafc;border:none;cursor:pointer">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 🗑️ SUPER ADMIN PHOTO DELETE CONFIRMATION MODAL WITH AUDIT REASON          -->
<!-- ========================================================================= -->
<div id="delete-confirm-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;padding:20px" onclick="closeDeleteModal()">
  <div style="max-width:440px;width:100%;background:#fff;border-radius:8px;padding:20px;box-shadow:0 10px 25px rgba(0,0,0,0.3)" onclick="event.stopPropagation()">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <span style="font-size:24px">⚠️</span>
      <div>
        <h3 style="margin:0;font-size:16px;color:#991b1b">Delete Field Photo</h3>
        <span style="font-size:12px;color:#64748b">This action is permanently logged in the Audit Trail.</span>
      </div>
    </div>

    <div style="background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:6px;font-size:12.5px;color:#991b1b;margin-bottom:12px">
      Are you sure you want to permanently delete photo <strong id="del-photo-id"></strong> taken by <strong id="del-photo-officer"></strong> at <strong id="del-photo-plot"></strong>?
    </div>

    <form method="post" id="delete-photo-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="delete_photo">
      <input type="hidden" name="id" id="del-input-id" value="">
      <input type="hidden" name="photo_type" id="del-input-type" value="field">

      <div style="margin-bottom:14px">
        <label style="font-size:11.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">Audit Reason for Deletion:</label>
        <select name="reason" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:4px;font-size:12.5px">
          <option value="Duplicate photo upload">Duplicate photo upload</option>
          <option value="Accidental / Unclear photo">Accidental / Unclear photo</option>
          <option value="Incorrect plot tag">Incorrect plot tag</option>
          <option value="Operator test snap">Operator test snap</option>
          <option value="Authorized Management Removal">Authorized Management Removal</option>
        </select>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="closeDeleteModal()" class="btn ghost" style="padding:6px 14px;border:1px solid #cbd5e1;border-radius:4px">Cancel</button>
        <button type="submit" class="btn" style="background:#dc2626;color:#fff;padding:6px 16px;border-radius:4px;font-weight:700;border:none">Permanently Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 📜 DELETION AUDIT LOG MODAL                                               -->
<!-- ========================================================================= -->
<div id="audit-log-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;padding:20px" onclick="closeAuditLogModal()">
  <div style="max-width:850px;width:100%;max-height:85vh;background:#fff;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 15px 35px rgba(0,0,0,0.3)" onclick="event.stopPropagation()">
    
    <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:18px">📜</span>
        <div>
          <strong style="font-size:15px;color:#0f172a">Photo Deletion Audit Trail</strong>
          <div style="font-size:11.5px;color:#64748b">History of all photos removed by Super Admins / Managers</div>
        </div>
      </div>
      <button type="button" onclick="closeAuditLogModal()" style="border:none;background:transparent;font-size:16px;cursor:pointer;color:#64748b">✕</button>
    </div>

    <div style="padding:14px 18px;overflow-y:auto;flex:1">
      <?php if (empty($auditLogs)): ?>
        <div style="text-align:center;padding:36px;color:#94a3b8">
          No photo deletions recorded yet. All future deletions will be logged here.
        </div>
      <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead>
            <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1">
              <th style="padding:8px 10px;text-align:left">Deleted At</th>
              <th style="padding:8px 10px;text-align:left">Deleted By</th>
              <th style="padding:8px 10px;text-align:left">Field Officer</th>
              <th style="padding:8px 10px;text-align:left">Plot</th>
              <th style="padding:8px 10px;text-align:left">Photo Time</th>
              <th style="padding:8px 10px;text-align:left">Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditLogs as $log): ?>
              <tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:8px 10px;color:#475569;font-weight:600"><?= h($log['deleted_at']) ?></td>
                <td style="padding:8px 10px">
                  <strong><?= h($log['deleted_by_name']) ?></strong>
                  <span style="font-size:10.5px;color:#94a3b8;display:block">IP: <?= h($log['ip_address'] ?? '—') ?></span>
                </td>
                <td style="padding:8px 10px">
                  <?= h($log['officer_name']) ?>
                  <?php if (!empty($log['officer_code'])): ?>
                    <span style="font-size:10px;color:#64748b">(<?= h($log['officer_code']) ?>)</span>
                  <?php endif; ?>
                </td>
                <td style="padding:8px 10px">
                  <span style="background:#f0fdf4;color:#166534;padding:1px 6px;border-radius:3px;font-weight:700">
                    <?= h($log['plot_name'] ?: '—') ?>
                  </span>
                </td>
                <td style="padding:8px 10px;color:#64748b"><?= h($log['taken_at'] ?? '—') ?></td>
                <td style="padding:8px 10px;color:#dc2626;font-weight:600"><?= h($log['reason'] ?? 'Manual removal') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div style="padding:10px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:right">
      <button type="button" onclick="closeAuditLogModal()" class="btn ghost" style="padding:6px 14px;border:1px solid #cbd5e1;border-radius:4px">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT HANDLERS                                                       -->
<!-- ========================================================================= -->
<script>
let currentLbPhoto = null;

function openLightbox(url, officer, plot, time, note, lat, lng, id, type, filePath) {
  currentLbPhoto = { id, type, officer, time, plot };
  document.getElementById('lightbox-img').src = url;
  document.getElementById('lb-officer').textContent = officer || 'Staff Officer';
  document.getElementById('lb-plot').textContent = plot ? '🌾 ' + plot : 'Field Plot';
  document.getElementById('lb-time').textContent = time || '';
  document.getElementById('lb-note').textContent = note ? '📝 Note: ' + note : 'No field remark attached';
  
  const coordsEl = document.getElementById('lb-coords');
  if (lat && lng && parseFloat(lat) !== 0) {
    coordsEl.innerHTML = '📍 Location: <a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" style="color:#38bdf8;text-decoration:underline">Google Maps (' + parseFloat(lat).toFixed(5) + ', ' + parseFloat(lng).toFixed(5) + ')</a>';
  } else {
    coordsEl.textContent = '📍 Location: No GPS coordinates';
  }

  const fileName = filePath || url.substring(url.lastIndexOf('uploads/') + 8);
  document.getElementById('lightbox-dl').href = 'download_photo.php?file=' + encodeURIComponent(fileName);

  document.getElementById('lightbox-modal').style.display = 'flex';
}

function closeLightbox() {
  document.getElementById('lightbox-modal').style.display = 'none';
}

function triggerDeleteFromLightbox() {
  if (!currentLbPhoto) return;
  closeLightbox();
  triggerDeleteModal(currentLbPhoto.id, currentLbPhoto.type, currentLbPhoto.officer, currentLbPhoto.time, currentLbPhoto.plot);
}

function triggerDeleteModal(id, type, officer, time, plot) {
  document.getElementById('del-input-id').value = id;
  document.getElementById('del-input-type').value = type;
  document.getElementById('del-photo-id').textContent = '#' + id;
  document.getElementById('del-photo-officer').textContent = officer;
  document.getElementById('del-photo-plot').textContent = plot ? '(' + plot + ')' : '';
  document.getElementById('delete-confirm-modal').style.display = 'flex';
}

function closeDeleteModal() {
  document.getElementById('delete-confirm-modal').style.display = 'none';
}

function openAuditLogModal() {
  document.getElementById('audit-log-modal').style.display = 'flex';
}

function closeAuditLogModal() {
  document.getElementById('audit-log-modal').style.display = 'none';
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeLightbox();
    closeDeleteModal();
    closeAuditLogModal();
  }
});
</script>

<?php layout_end(); ?>
