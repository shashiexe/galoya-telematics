<?php
require __DIR__ . '/_common.php';
require_admin();

// Fetch all units for filter dropdown
$staff = q("SELECT id, name, code, unit_type FROM users WHERE role='staff' AND active=1 ORDER BY (code IS NOT NULL) DESC, name ASC")->fetchAll();

$uid = isset($_GET['user']) && $_GET['user'] !== '' ? (int)$_GET['user'] : null;
$from_dt = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to_dt = $_GET['to'] ?? date('Y-m-d 23:59:59');
$type = $_GET['type'] ?? 'all'; // all, field, task

// Build queries for field_photos and task_photos
$params1 = [];
$params2 = [];

$sql1 = "SELECT fp.id, fp.user_id, fp.file_path, fp.lat, fp.lng, fp.note, fp.geofence_name, fp.taken_at, 'field' as photo_type, 
                u.name as user_name, u.code as user_code, u.unit_type
         FROM field_photos fp
         JOIN users u ON u.id = fp.user_id
         WHERE fp.taken_at BETWEEN ? AND ?";
$params1[] = $from_dt;
$params1[] = $to_dt;

$sql2 = "SELECT tp.id, tp.user_id, tp.file_path, tp.lat, tp.lng, 
                CONCAT('Task #', tp.task_id, ' (', tp.type, ')', IF(t.title IS NOT NULL, CONCAT(' - ', t.title), '')) as note, 
                NULL as geofence_name,
                tp.taken_at, 'task' as photo_type,
                u.name as user_name, u.code as user_code, u.unit_type
         FROM task_photos tp
         JOIN users u ON u.id = tp.user_id
         LEFT JOIN tasks t ON t.id = tp.task_id
         WHERE tp.taken_at BETWEEN ? AND ?";
$params2[] = $from_dt;
$params2[] = $to_dt;

if ($uid) {
    $sql1 .= " AND fp.user_id = ?";
    $params1[] = $uid;
    $sql2 .= " AND tp.user_id = ?";
    $params2[] = $uid;
}

$photos = [];
if ($type === 'all' || $type === 'field') {
    $rows1 = q($sql1, $params1)->fetchAll();
    $photos = array_merge($photos, $rows1);
}
if ($type === 'all' || $type === 'task') {
    $rows2 = q($sql2, $params2)->fetchAll();
    $photos = array_merge($photos, $rows2);
}

// Sort all by taken_at DESC
usort($photos, function($a, $b) {
    return strcmp($b['taken_at'], $a['taken_at']);
});

