<?php
require __DIR__ . '/_common.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name']); $phone = trim($_POST['phone']);
            $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            if ($id) {
                q('UPDATE users SET name=?, phone=?, role=? WHERE id=?', [$name, $phone, $role, $id]);
                if (!empty($_POST['password'])) {
                    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                    q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
                }
                flash('Staff member updated');
            } else {
                if (strlen($_POST['password'] ?? '') < 4) throw new Exception('Password must be at least 4 characters');
                q('INSERT INTO users (name,phone,password_hash,role) VALUES (?,?,?,?)',
                  [$name, $phone, password_hash($_POST['password'], PASSWORD_DEFAULT), $role]);
                flash('Staff member added');
            }
        } elseif ($act === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id !== (int)$me['id']) {
                q('UPDATE users SET active=1-active WHERE id=?', [$id]);
                q('DELETE FROM api_tokens WHERE user_id=?', [$id]);
            }
            flash('Status changed');
        }
    } catch (Throwable $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'That phone number already exists' : $e->getMessage());
    }
    header('Location: staff.php'); exit;
}

$edit = isset($_GET['edit']) ? q('SELECT * FROM users WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$users = q("SELECT u.*,
   (SELECT COUNT(*) FROM tasks WHERE assigned_to=u.id AND status='done') done,
   (SELECT COUNT(*) FROM tasks WHERE assigned_to=u.id AND status IN ('pending','in_progress')) open
   FROM users u ORDER BY u.role, u.name")->fetchAll();

layout_start('Staff', 'staff.php');
?>
<div class="grid" style="grid-template-columns:1fr 340px;align-items:start">
<div class="card">
<table><thead><tr><th>Name</th><th>Phone</th><th>Role</th><th>Duty</th><th>Last seen</th><th>Open / Done</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
<tr style="<?= $u['active'] ? '' : 'opacity:.45' ?>">
  <td><?= h($u['name']) ?></td><td><?= h($u['phone']) ?></td><td><?= h($u['role']) ?></td>
  <td><span class="dot <?= $u['on_duty'] ? 'on' : 'off' ?>" style="display:inline-block"></span></td>
  <td class="mut"><?= h($u['last_seen'] ?? '—') ?></td>
  <td><?= (int)$u['open'] ?> / <?= (int)$u['done'] ?></td>
  <td style="white-space:nowrap">
    <a class="btn ghost sm" href="?edit=<?= $u['id'] ?>">Edit</a>
    <?php if ($u['id'] != $me['id']): ?>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
      <button class="btn sm <?= $u['active'] ? 'red' : '' ?>"><?= $u['active'] ? 'Disable' : 'Enable' ?></button></form>
    <?php endif; ?>
  </td></tr>
<?php endforeach; ?>
</tbody></table></div>

<form method="post" class="card" id="staff-form">
  <h3 style="margin:0"><?= $edit ? 'Edit staff' : 'Add staff' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label>Name</label><input name="name" required value="<?= h($edit['name'] ?? '') ?>">
  <label>Phone (login)</label><input name="phone" required value="<?= h($edit['phone'] ?? '') ?>">
  <label>Password <?= $edit ? '<span class="mut">(leave blank to keep)</span>' : '' ?></label><input name="password" type="password" <?= $edit ? '' : 'required' ?>>
  <label>Role</label><select name="role"><option value="staff">Staff (mobile app)</option><option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin (web)</option></select>
  <div class="row" style="margin-top:18px"><button class="btn" id="save-staff">Save</button><?php if ($edit): ?><a class="btn ghost" href="staff.php">Cancel</a><?php endif; ?></div>
</form>
</div>
<?php layout_end();
