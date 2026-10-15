<?php
require 'backend/bootstrap.php';
// Reset all staff units to offline (last seen 30 mins ago, speed 0, stationary)
q("UPDATE users SET last_seen = DATE_SUB(NOW(), INTERVAL 30 MINUTE), speed = 0, ignition = 0, motion_state = 'stationary', sos_active = 0 WHERE role = 'staff'");

$off = (int)$CONFIG['offline_after_min'];
$online = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$off])->fetchColumn();
$total = (int)q("SELECT COUNT(*) FROM users WHERE role='staff' AND active=1")->fetchColumn();

echo "State 1 (App Closed): Online = $online / $total" . PHP_EOL;
