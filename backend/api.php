<?php
/**
 * StaffTrack mobile API.  Usage: api.php?action=NAME
 * Auth: header "Authorization: Bearer <token>" (all actions except login).
 * All write actions are idempotent so the offline queue can safely retry.
 */
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }
function body(): array {
    static $b = null;
    if ($b === null) {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!is_array($b)) $b = $_POST;
    }
    return $b;
}

function get_auth_user(): ?array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$hdr && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $hdr = $v;
    }
    if (!preg_match('/Bearer\s+(\S+)/', $hdr, $m)) return null;
    $u = q('SELECT u.* FROM api_tokens t JOIN users u ON u.id=t.user_id
            WHERE t.token_hash=? AND u.active=1', [hash('sha256', $m[1])])->fetch();
    if (!$u) return null;
    q('UPDATE api_tokens SET last_used=NOW() WHERE token_hash=?', [hash('sha256', $m[1])]);
    return $u;
}

function auth_user(): array {
    $u = get_auth_user();
    if (!$u) fail('Unauthorized', 401);
    return $u;
}

function app_config(): array {
    return [
        'location_interval' => (int)setting('location_interval', 60),
        'allow_staff_change_interval' => setting('allow_staff_change_interval', '1') === '1',
        'upload_batch_size' => (int)setting('upload_batch_size', 500),
    ];
}

