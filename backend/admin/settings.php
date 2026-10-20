<?php
require __DIR__ . '/_common.php';
require_admin();
require_perm('settings', 'view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    require_perm('settings', 'save');
    $iv = max(10, min(3600, (int)$_POST['location_interval']));
    set_setting('location_interval', (string)$iv);
    set_setting('allow_staff_change_interval', isset($_POST['allow']) ? '1' : '0');
    set_setting('upload_batch_size', (string)max(50, min(2000, (int)$_POST['upload_batch_size'])));
    
    // AI Assistant Settings
    if (isset($_POST['gemini_api_key'])) {
        $gKey = trim((string)$_POST['gemini_api_key']);
        if ($gKey) set_setting('gemini_api_key', $gKey);
    }
    if (isset($_POST['gemini_model'])) {
        set_setting('gemini_model', trim((string)$_POST['gemini_model']));
    }

    if (!empty($_POST['purge_days'])) {
        $n = q('DELETE FROM locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [max(30, (int)$_POST['purge_days'])])->rowCount();
        flash("Settings saved · $n old GPS points deleted");
    } else flash('Settings saved successfully');
    header('Location: settings.php'); exit;
}
$iv = (int)setting('location_interval', 60);
$geminiKey = (string)setting('gemini_api_key', '');
$geminiModel = (string)setting('gemini_model', 'gemini-3.8-flash');

layout_start('Settings', 'settings.php');
?>
<div style="display:flex;gap:20px;flex-wrap:wrap">
  <form method="post" class="card" style="flex:1;min-width:320px;max-width:560px" id="settings-form"><?= csrf_field() ?>
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
    
    <?php if (can('settings', 'save')): ?>
      <button class="btn" id="save-settings">Save settings</button>
    <?php endif; ?>
  </form>

  <!-- AI Analyst Configuration Card -->
  <form method="post" class="card" style="flex:1;min-width:320px;max-width:560px;background:#0f172a;border:1px solid #334155"><?= csrf_field() ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <span style="font-size:24px">✨</span>
      <div>
        <h3 style="margin:0;color:#38bdf8">Track360 AI Analyst Intelligence</h3>
        <span style="font-size:11.5px;color:#94a3b8">Powered by Google Gemini Enterprise Engine</span>
      </div>
    </div>

    <label style="color:#e2e8f0;font-weight:600;font-size:13px;display:block;margin-bottom:6px">Google Gemini API Key</label>
    <div style="position:relative;display:flex;align-items:center;margin-bottom:14px">
      <input type="password" id="gemini_key_input" name="gemini_api_key" value="<?= h($geminiKey) ?>" style="background:#1e293b;color:#f8fafc;border:1px solid #475569;border-radius:8px;padding:9px 40px 9px 12px;font-family:monospace;font-size:13px;width:100%;box-sizing:border-box" placeholder="Enter AIzaSy... or AQ... key">
      <button type="button" onclick="toggleKeyVis()" style="position:absolute;right:10px;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:15px" title="Show/Hide Key">👁️</button>
    </div>

    <label style="color:#e2e8f0;font-weight:600;font-size:13px;display:block;margin-bottom:6px">Gemini Model Architecture</label>
    <select name="gemini_model" id="gemini_model_select" style="background:#1e293b;color:#f8fafc;border:1px solid #475569;border-radius:8px;padding:9px 12px;font-size:13px;width:100%;box-sizing:border-box;margin-bottom:16px">
      <option value="gemini-3.8-flash" <?= $geminiModel === 'gemini-3.8-flash' ? 'selected' : '' ?>>⚡ Gemini 3.8 Flash (Recommended · Ultra Fast & Accurate)</option>
      <option value="gemini-3.1-pro-preview" <?= $geminiModel === 'gemini-3.1-pro-preview' ? 'selected' : '' ?>>🧠 Gemini 3.1 Pro (Deep Analytical Reasoning)</option>
    </select>

    <div style="display:flex;gap:10px;align-items:center">
      <?php if (can('settings', 'save')): ?>
        <button class="btn" style="background:#2563eb">Save AI Config</button>
      <?php endif; ?>
      <button type="button" class="btn" style="background:#059669" onclick="testAIConnection()" id="test-ai-btn">Test AI Connection</button>
    </div>
    <div id="ai-test-result" style="margin-top:12px;font-size:12px;display:none;padding:8px 12px;border-radius:6px"></div>
  </form>
</div>

<script>
function toggleKeyVis() {
  var el = document.getElementById('gemini_key_input');
  el.type = el.type === 'password' ? 'text' : 'password';
}

function testAIConnection() {
  var btn = document.getElementById('test-ai-btn');
  var resBox = document.getElementById('ai-test-result');
  var key = document.getElementById('gemini_key_input').value.trim();
  var model = document.getElementById('gemini_model_select').value;

  btn.disabled = true;
  btn.textContent = 'Testing...';
  resBox.style.display = 'none';

  var fd = new FormData();
  fd.append('action', 'test_key');
  fd.append('api_key', key);
  fd.append('model', model);

  fetch('ai_assistant.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.textContent = 'Test AI Connection';
      resBox.style.display = 'block';
      if (data.status === 'ok') {
        resBox.style.background = 'rgba(34, 197, 94, 0.15)';
        resBox.style.color = '#4ade80';
        resBox.style.border = '1px solid #22c55e';
        resBox.innerHTML = '✅ ' + data.message;
      } else {
        resBox.style.background = 'rgba(239, 68, 68, 0.15)';
        resBox.style.color = '#f87171';
        resBox.style.border = '1px solid #ef4444';
        resBox.innerHTML = '❌ ' + data.message;
      }
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.textContent = 'Test AI Connection';
      resBox.style.display = 'block';
      resBox.style.background = 'rgba(239, 68, 68, 0.15)';
      resBox.style.color = '#f87171';
      resBox.innerHTML = '❌ Network Error: ' + err.message;
    });
}
</script>
<?php layout_end();
