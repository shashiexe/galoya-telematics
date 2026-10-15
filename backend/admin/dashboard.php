<?php
require __DIR__ . '/_common.php';
require_admin();

// Fetch telematics data for dashboard
$all_units = q("SELECT id, name, code, unit_type, motion_state, speed, ignition, last_battery, last_lat, last_seen,
                TIMESTAMPDIFF(MINUTE, last_seen, NOW()) mins_ago
                FROM users WHERE role='staff' AND active=1")->fetchAll();

$total_units = count($all_units);

// 1. Health Status (based on battery level)
$healthy = 0;      // battery > 50%
$need_attention = 0; // battery 15% - 50%
$unhealthy = 0;    // battery < 15% or NULL

// 2. Connection Status (last seen <= 10 mins)
$online_count = 0;
$offline_count = 0;

// 3. Motion State
$stat_count = 0;
$stat_ign_count = 0;
$mov_count = 0;
$mov_ign_count = 0;
$no_state_count = 0;
$no_coords_count = 0;

foreach ($all_units as $u) {
    // Health
    $bat = $u['last_battery'];
    if ($bat !== null && $bat >= 50) $healthy++;
    elseif ($bat !== null && $bat >= 15) $need_attention++;
    else $unhealthy++;

    // Connection
    $mins = $u['mins_ago'];
    $is_online = ($mins !== null && (int)$mins <= 10);
    if ($is_online) $online_count++;
    else $offline_count++;

    // Motion State
    if ($u['last_lat'] === null) {
        $no_coords_count++;
    } elseif ($u['motion_state'] === 'moving') {
        if ($u['ignition']) $mov_ign_count++;
        else $mov_count++;
    } elseif ($u['motion_state'] === 'stationary' || $u['motion_state'] === 'idle') {
        if ($u['ignition']) $stat_ign_count++;
        else $stat_count++;
    } else {
        $no_state_count++;
    }
}

// Tasks summary
$tasks_prog = (int)q("SELECT COUNT(*) FROM tasks WHERE status='in_progress'")->fetchColumn();
$tasks_done = (int)q("SELECT COUNT(*) FROM tasks WHERE status='done' AND DATE(completed_at)=CURDATE()")->fetchColumn();
$tasks_pend = (int)q("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn();

// Helper to build SVG donut slice
function svg_donut(array $slices, int $size = 140, int $stroke = 28): string {
    $total = array_sum(array_column($slices, 'val'));
    if ($total <= 0) return '<circle cx="70" cy="70" r="50" fill="none" stroke="#e2e8f0" stroke-width="'.$stroke.'"/>';
    
    $radius = 50;
    $circ = 2 * M_PI * $radius;
    $offset = 0;
    $out = '';
    
    foreach ($slices as $s) {
        if ($s['val'] <= 0) continue;
        $pct = $s['val'] / $total;
        $dash = $pct * $circ;
        $space = $circ - $dash;
        $out .= sprintf(
            '<circle cx="70" cy="70" r="%f" fill="none" stroke="%s" stroke-width="%d" stroke-dasharray="%f %f" stroke-dashoffset="%f" />',
            $radius, $s['color'], $stroke, $dash, $space, -$offset
        );
        $offset += $dash;
    }
    return $out;
}

layout_start('Dashboard', 'dashboard.php');
?>
<!-- Sub-toolbar: Date filter matching Wialon Screenshot 2 -->
<div class="w-subbar">
  <div class="w-filter-group">
    <div class="w-btn-group">
      <button class="active">Today</button>
      <button>Yesterday</button>
      <button>Week</button>
      <button>Month</button>
    </div>
    
    <div class="w-date-input" style="margin-left:12px;">
      <span style="color:#64748b">From:</span>
      <input type="text" value="<?= date('d F Y', strtotime('-6 days')) ?>" style="width:125px">
      <span style="color:#64748b;margin-left:6px;">To:</span>
      <input type="text" value="<?= date('d F Y') ?>" style="width:125px">
      <button class="w-icon-btn primary" style="padding:4px 12px;margin-left:6px">Apply</button>
    </div>
  </div>

  <div style="font-size:12px;color:#64748b">
    <span>Telematics Node: <strong>Hingurana Primary</strong></span>
  </div>
</div>

<div class="w-dashboard">
  <!-- 3 Wialon Donut Cards (Screenshot 2) -->
  <div class="w-donut-grid">
    <!-- Card 1: Health check status -->
    <div class="w-donut-card">
      <div class="w-donut-card-header">
        <div class="w-donut-title">Health check status</div>
        <span class="w-online-tag">ONLINE DATA</span>
      </div>
      <div class="w-donut-body">
        <div class="w-donut-visual">
          <svg viewBox="0 0 140 140">
            <?= svg_donut([
              ['val' => $healthy, 'color' => '#2e7d32'],
              ['val' => $need_attention, 'color' => '#f57c00'],
              ['val' => $unhealthy, 'color' => '#d32f2f']
            ]) ?>
          </svg>
          <div class="w-donut-center">
            <span class="total-num"><?= $total_units ?></span>
            <span class="total-lbl">Total</span>
          </div>
        </div>
        <div class="w-donut-legend">
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#2e7d32"></span>
              <span>Healthy</span>
            </div>
            <span class="w-legend-count"><?= $healthy ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#f57c00"></span>
              <span>Need attention</span>
            </div>
            <span class="w-legend-count"><?= $need_attention ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#d32f2f"></span>
              <span>Unhealthy</span>
            </div>
            <span class="w-legend-count"><?= $unhealthy ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Card 2: Connection status -->
    <div class="w-donut-card">
      <div class="w-donut-card-header">
        <div class="w-donut-title">Connection status</div>
        <span class="w-online-tag">ONLINE DATA</span>
      </div>
      <div class="w-donut-body">
        <div class="w-donut-visual">
          <svg viewBox="0 0 140 140">
            <?= svg_donut([
              ['val' => $online_count, 'color' => '#2e7d32'],
              ['val' => $offline_count, 'color' => '#cfd8dc']
            ]) ?>
          </svg>
          <div class="w-donut-center">
            <span class="total-num"><?= $total_units ?></span>
            <span class="total-lbl">Total</span>
          </div>
        </div>
        <div class="w-donut-legend">
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#2e7d32"></span>
              <span>Online</span>
            </div>
            <span class="w-legend-count"><?= $online_count ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#cfd8dc"></span>
              <span>Offline</span>
            </div>
            <span class="w-legend-count"><?= $offline_count ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Card 3: Motion state -->
    <div class="w-donut-card">
      <div class="w-donut-card-header">
        <div class="w-donut-title">
          <span>Motion state</span>
          <span style="font-size:12px;color:#90a4ae;cursor:help" title="Real-time telematics movement detection">ⓘ</span>
        </div>
        <span class="w-online-tag">ONLINE DATA</span>
      </div>
      <div class="w-donut-body">
        <div class="w-donut-visual">
          <svg viewBox="0 0 140 140">
            <?= svg_donut([
              ['val' => $stat_count, 'color' => '#d32f2f'],
              ['val' => $stat_ign_count, 'color' => '#f57c00'],
              ['val' => $mov_count, 'color' => '#2e7d32'],
              ['val' => $mov_ign_count, 'color' => '#66bb6a'],
              ['val' => $no_state_count, 'color' => '#7b1fa2'],
              ['val' => $no_coords_count, 'color' => '#b0bec5']
            ]) ?>
          </svg>
          <div class="w-donut-center">
            <span class="total-num"><?= $total_units ?></span>
            <span class="total-lbl">Total</span>
          </div>
        </div>
        <div class="w-donut-legend">
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#d32f2f"></span>
              <span>Stationary</span>
            </div>
            <span class="w-legend-count"><?= $stat_count ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#f57c00"></span>
              <span>Stationary (Ignition on)</span>
            </div>
            <span class="w-legend-count"><?= $stat_ign_count ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#2e7d32"></span>
              <span>Moving</span>
            </div>
            <span class="w-legend-count"><?= $mov_count ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#66bb6a"></span>
              <span>Moving (Ignition on)</span>
            </div>
            <span class="w-legend-count"><?= $mov_ign_count ?></span>
          </div>
          <div class="w-legend-item">
            <div class="w-legend-left">
              <span class="w-color-swatch" style="background:#7b1fa2"></span>
              <span>No actual state</span>
            </div>
            <span class="w-legend-count"><?= $no_state_count ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Fleet & Operations Metrics -->
  <div class="w-metric-row">
    <div class="w-metric-card">
      <div class="w-metric-icon" style="background:#e8f5e9;color:#2e7d32">🚜</div>
      <div>
        <div class="w-metric-val"><?= $online_count ?> / <?= $total_units ?></div>
        <div class="w-metric-lbl">Fleet Units Active</div>
      </div>
    </div>
    <div class="w-metric-card">
      <div class="w-metric-icon" style="background:#e3f2fd;color:#1976d2">⏱️</div>
      <div>
        <div class="w-metric-val"><?= $tasks_prog ?></div>
        <div class="w-metric-lbl">Field Tasks in Progress</div>
      </div>
    </div>
    <div class="w-metric-card">
      <div class="w-metric-icon" style="background:#f3e5f5;color:#7b1fa2">✓</div>
      <div>
        <div class="w-metric-val"><?= $tasks_done ?></div>
        <div class="w-metric-lbl">Tasks Completed Today</div>
      </div>
    </div>
    <div class="w-metric-card">
      <div class="w-metric-icon" style="background:#fff3e0;color:#f57c00">⬡</div>
      <div>
        <div class="w-metric-val">2</div>
        <div class="w-metric-lbl">Active Geofence Zones</div>
      </div>
    </div>
  </div>

  <!-- Recent Units Live Table Preview -->
  <div class="w-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0;font-size:14px;color:#263238">Fleet Units Status Overview</h3>
      <a href="index.php" class="w-icon-btn primary">Open Live Monitoring Map →</a>
    </div>
    <table class="w-table">
      <thead>
        <tr>
          <th>Unit / Machine</th>
          <th>Type</th>
          <th>State</th>
          <th>Speed</th>
          <th>Ignition</th>
          <th>Battery</th>
          <th>Last Seen</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($all_units, 0, 8) as $u): ?>
          <tr>
            <td>
              <strong><?= h($u['name']) ?></strong>
              <?php if (!empty($u['code'])): ?>
                <span style="color:#64748b;font-size:11px;margin-left:4px">(<?= h($u['code']) ?>)</span>
              <?php endif; ?>
            </td>
            <td><span class="w-badge" style="background:#f1f5f9;color:#475569"><?= ucfirst(h($u['unit_type'])) ?></span></td>
            <td>
              <?php if ($u['motion_state'] === 'moving'): ?>
                <span style="color:#2e7d32;font-weight:600">● Moving</span>
              <?php elseif ($u['motion_state'] === 'stationary'): ?>
                <span style="color:#d32f2f;font-weight:600">● Stationary</span>
              <?php elseif ($u['motion_state'] === 'idle'): ?>
                <span style="color:#f57c00;font-weight:600">● Idle (Engine on)</span>
              <?php else: ?>
                <span style="color:#94a3b8">● Offline</span>
              <?php endif; ?>
            </td>
            <td><?= number_format((float)$u['speed'], 1) ?> km/h</td>
            <td>
              <?php if ($u['ignition']): ?>
                <span style="color:#2e7d32;font-weight:bold">ON</span>
              <?php else: ?>
                <span style="color:#94a3b8">OFF</span>
              <?php endif; ?>
            </td>
            <td><?= $u['last_battery'] !== null ? $u['last_battery'] . '%' : '–' ?></td>
            <td><?= $u['mins_ago'] === null ? 'Never' : ((int)$u['mins_ago'] < 1 ? 'Just now' : (int)$u['mins_ago'] . ' min ago') ?></td>
            <td>
              <a href="history.php?user=<?= $u['id'] ?>" class="w-icon-btn" style="padding:2px 8px;font-size:11px">Track</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_end(); ?>
