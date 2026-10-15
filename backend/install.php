<?php
/**
 * One-time installer: creates tables + first admin. DELETE THIS FILE after use.
 */
require __DIR__ . '/bootstrap.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt) db()->exec($stmt);
        }
        if (!q("SELECT id FROM users WHERE role='admin'")->fetchColumn()) {
            q("INSERT INTO users (name,phone,password_hash,role) VALUES (?,?,?,'admin')",
              [$_POST['name'], $_POST['phone'], password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        }
        $msg = '✅ Installed! Now DELETE install.php from the server and open admin/';
    } catch (Throwable $e) { $msg = '❌ ' . $e->getMessage(); }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install StaffTrack</title>
<link rel="stylesheet" href="admin/assets/style.css"></head><body><main class="login"><form method="post" class="card">
<h1 style="font-size:20px">Install StaffTrack</h1><?php if ($msg): ?><div class="flash"><?= h($msg) ?></div><?php endif; ?>
<label>Admin name</label><input name="name" required><label>Admin phone (login)</label><input name="phone" required>
<label>Admin password</label><input name="password" type="password" required minlength="6">
<button class="btn" style="margin-top:16px">Install</button></form></main></body></html>
