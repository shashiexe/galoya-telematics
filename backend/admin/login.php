<?php
require __DIR__ . '/_common.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['phone'] ?? $_POST['user'] ?? '');
    $u = q("SELECT * FROM users WHERE (phone=? OR name=?) AND role='admin' AND active=1", [$login, $login])->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $u['id'];
        header('Location: dashboard.php'); 
        exit;
    }
    $err = 'Invalid user credentials. Please check username and password.';
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login · Galoya Telematics</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body style="margin:0;padding:0;background:#e2e8f0;">
  <div class="w-login-wrapper">
    <div class="w-login-card">
      <div class="w-login-header">
        <div class="w-login-logo">
          <span style="font-size:28px">🌐</span>
          <div>
            <div><span style="color:#1e88e5">GALOYA</span><span style="color:#0d47a1">TELEMATICS</span></div>
            <span class="subtext">Fleet & Staff Tracking System</span>
          </div>
        </div>
      </div>

      <?php if ($err): ?>
        <div style="background:#ffebee;color:#c62828;padding:8px 12px;border-radius:4px;font-size:12px;margin-bottom:16px;border:1px solid #ffcdd2;">
          <?= h($err) ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <div class="w-form-group">
          <label class="w-form-label" for="user">User:</label>
          <div class="w-form-control">
            <input id="user" name="user" value="galoyap.admin" required autofocus>
          </div>
        </div>

        <div class="w-form-group">
          <label class="w-form-label" for="password">Password:</label>
          <div class="w-form-control">
            <input id="password" type="password" name="password" value="admin123" required>
          </div>
        </div>

        <div class="w-form-group">
          <label class="w-form-label" for="lang">Language:</label>
          <div class="w-form-control">
            <select id="lang" name="lang">
              <option value="en" selected>English</option>
              <option value="si">Sinhala (සිංහල)</option>
              <option value="ta">Tamil (தமிழ்)</option>
            </select>
          </div>
        </div>

        <div class="w-form-group" style="margin-left: 100px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#546e7a;cursor:pointer">
            <input type="checkbox" name="remember" checked style="width:auto;margin:0"> Remember
          </label>
        </div>

        <button type="submit" class="w-login-btn">Log in</button>

        <div class="w-login-links">
          <span>Log in as | Forgot your password?</span>
          <div style="margin-top:6px;font-size:10px;color:#90a4ae">© Galoya Telematics / KainGlobe</div>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
