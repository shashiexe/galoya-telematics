<?php
require __DIR__ . '/_common.php';
$err = '';

// Brute-force rate limiting
$lockoutTime = 300; // 5 minutes
$maxAttempts = 5;
$attempts = (int)($_SESSION['login_attempts'] ?? 0);
$lastAttemptTime = (int)($_SESSION['last_login_attempt'] ?? 0);

if ($attempts >= $maxAttempts && (time() - $lastAttemptTime) < $lockoutTime) {
    $remaining = ceil(($lockoutTime - (time() - $lastAttemptTime)) / 60);
    $err = "Too many failed login attempts. For security, please wait $remaining minute(s) before trying again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_web_user_columns();
    $login = trim($_POST['phone'] ?? $_POST['user'] ?? '');
    $pwd = $_POST['password'] ?? '';

    $u = q("SELECT * FROM users WHERE (phone=? OR name=?) AND active=1 AND (role='admin' OR web_access=1)", [$login, $login])->fetch();

    if ($u && password_verify($pwd, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $u['id'];
        $_SESSION['admin_user'] = ['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role']];
        $_SESSION['admin_perms'] = !empty($u['permissions_json']) ? json_decode($u['permissions_json'], true) : [];
        unset($_SESSION['login_attempts'], $_SESSION['last_login_attempt']);

        try {
            q("UPDATE users SET last_seen=NOW(), last_login_at=NOW(), last_login_ip=? WHERE id=?", 
              [$_SERVER['REMOTE_ADDR'] ?? '', $u['id']]);
        } catch (Throwable $e) {}

        header('Location: dashboard.php'); 
        exit;
    }

    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['last_login_attempt'] = time();
    $remainingAttempts = max(0, $maxAttempts - ($attempts + 1));
    if ($remainingAttempts > 0) {
        $err = "Invalid credentials. Please check your username and password. ($remainingAttempts attempt(s) remaining)";
    } else {
        $err = "Account locked for 5 minutes due to multiple failed login attempts.";
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login · GalOya Track360</title>
  <link rel="icon" type="image/png" href="assets/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #022c22;
      background: radial-gradient(circle at 15% 20%, rgba(16, 185, 129, 0.22), transparent 45%),
                  radial-gradient(circle at 85% 80%, rgba(5, 150, 105, 0.25), transparent 45%),
                  radial-gradient(circle at 50% 50%, rgba(4, 120, 87, 0.15), transparent 60%),
                  linear-gradient(135deg, #022c22 0%, #064e3b 50%, #021e17 100%);
      color: #f8fafc;
      overflow-x: hidden;
      position: relative;
      padding: 20px;
    }

    /* Ambient animated agricultural glowing background orbs */
    .bg-orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(80px);
      pointer-events: none;
      z-index: 1;
      opacity: 0.6;
      animation: floatOrb 14s ease-in-out infinite alternate;
    }
    .bg-orb-1 {
      width: 380px;
      height: 380px;
      background: radial-gradient(circle, #10b981, rgba(16, 185, 129, 0));
      top: -60px;
      left: -60px;
    }
    .bg-orb-2 {
      width: 420px;
      height: 420px;
      background: radial-gradient(circle, #059669, rgba(5, 150, 105, 0));
      bottom: -80px;
      right: -80px;
      animation-duration: 18s;
      animation-delay: -5s;
    }
    .bg-orb-3 {
      width: 250px;
      height: 250px;
      background: radial-gradient(circle, #eab308, rgba(234, 179, 8, 0));
      top: 50%;
      right: 15%;
      opacity: 0.2;
      animation-duration: 20s;
      animation-delay: -8s;
    }

    @keyframes floatOrb {
      0% { transform: translate(0, 0) scale(1); }
      50% { transform: translate(40px, -30px) scale(1.08); }
      100% { transform: translate(-30px, 40px) scale(0.95); }
    }

    /* Main Login Card */
    .login-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 420px;
      animation: cardEntrance 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes cardEntrance {
      from {
        opacity: 0;
        transform: translateY(30px) scale(0.97);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .login-card {
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 28px;
      padding: 36px 32px 30px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.45),
                  0 0 0 1px rgba(255, 255, 255, 0.6),
                  0 0 50px rgba(16, 185, 129, 0.2);
      color: #0f172a;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .login-card:hover {
      box-shadow: 0 30px 70px rgba(0, 0, 0, 0.5),
                  0 0 0 1px rgba(255, 255, 255, 0.8),
                  0 0 60px rgba(16, 185, 129, 0.3);
    }

    /* Brand Header with Logo */
    .brand-header {
      text-align: center;
      margin-bottom: 26px;
    }
    .brand-logo-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 6px;
      background: #ffffff;
      border-radius: 20px;
      box-shadow: 0 8px 24px rgba(16, 185, 129, 0.2), 0 2px 6px rgba(0, 0, 0, 0.06);
      margin-bottom: 14px;
      animation: logoPulse 4s ease-in-out infinite;
    }
    .brand-logo-img {
      height: 96px;
      max-width: 240px;
      object-fit: contain;
      display: block;
    }

    @keyframes logoPulse {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-4px); }
    }

    .brand-title-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      margin-bottom: 4px;
    }
    .brand-title-galoya {
      font-size: 24px;
      font-weight: 900;
      color: #15803d;
      letter-spacing: -0.5px;
    }
    .brand-title-track {
      font-size: 24px;
      font-weight: 900;
      color: #0f172a;
      letter-spacing: -0.5px;
    }
    .brand-title-360 {
      font-size: 24px;
      font-weight: 900;
      color: #eab308;
      letter-spacing: -0.5px;
    }
    .brand-tagline {
      font-size: 11.5px;
      font-weight: 700;
      color: #047857;
      text-transform: uppercase;
      letter-spacing: 1.2px;
    }

    /* Error Alert */
    .alert-danger {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 12.5px;
      font-weight: 600;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
      animation: shake 0.4s ease-in-out;
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-6px); }
      40%, 80% { transform: translateX(6px); }
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 16px;
    }
    .form-label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .input-icon {
      position: absolute;
      left: 14px;
      font-size: 16px;
      color: #94a3b8;
      pointer-events: none;
      transition: color 0.2s;
    }
    .form-input {
      width: 100%;
      height: 46px;
      padding: 10px 14px 10px 42px;
      font-size: 14px;
      font-weight: 600;
      font-family: inherit;
      color: #0f172a;
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      outline: none;
      transition: all 0.2s ease;
    }
    .form-input:focus {
      background: #ffffff;
      border-color: #10b981;
      box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }
    .form-input:focus + .input-icon,
    .input-wrapper:focus-within .input-icon {
      color: #10b981;
    }

    .form-select {
      width: 100%;
      height: 46px;
      padding: 10px 14px 10px 42px;
      font-size: 13.5px;
      font-weight: 600;
      font-family: inherit;
      color: #0f172a;
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      outline: none;
      cursor: pointer;
      transition: all 0.2s;
    }
    .form-select:focus {
      background: #ffffff;
      border-color: #10b981;
      box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }

    /* Remember Row */
    .remember-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 18px 0 22px;
      font-size: 12.5px;
      font-weight: 600;
      color: #64748b;
    }
    .remember-label {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      user-select: none;
    }
    .remember-checkbox {
      width: 16px;
      height: 16px;
      accent-color: #10b981;
      cursor: pointer;
    }

    /* Vibrant Emerald Login Button */
    .login-btn {
      width: 100%;
      height: 48px;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #ffffff;
      border: none;
      border-radius: 14px;
      font-size: 15px;
      font-weight: 800;
      font-family: inherit;
      letter-spacing: 0.3px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 10px 25px rgba(16, 185, 129, 0.35);
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
    }
    .login-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
      transition: left 0.6s;
    }
    .login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 30px rgba(16, 185, 129, 0.45);
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
    }
    .login-btn:hover::before {
      left: 100%;
    }
    .login-btn:active {
      transform: translateY(0);
      box-shadow: 0 6px 15px rgba(16, 185, 129, 0.3);
    }

    /* Footer / GOPL IT Attribution */
    .card-footer {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid #f1f5f9;
      text-align: center;
    }
    .gopl-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      border-radius: 20px;
      color: #047857;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.3px;
      margin-bottom: 6px;
    }
    .org-text {
      font-size: 11px;
      color: #64748b;
      font-weight: 600;
    }
    .apk-dl-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 12px;
      padding: 6px 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      color: #0284c7;
      font-size: 11.5px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s;
    }
    .apk-dl-link:hover {
      background: #e0f2fe;
      border-color: #7dd3fc;
      transform: translateY(-1px);
    }
  </style>
