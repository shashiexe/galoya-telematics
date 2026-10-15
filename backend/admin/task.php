<?php
require __DIR__ . '/_common.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$t = q('SELECT t.*, u.name staff, u.phone, u.code staff_code FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.id=?', [$id])->fetch();
if (!$t) { http_response_code(404); exit('Task not found'); }
$photos = q('SELECT * FROM task_photos WHERE task_id=? ORDER BY taken_at', [$id])->fetchAll();
$by = ['before' => [], 'after' => []];
foreach ($photos as $p) $by[$p['type']][] = $p;
$mins = $t['started_at'] && $t['completed_at'] ? round((strtotime($t['completed_at']) - strtotime($t['started_at'])) / 60) : null;

layout_start('Task #' . $t['id'] . ' - ' . $t['title'], 'tasks.php', true);
?>
<div class="w-subbar">
  <div style="display:flex;align-items:center;gap:10px">
    <a href="tasks.php" class="w-icon-btn">← Back to Tasks List</a>
    <h2 style="margin:0;font-size:14px;color:#1e293b">Task #<?= $t['id'] ?>: <?= h($t['title']) ?></h2>
    <?= status_badge($t['status']) ?>
  </div>
</div>

<div class="w-page-container">
  <div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
    <!-- Left Column: Photos & Details -->
    <div>
      <div class="w-card">
        <h3 style="margin:0 0 8px;font-size:15px;color:#1e293b">Task Description</h3>
        <p style="color:#475569;margin:0 0 16px;font-size:13px;line-height:1.5">
          <?= nl2br(h($t['description'] ?: 'No detailed instructions provided.')) ?>
        </p>

        <!-- Before & After Comparison Photos -->
        <h3 style="margin:0 0 12px;font-size:14px;color:#1e293b;border-top:1px solid #e2e8f0;padding-top:14px">
          Field Photos Verification
        </h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <!-- Before Photos -->
          <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <strong style="color:#1e88e5;font-size:12px">📷 Before Work (Start)</strong>
              <span style="font-size:11px;color:#64748b"><?= count($by['before']) ?> photo(s)</span>
            </div>
            <?php if (!$by['before']): ?>
              <div style="height:140px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px dashed #cbd5e1;border-radius:6px;color:#94a3b8;font-size:12px">
                No before photo captured yet
              </div>
            <?php endif; ?>
            <?php foreach ($by['before'] as $p): ?>
              <div style="margin-bottom:8px;background:#fff;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1">
                <a href="<?= h(photo_url($p['file_path'])) ?>" target="_blank">
                  <img src="<?= h(photo_url($p['file_path'])) ?>" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block">
                </a>
                <div style="padding:6px 8px;font-size:11px;color:#64748b">
                  🕒 <?= h($p['taken_at']) ?><?= $p['lat'] ? ' · 📍 ' . round($p['lat'], 4) . ', ' . round($p['lng'], 4) : '' ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- After Photos -->
          <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <strong style="color:#2e7d32;font-size:12px">📸 After Work (Finish)</strong>
              <span style="font-size:11px;color:#64748b"><?= count($by['after']) ?> photo(s)</span>
            </div>
            <?php if (!$by['after']): ?>
              <div style="height:140px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px dashed #cbd5e1;border-radius:6px;color:#94a3b8;font-size:12px">
                No after photo captured yet
              </div>
            <?php endif; ?>
            <?php foreach ($by['after'] as $p): ?>
              <div style="margin-bottom:8px;background:#fff;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1">
                <a href="<?= h(photo_url($p['file_path'])) ?>" target="_blank">
                  <img src="<?= h(photo_url($p['file_path'])) ?>" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block">
                </a>
                <div style="padding:6px 8px;font-size:11px;color:#64748b">
                  🕒 <?= h($p['taken_at']) ?><?= $p['lat'] ? ' · 📍 ' . round($p['lat'], 4) . ', ' . round($p['lng'], 4) : '' ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($t['staff_note']): ?>
          <div style="margin-top:16px;background:#f1f8ff;border-left:4px solid #1976d2;padding:10px 14px;border-radius:0 6px 6px 0">
            <strong style="font-size:12px;color:#0d47a1">Staff Completion Note:</strong>
            <p style="margin:4px 0 0;font-size:12.5px;color:#334155"><?= nl2br(h($t['staff_note'])) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right Column: Meta & Location Map -->
    <div>
      <div class="w-card" style="margin-bottom:16px">
        <h4 style="margin:0 0 10px;font-size:13px;color:#1e293b">Task Details</h4>
        <div style="display:flex;flex-direction:column;gap:8px;font-size:12px">
          <div>
            <span style="color:#64748b">Assigned to:</span>
            <strong style="color:#0f172a;display:block"><?= h($t['staff'] ?? 'Unassigned') ?></strong>
            <?php if ($t['phone']): ?>
              <div style="font-size:11px;color:#1976d2"><?= h($t['phone']) ?></div>
            <?php endif; ?>
          </div>
          <div>
            <span style="color:#64748b">Field Location:</span>
            <div style="font-weight:600"><?= h($t['address'] ?? 'Hingurana Sector') ?></div>
          </div>
          <div>
            <span style="color:#64748b">Due Date:</span>
            <div style="font-weight:600"><?= h($t['due_date'] ?? 'No deadline') ?></div>
          </div>
          <div>
            <span style="color:#64748b">Started At:</span>
            <div><?= h($t['started_at'] ?? '–') ?></div>
          </div>
          <div>
            <span style="color:#64748b">Completed At:</span>
            <div><?= h($t['completed_at'] ?? '–') ?></div>
          </div>
          <?php if ($mins !== null): ?>
            <div>
              <span style="color:#64748b">Duration of Work:</span>
              <strong style="color:#2e7d32"><?= $mins ?> minutes</strong>
            </div>
          <?php endif; ?>
        </div>

        <?php if (in_array($t['status'], ['pending', 'in_progress'])): ?>
          <form method="post" action="tasks.php" onsubmit="return confirm('Cancel this task?')" style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="cancel">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button type="submit" class="w-icon-btn" style="width:100%;justify-content:center;color:#d32f2f;border-color:#ffcdd2;background:#ffebee">
              Cancel Task
            </button>
          </form>
        <?php endif; ?>
      </div>

      <!-- Task Location Map -->
      <div class="w-card" style="padding:0;overflow:hidden">
        <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:600;font-size:11.5px">
          📍 Task GPS Location
        </div>
        <div id="map" style="height:250px;width:100%"></div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const pts = <?= json_encode(array_values(array_filter([
      $t['lat'] ? [(float)$t['lat'], (float)$t['lng'], 'Task Location'] : null,
      $t['start_lat'] ? [(float)$t['start_lat'], (float)$t['start_lng'], 'Start (Before)'] : null,
      $t['end_lat'] ? [(float)$t['end_lat'], (float)$t['end_lng'], 'End (After)'] : null
  ]))) ?>;
  
  const c = pts[0] ? [pts[0][0], pts[0][1]] : <?= json_encode($CONFIG['map_center']) ?>;
  const map = StMap.create(document.getElementById('map'), c, pts.length ? 15 : <?= (int)$CONFIG['map_zoom'] ?>);

  pts.forEach(p => {
    L.marker([p[0], p[1]]).addTo(map).bindPopup(`<strong>${p[2]}</strong><br>${p[0].toFixed(5)}, ${p[1].toFixed(5)}`);
  });
});
</script>

<?php layout_end(); ?>