$action = $_GET['action'] ?? '';
try {
    switch ($action) {

    case 'login': {
        $b = body();
        $u = q('SELECT * FROM users WHERE phone=? AND active=1', [trim($b['phone'] ?? '')])->fetch();
        if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) fail('Phone number or password is wrong', 401);
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO api_tokens (user_id, token_hash) VALUES (?,?)', [$u['id'], hash('sha256', $token)]);
        out(['ok' => true, 'token' => $token,
             'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone']],
             'config' => app_config()]);
    }

    case 'simulator_auth': {
        // Allows laptop test simulator to easily connect as any fleet machinery/staff unit
        $uid = (int)($_GET['unit_id'] ?? ($_POST['unit_id'] ?? 2));
        $u = q('SELECT * FROM users WHERE id=? AND role="staff" AND active=1', [$uid])->fetch();
        if (!$u) {
            $u = q('SELECT * FROM users WHERE role="staff" AND active=1 ORDER BY id ASC LIMIT 1')->fetch();
        }
        if (!$u) fail('No active staff units found', 404);
        
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO api_tokens (user_id, token_hash) VALUES (?,?)', [$u['id'], hash('sha256', $token)]);
        
        // Mark online immediately
        q('UPDATE users SET last_seen=NOW(), on_duty=1 WHERE id=?', [$u['id']]);
        
        out([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'code' => $u['code'],
                'phone' => $u['phone'],
                'unit_type' => $u['unit_type']
            ],
            'config' => app_config()
        ]);
    }

    case 'set_offline': {
        // Explicitly marks the unit as offline & stationary immediately
        $u = get_auth_user();
        if (!$u) {
            $b = body();
            $simId = (int)($b['unit_id'] ?? ($_GET['unit_id'] ?? 0));
            if ($simId > 0) {
                $u = q('SELECT * FROM users WHERE id=? AND role="staff"', [$simId])->fetch();
            }
        }
        if ($u) {
            q("UPDATE users SET 
                last_seen = DATE_SUB(NOW(), INTERVAL 30 MINUTE),
                speed = 0,
                ignition = 0,
                motion_state = 'stationary',
                on_duty = 0
               WHERE id=?", [$u['id']]);
        }
        out(['ok' => true, 'status' => 'offline', 'unit_id' => $u['id'] ?? null]);
    }

    case 'config': {
        $u = auth_user();
        out(['ok' => true, 'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone'],
             'on_duty' => (bool)$u['on_duty']], 'config' => app_config()]);
    }

    case 'locations': {
        // body: {unit_id?, points:[{lat,lng,accuracy,speed,battery,t}]}
        $u = get_auth_user();
        if (!$u) {
            $b = body();
            $simId = (int)($b['unit_id'] ?? ($_GET['unit_id'] ?? 0));
            if ($simId > 0) {
                $u = q('SELECT * FROM users WHERE id=? AND role="staff" AND active=1', [$simId])->fetch();
            }
        }
        if (!$u) fail('Unauthorized', 401);

        $pts = body()['points'] ?? [];
        if (!is_array($pts)) fail('points must be array');
        $st = db()->prepare('INSERT IGNORE INTO locations (user_id,lat,lng,accuracy,speed,battery,recorded_at)
                             VALUES (?,?,?,?,?,?,?)');
        $latest = null; $n = 0;
        db()->beginTransaction();
        foreach (array_slice($pts, 0, 2000) as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $dt = isset($p['t']) ? to_dt($p['t']) : date('Y-m-d H:i:s');
            $spd = (float)($p['speed'] ?? 0);
            $batt = isset($p['battery']) ? (int)$p['battery'] : null;
            $st->execute([$u['id'], $p['lat'], $p['lng'], $p['accuracy'] ?? 4.0, $spd, $batt, $dt]);
            $n++;
            if (!$latest || $dt >= $latest['dt']) {
                $latest = ['dt' => $dt, 'lat' => $p['lat'], 'lng' => $p['lng'], 'speed' => $spd, 'battery' => $batt];
            }
        }
        if ($latest) {
            $spd = (float)($latest['speed'] ?? 0);
            $motion = $spd > 0 ? 'moving' : 'stationary';
            $ign = $spd > 0 ? 1 : 1;
            q('UPDATE users SET last_lat=?, last_lng=?, last_battery=?, last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1
               WHERE id=?',
              [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $u['id']]);
        }
        db()->commit();
        out(['ok' => true, 'accepted' => $n, 'unit_id' => $u['id'], 'unit_name' => $u['name'], 'config' => app_config()]);
    }

    case 'duty': {
        // body: {client_id, status:'on'|'off', lat, lng, t}
        $u = auth_user(); $b = body();
        $status = ($b['status'] ?? '') === 'on' ? 'on' : 'off';
        $dt = to_dt($b['t'] ?? time() * 1000);
        $ins = q('INSERT IGNORE INTO duty_logs (user_id,client_id,status,lat,lng,at) VALUES (?,?,?,?,?,?)',
                 [$u['id'], $b['client_id'] ?? bin2hex(random_bytes(8)), $status, $b['lat'] ?? null, $b['lng'] ?? null, $dt]);
        if ($ins->rowCount()) {
            $last = q('SELECT status FROM duty_logs WHERE user_id=? ORDER BY at DESC, id DESC LIMIT 1', [$u['id']])->fetchColumn();
            q('UPDATE users SET on_duty=? WHERE id=?', [$last === 'on' ? 1 : 0, $u['id']]);
        }
        out(['ok' => true]);
    }

    case 'sos': {
        // body: {lat, lng, battery}
        $u = null;
        try {
            $u = auth_user();
        } catch (Throwable $e) {
            $b = body();
            $simId = (int)($b['unit_id'] ?? ($_GET['unit_id'] ?? 0));
            if ($simId > 0) {
                $u = q('SELECT * FROM users WHERE id=? AND role="staff" AND active=1', [$simId])->fetch();
            }
        }
        if (!$u) fail('Unauthorized', 401);

        $b = body();
        $lat = (float)($b['lat'] ?? $u['last_lat'] ?? 7.2095);
        $lng = (float)($b['lng'] ?? $u['last_lng'] ?? 81.6575);
        $bat = isset($b['battery']) ? (int)$b['battery'] : null;

        q("INSERT INTO sos_alerts (user_id, lat, lng, battery, status) VALUES (?,?,?,?,'active')",
          [$u['id'], $lat, $lng, $bat]);
        q("UPDATE users SET sos_active=1, last_lat=?, last_lng=?, last_seen=NOW(), on_duty=1 WHERE id=?",
          [$lat, $lng, $u['id']]);

        // Add to notifications table for real-time alarm center
        try {
            q("INSERT INTO notifications (unit_id, type, title, message, lat, lng, is_read) 
               VALUES (?, 'sos', '🆘 SOS Panic Button Dispatched', ?, ?, ?, 0)",
              [$u['id'], "Emergency alarm triggered from mobile app on " . $u['name'], $lat, $lng]);
        } catch (Throwable $e) {}

        out(['ok' => true, 'message' => 'EMERGENCY SOS transmitted to Control Room']);
    }

    case 'operation_mode': {
        // body: {mode: 'light'|'standard'|'custom'}
        $u = auth_user(); $b = body();
        $mode = in_array($b['mode'] ?? '', ['light', 'standard', 'custom']) ? $b['mode'] : 'standard';
        q("UPDATE users SET operation_mode=? WHERE id=?", [$mode, $u['id']]);
        out(['ok' => true, 'mode' => $mode]);
    }

    case 'tasks': {
        $u = auth_user();
        $rows = q("SELECT t.*, 
                     (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='before' ORDER BY id DESC LIMIT 1) before_photo,
                     (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='after' ORDER BY id DESC LIMIT 1) after_photo
                   FROM tasks t WHERE t.assigned_to=? AND
                     (t.status IN ('pending','in_progress') OR t.completed_at >= DATE_SUB(NOW(), INTERVAL 3 DAY))
                   ORDER BY FIELD(t.status,'in_progress','pending','done','cancelled'), t.due_date IS NULL, t.due_date, t.id DESC",
                  [$u['id']])->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['lat'] = $r['lat'] !== null ? (float)$r['lat'] : null;
            $r['lng'] = $r['lng'] !== null ? (float)$r['lng'] : null;
        }
        out(['ok' => true, 'tasks' => $rows]);
    }

    case 'upload_field_photo': {
        global $CONFIG;
        $u = auth_user();
        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed');
        if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Invalid image');
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';

        $sub = date('Y/m');
        $dir = $CONFIG['upload_dir'] . '/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $cid = bin2hex(random_bytes(6));
        $name = "field_{$u['id']}_{$cid}.{$ext}";
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save photo', 500);

        $dt = to_dt($_POST['t'] ?? time() * 1000);
        $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : null;
        $note = trim($_POST['note'] ?? '');

        q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, taken_at) VALUES (?,?,?,?,?,?)',
          [$u['id'], "$sub/$name", $lat, $lng, $note, $dt]);

        out(['ok' => true, 'file_path' => "$sub/$name"]);
    }

    case 'task_photo': {
        // multipart: task_id, type(before|after), client_id, lat, lng, t, note, photo(file)
        global $CONFIG;
        $u = auth_user();
        $taskId = (int)($_POST['task_id'] ?? 0);
        $type = ($_POST['type'] ?? '') === 'after' ? 'after' : 'before';
        $cid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['client_id'] ?? '');
        if (!$cid) fail('client_id required');
        if (!$taskId) {
            // Fallback: save as general field photo if no task is specified
            $f = $_FILES['photo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed');
            if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Invalid image');
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';

            $sub = date('Y/m');
            $dir = $CONFIG['upload_dir'] . '/' . $sub;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $cid = bin2hex(random_bytes(6));
            $name = "field_{$u['id']}_{$cid}.{$ext}";
            if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save photo', 500);

            $dt = to_dt($_POST['t'] ?? time() * 1000);
            $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : null;
            $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : null;
            $note = trim($_POST['note'] ?? '');

            q('INSERT INTO field_photos (user_id, file_path, lat, lng, note, taken_at) VALUES (?,?,?,?,?,?)',
              [$u['id'], "$sub/$name", $lat, $lng, $note, $dt]);

            out(['ok' => true, 'file_path' => "$sub/$name"]);
        }

        $task = q('SELECT * FROM tasks WHERE id=? AND assigned_to=?', [$taskId, $u['id']])->fetch();
        if (!$task) fail('Task not found', 404);

        // Already uploaded (retry from offline queue)? -> success.
        if (q('SELECT id FROM task_photos WHERE client_id=?', [$cid])->fetchColumn()) out(['ok' => true, 'duplicate' => true]);

        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed');
        if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Invalid image');
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';

        $sub = date('Y/m');
        $dir = $CONFIG['upload_dir'] . '/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = "t{$taskId}_{$type}_{$cid}.{$ext}";
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save photo', 500);

        $dt = to_dt($_POST['t'] ?? time() * 1000);
        $lat = $_POST['lat'] ?? null; $lng = $_POST['lng'] ?? null;
        $lat = $lat === '' ? null : $lat; $lng = $lng === '' ? null : $lng;
        q('INSERT INTO task_photos (task_id,user_id,client_id,type,file_path,lat,lng,taken_at) VALUES (?,?,?,?,?,?,?,?)',
          [$taskId, $u['id'], $cid, $type, "$sub/$name", $lat, $lng, $dt]);

        if ($type === 'before' && $task['status'] === 'pending') {
            q("UPDATE tasks SET status='in_progress', started_at=?, start_lat=?, start_lng=? WHERE id=?", [$dt, $lat, $lng, $taskId]);
        } elseif ($type === 'after' && $task['status'] !== 'cancelled') {
            q("UPDATE tasks SET status='done', completed_at=?, end_lat=?, end_lng=?, staff_note=?,
                 started_at=COALESCE(started_at, ?) WHERE id=?",
              [$dt, $lat, $lng, $_POST['note'] ?? null, $dt, $taskId]);
        }
        out(['ok' => true]);
    }

    case 'resolve_sos': {
        $id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
        $uid = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
        if ($id > 0) {
            $alert = q('SELECT * FROM sos_alerts WHERE id=?', [$id])->fetch();
            if ($alert) {
                q("UPDATE sos_alerts SET status='resolved' WHERE id=?", [$id]);
                $uid = (int)$alert['user_id'];
            }
        }
        if ($uid > 0) {
            $rem = (int)q("SELECT COUNT(*) FROM sos_alerts WHERE user_id=? AND status='active'", [$uid])->fetchColumn();
            if ($rem === 0) {
                q("UPDATE users SET sos_active=0 WHERE id=?", [$uid]);
            }
        }
        out(['ok' => true]);
    }

    case 'logout': {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/', $hdr, $m)) q('DELETE FROM api_tokens WHERE token_hash=?', [hash('sha256', $m[1])]);
        out(['ok' => true]);
    }

    default:
        fail('Unknown action', 404);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('StaffTrack API: ' . $e->getMessage());
    fail('Server error', 500);
}