</head>
<body>
  <!-- Animated Background Orbs -->
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>
  <div class="bg-orb bg-orb-3"></div>

  <div class="login-container">
    <div class="login-card">
      <!-- Brand Header -->
      <div class="brand-header">
        <div class="brand-logo-wrap">
          <img src="assets/logo_full.png" alt="GalOya Track360" class="brand-logo-img" onerror="this.onerror=null;this.src='assets/logo.png'">
        </div>
        <div class="brand-title-wrap">
          <span class="brand-title-galoya">GalOya</span>
          <span class="brand-title-track">Track</span>
          <span class="brand-title-360">360</span>
        </div>
        <div class="brand-tagline">People • Field • Fleet Telematics</div>
      </div>

      <!-- Error Alert -->
      <?php if ($err): ?>
        <div class="alert-danger">
          <span>⚠️</span>
          <span><?= h($err) ?></span>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form method="post" autocomplete="on">
        <div class="form-group">
          <label class="form-label" for="user">Username / Staff ID</label>
          <div class="input-wrapper">
            <span class="input-icon">👤</span>
            <input class="form-input" id="user" name="user" value="galoyap.admin" required autofocus placeholder="Enter your username">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-wrapper">
            <span class="input-icon">🔒</span>
            <input class="form-input" id="password" type="password" name="password" value="admin123" required placeholder="Enter your password">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="lang">Language</label>
          <div class="input-wrapper">
            <span class="input-icon">🌐</span>
            <select class="form-select" id="lang" name="lang">
              <option value="en" selected>English (Primary)</option>
              <option value="si">Sinhala (සිංහල)</option>
              <option value="ta">Tamil (தமிழ்)</option>
            </select>
          </div>
        </div>

        <div class="remember-row">
          <label class="remember-label">
            <input class="remember-checkbox" type="checkbox" name="remember" checked>
            <span>Keep me logged in</span>
          </label>
          <span style="color:#059669;cursor:pointer;font-size:12px" title="Contact GOPL IT Helpdesk">Need Help?</span>
        </div>

        <button type="submit" class="login-btn">
          <span>Sign In to Track360</span>
          <span>➔</span>
        </button>

        <div class="card-footer">
          <div>
            <span class="gopl-badge">
              <span>🌱</span>
              <span>Created by GOPL IT</span>
            </span>
          </div>
          <div class="org-text">
            Gal-Oya Plantations (Pvt) Limited · Hingurana
          </div>
          <div>
            <a href="../Galoya-Telematics.apk" download class="apk-dl-link">
              <span>📱</span>
              <span>Download GalOya Track360 Android APK (v1.3.5)</span>
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
