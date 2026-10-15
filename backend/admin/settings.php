<?php
require __DIR__ . '/_common.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $iv = max(10, min(3600, (int)$_POST['location_interval']));
    set_setting('location_interval', (string)$iv);
    set_setting('allow_staff_change_interval', isset($_POST['allow']) ? '1' : '0');
    set_setting('upload_batch_size', (string)max(50, min(2000, (int)$_POST['upload_batch_size'])));
    if (!empty($_POST['purge_days'])) {
        $n = q('DELETE FROM locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [max(30, (int)$_POST['purge_days'])])->rowCount();
        flash("Settings saved · $n old GPS points deleted");
    } else flash('Settings saved — phones pick this up on their next sync');
    header('Location: settings.php'); exit;
}
$iv = (int)setting('location_interval', 60);
layout_start('Settings', 'settings.php');
?>
<form method="post" class="card" style="max-width:560px" id="settings-form"><?= csrf_field() ?>
  <h3 style="margin-top:0">GPS tracking (all staff)</h3>
  <label>Location interval (seconds)</label>
  <select name="location_interval">
    <?php foreach ([15 => '15 seconds', 30 => '30 seconds', 60 => '1 minute (default)', 120 => '2 minutes', 300 => '5 minutes', 600 => '10 minutes'] as $v => $l): ?>
    <option value="<?= $v ?>" <?= $v === $iv ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <label style="display:flex;gap:10px;align-items:center;color:var(--txt)"><input type="checkbox" name="allow" style="width:auto" <?= setting('allow_staff_change_interval', '1') === '1' ? 'checked' : '' ?>> Allow staff to change interval in the app</label>
  <label>Upload batch size (points per request)</label><input type="number" name="upload_batch_size" value="<?= (int)setting('upload_batch_size', 500) ?>">
  <h3>Maintenance</h3>
  <label>Delete GPS history older than (days, min 30) — leave blank to skip</label><input type="number" name="purge_days" min="30" placeholder="e.g. 180">
  <p class="mut" style="font-size:13px">Map provider: <b><?= h($CONFIG['map_provider']) ?></b> — change <code>map_provider</code> and <code>google_maps_key</code> in config.php to switch to Google Maps.</p>
  <button class="btn" id="save-settings">Save settings</button>
</form>
<?php layout_end();