layout_start('Photos & Field Media', 'photos.php');
?>
<div style="padding:16px 20px;max-width:1440px;margin:0 auto">
  <!-- Header Bar -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;color:#1e293b;display:flex;align-items:center;gap:8px">
        <span>📷</span> Uploaded Field Photos & Media Gallery
      </h2>
      <div style="font-size:12px;color:#64748b;margin-top:2px">
        Photos taken by mobile apps, tagged with exact GPS location, time, and machinery unit.
      </div>
    </div>
    <div style="font-size:13px;color:#0284c7;font-weight:700;background:#e0f2fe;padding:6px 14px;border-radius:20px;border:1px solid #bae6fd">
      Total: <?= count($photos) ?> Photos Found
    </div>
  </div>

  <!-- Filter Bar -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.05)">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
      <!-- Unit Selection -->
      <div style="flex:1;min-width:200px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Unit / Machine:</label>
        <select name="user" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12.5px">
          <option value="">All Units & Staff</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $uid === (int)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Type Filter -->
      <div style="min-width:140px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">Category:</label>
        <select name="type" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12.5px">
          <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Photos</option>
          <option value="field" <?= $type === 'field' ? 'selected' : '' ?>>Field Snaps 📷</option>
          <option value="task" <?= $type === 'task' ? 'selected' : '' ?>>Task Verification ✓</option>
        </select>
      </div>

      <!-- Date Presets -->
      <div style="min-width:180px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">From:</label>
        <input type="datetime-local" name="from" value="<?= date('Y-m-d\TH:i', strtotime($from_dt)) ?>" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
      </div>

      <div style="min-width:180px">
        <label style="font-size:11.5px;color:#475569;display:block;margin-bottom:4px;font-weight:600">To:</label>
        <input type="datetime-local" name="to" value="<?= date('Y-m-d\TH:i', strtotime($to_dt)) ?>" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px">
      </div>

      <div>
        <button type="submit" class="w-icon-btn primary" style="padding:8px 18px;font-weight:700;font-size:12.5px;border-radius:4px">
          🔍 Filter
        </button>
      </div>
    </form>
  </div>

  <!-- Photos Gallery Grid -->
  <?php if (empty($photos)): ?>
    <div style="background:#fff;border:1px dashed #cbd5e1;border-radius:8px;padding:48px 20px;text-align:center;color:#64748b">
      <div style="font-size:48px;margin-bottom:12px">📷</div>
      <h3 style="margin:0 0 6px;color:#334155">No photos found</h3>
      <p style="margin:0;font-size:13px">When staff or operators take photos using the Galoya Telematics mobile app, they will automatically appear here with exact GPS coordinates and direct download buttons.</p>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:16px">
      <?php foreach ($photos as $p): 
        $pUrl = photo_url($p['file_path']);
        $hasCoords = !empty($p['lat']) && !empty($p['lng']);
      ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;flex-direction:column;transition:transform 0.15s, box-shadow 0.15s" class="w-photo-card">
          <!-- Thumbnail Image -->
          <div style="position:relative;height:200px;background:#0f172a;cursor:pointer;overflow:hidden" onclick="openLightbox('<?= h($pUrl) ?>', '<?= h($p['user_name']) ?> - <?= h($p['taken_at']) ?>')">
            <img src="<?= h($pUrl) ?>" alt="Field Photo" style="width:100%;height:100%;object-fit:cover;transition:transform 0.3s" loading="lazy">
            <span style="position:absolute;top:8px;right:8px;background:rgba(15,23,42,0.8);color:#fff;font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600">
              <?= $p['photo_type'] === 'field' ? '📷 Field Snap' : '✓ Task Photo' ?>
            </span>
            <span style="position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,0.65);color:#fff;font-size:10.5px;padding:2px 6px;border-radius:3px">
              🔍 Click to zoom
            </span>
          </div>

          <!-- Card Body -->
          <div style="padding:12px;flex:1;display:flex;flex-direction:column;justify-content:space-between">
            <div>
              <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                <strong style="color:#0f172a;font-size:13px"><?= h($p['user_name']) ?></strong>
                <?php if (!empty($p['user_code'])): ?>
                  <span style="font-size:11px;color:#64748b"><?= h($p['user_code']) ?></span>
                <?php endif; ?>
              </div>

              <div style="font-size:11.5px;color:#64748b;margin-bottom:6px">
                🕒 <?= h($p['taken_at']) ?>
              </div>

              <?php if (!empty($p['geofence_name'])): ?>
                <div style="font-size:11px;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:3px 8px;border-radius:4px;margin-bottom:6px;font-weight:700">
                  ⬡ <?= h($p['geofence_name']) ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($p['note'])): ?>
                <div style="font-size:12px;color:#334155;background:#f8fafc;padding:6px 8px;border-radius:4px;border-left:3px solid #0284c7;margin-bottom:8px">
                  <?= h($p['note']) ?>
                </div>
              <?php endif; ?>

              <?php if ($hasCoords): ?>
                <div style="font-size:11px;color:#475569;margin-bottom:8px">
                  📍 <code><?= number_format((float)$p['lat'], 5) ?>, <?= number_format((float)$p['lng'], 5) ?></code>
                </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <div style="display:flex;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9">
              <a href="download_photo.php?file=<?= urlencode($p['file_path']) ?>" class="w-icon-btn primary" style="flex:1;justify-content:center;font-size:11.5px;padding:6px;border-radius:4px" title="Download High Resolution Photo">
                ⬇️ Download
              </a>
              <?php if ($hasCoords): ?>
                <a href="history.php?user=<?= $p['user_id'] ?>&from=<?= urlencode(date('Y-m-d 00:00:00', strtotime($p['taken_at']))) ?>&to=<?= urlencode(date('Y-m-d 23:59:59', strtotime($p['taken_at']))) ?>" class="w-icon-btn" style="flex:1;justify-content:center;font-size:11.5px;padding:6px;border-radius:4px" title="View location on route track">
                  ↝ View Track
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Lightbox Modal -->
<div id="lightbox-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;flex-direction:column;padding:20px" onclick="closeLightbox()">
  <div style="position:relative;max-width:90vw;max-height:85vh;text-align:center" onclick="event.stopPropagation()">
    <img id="lightbox-img" src="" style="max-width:100%;max-height:80vh;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.5)">
    <div id="lightbox-caption" style="color:#fff;margin-top:10px;font-size:13px;font-weight:600"></div>
    <div style="margin-top:10px">
      <a id="lightbox-dl" href="#" class="w-icon-btn primary" style="display:inline-flex;padding:6px 16px;font-size:12px;border-radius:4px">
        ⬇️ Download Full Resolution
      </a>
      <button type="button" class="w-icon-btn" onclick="closeLightbox()" style="margin-left:8px;padding:6px 16px;font-size:12px;border-radius:4px">
        ✕ Close
      </button>
    </div>
  </div>
</div>

<script>
function openLightbox(url, caption) {
  document.getElementById('lightbox-img').src = url;
  document.getElementById('lightbox-caption').textContent = caption;
  // Construct download link
  const fileName = url.substring(url.lastIndexOf('uploads/') + 8);
  document.getElementById('lightbox-dl').href = 'download_photo.php?file=' + encodeURIComponent(fileName);
  const modal = document.getElementById('lightbox-modal');
  modal.style.display = 'flex';
}

function closeLightbox() {
  document.getElementById('lightbox-modal').style.display = 'none';
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeLightbox();
});
</script>

<?php layout_end(); ?>
