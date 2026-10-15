<?php
require __DIR__ . '/_common.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'create') {
        $n = 0;
        foreach ((array)($_POST['assigned_to'] ?? ['']) as $uid) {
            q('INSERT INTO tasks (title,description,assigned_to,address,lat,lng,due_date,created_by) VALUES (?,?,?,?,?,?,?,?)', [
                trim($_POST['title']), trim($_POST['description'] ?? '') ?: null, $uid ?: null,
                trim($_POST['address'] ?? '') ?: null,
                $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null,
                $_POST['due_date'] ?: null, $me['id']]);
            $n++;
        }
        flash("$n task(s) created successfully.");
    } elseif ($act === 'cancel') {
        q("UPDATE tasks SET status='cancelled' WHERE id=? AND status<>'done'", [(int)$_POST['id']]);
        flash('Task cancelled.');
    }
    header('Location: tasks.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'user', 'date'])))); 
    exit;
}

// CSV export / filters
$where = ['1=1']; 
$p = [];
if (!empty($_GET['status'])) { $where[] = 't.status=?'; $p[] = $_GET['status']; }
if (!empty($_GET['user'])) { $where[] = 't.assigned_to=?'; $p[] = (int)$_GET['user']; }
if (!empty($_GET['date'])) { 
    $where[] = '(DATE(t.created_at)=? OR DATE(t.completed_at)=? OR t.due_date=?)'; 
    array_push($p, $_GET['date'], $_GET['date'], $_GET['date']); 
}

$sql = "SELECT t.*, u.name staff, u.code staff_code,
   (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='before' ORDER BY id DESC LIMIT 1) bp,
   (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='after' ORDER BY id DESC LIMIT 1) ap
   FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE " . implode(' AND ', $where) . " ORDER BY t.id DESC LIMIT 500";
$tasks = q($sql, $p)->fetchAll();

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv'); 
    header('Content-Disposition: attachment; filename="tasks_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['ID', 'Title', 'Staff', 'Status', 'Due', 'Started', 'Completed', 'Minutes', 'Note']);
    foreach ($tasks as $t) {
        $mins = $t['started_at'] && $t['completed_at'] ? round((strtotime($t['completed_at']) - strtotime($t['started_at'])) / 60) : '';
        fputcsv($o, [$t['id'], $t['title'], $t['staff'], $t['status'], $t['due_date'], $t['started_at'], $t['completed_at'], $mins, $t['staff_note']]);
    }
    exit;
}

$staff = q("SELECT id,name,code FROM users WHERE role='staff' AND active=1 ORDER BY name")->fetchAll();
layout_start('Tasks', 'tasks.php', true);
?>
<!-- Tasks Filter Toolbar -->
<div class="w-subbar">
  <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <span style="font-weight:600;color:#334155">Filter:</span>
    <select name="status" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px">
      <option value="">All Statuses</option>
      <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="in_progress" <?= ($_GET['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
      <option value="done" <?= ($_GET['status'] ?? '') === 'done' ? 'selected' : '' ?>>Completed</option>
      <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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

  <button class="w-icon-btn primary" onclick="document.getElementById('task-modal').style.display='flex'">
    + Create New Task
  </button>
</div>

<div class="w-page-container">
  <!-- Tasks Table -->
  <div class="w-card">
    <table class="w-table">
      <thead>
        <tr>
          <th style="width:40px">#</th>
          <th>Task Title & Address</th>
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
              <?php if (!empty($t['address'])): ?>
                <div style="font-size:11px;color:#64748b"><?= h($t['address']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= h($t['staff'] ?? 'Unassigned') ?></strong>
              <?php if (!empty($t['staff_code'])): ?>
                <span style="font-size:11px;color:#64748b">(<?= h($t['staff_code']) ?>)</span>
              <?php endif; ?>
            </td>
            <td><?= status_badge($t['status']) ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <?php if ($t['bp']): ?>
                  <a href="<?= h(photo_url($t['bp'])) ?>" target="_blank" title="Before photo">
                    <img src="<?= h(photo_url($t['bp'])) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #cbd5e1">
                  </a>
                <?php endif; ?>
                <?php if ($t['ap']): ?>
                  <a href="<?= h(photo_url($t['ap'])) ?>" target="_blank" title="After photo">
                    <img src="<?= h(photo_url($t['ap'])) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #cbd5e1">
                  </a>
                <?php endif; ?>
                <?php if (!$t['bp'] && !$t['ap']): ?>
                  <span style="color:#94a3b8;font-size:11px">No photos</span>
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
          <tr><td colspan="8" style="text-align:center;padding:24px;color:#94a3b8">No tasks found matching filter criteria.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Dialog to Create New Task -->
<div id="task-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:520px;max-width:92vw;max-height:90vh;overflow-y:auto;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,0.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <h3 style="margin:0;font-size:15px;color:#1e293b">Create New Task</h3>
      <button onclick="document.getElementById('task-modal').style.display='none'" style="background:none;border:none;font-size:16px;cursor:pointer">✕</button>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Task Title *</label>
        <input name="title" required placeholder="e.g. Harvesting Sector 4, Tractor Maintenance" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Assign to Staff / Unit</label>
          <select name="assigned_to[]" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:4px">
            <option value="">Unassigned</option>
            <?php foreach ($staff as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= !empty($s['code']) ? '(' . h($s['code']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Due Date</label>
          <input type="date" name="due_date" value="<?= date('Y-m-d') ?>" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
        </div>
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Address / Field Location</label>
        <input name="address" placeholder="e.g. Block D/207/01, Hingurana" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box">
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600">Description / Work Instructions</label>
        <textarea name="description" rows="3" placeholder="Provide instructions for field staff..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px">
        <button type="button" class="w-icon-btn" onclick="document.getElementById('task-modal').style.display='none'">Cancel</button>
        <button type="submit" class="w-icon-btn primary" style="padding:6px 16px">Create Task</button>
      </div>
    </form>
  </div>
</div>

<?php layout_end(); ?>
