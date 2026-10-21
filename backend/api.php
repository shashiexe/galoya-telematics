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
    $token = '';
    if (preg_match('/Bearer\s+(\S+)/', $hdr, $m)) {
        $token = $m[1];
    } elseif (!empty($_REQUEST['token'])) {
        $token = trim($_REQUEST['token']);
    } elseif (!empty($_POST['token'])) {
        $token = trim($_POST['token']);
    } elseif (!empty($_GET['token'])) {
        $token = trim($_GET['token']);
    } else {
        $b = body();
        if (!empty($b['token'])) {
            $token = trim($b['token']);
        }
    }
    if (!$token) return null;
    $u = q('SELECT u.* FROM api_tokens t JOIN users u ON u.id=t.user_id
            WHERE t.token_hash=? AND u.active=1', [hash('sha256', $token)])->fetch();
    if (!$u) return null;
    q('UPDATE api_tokens SET last_used=NOW() WHERE token_hash=?', [hash('sha256', $token)]);
    return $u;
}

function auth_user(): array {
    $u = get_auth_user();
    if (!$u) fail('Unauthorized', 401);
    return $u;
}

function app_config(): array {
    $latestVersion = setting('latest_app_version', '1.3.5');
    $latestBuild = (int)setting('latest_app_build', 20);
    return [
        'location_interval' => (int)setting('location_interval', 60),
        'allow_staff_change_interval' => setting('allow_staff_change_interval', '1') === '1',
        'upload_batch_size' => (int)setting('upload_batch_size', 500),
        'app_version' => $latestVersion,
        'app_build' => $latestBuild,
        'apk_url' => 'https://track.galoya.lk/Galoya-Telematics.apk',
        'created_by' => 'GOPL IT',
    ];
}

$action = $_GET['action'] ?? '';
try {
    switch ($action) {

    case 'check_update': {
        $clientVersion = trim($_GET['version'] ?? ($_POST['version'] ?? '1.0.0'));
        $clientBuild = (int)($_GET['build'] ?? ($_POST['build'] ?? 1));
        
        $latestVersion = setting('latest_app_version', '1.3.5');
        $latestBuild = (int)setting('latest_app_build', 20);
        $apkUrl = 'https://track.galoya.lk/Galoya-Telematics.apk';
        $changelog = setting('app_update_changelog', "• Green boundary plantation plot identification\n• Standard plot details (e.g. D/15/14) and direct Google Maps navigation\n• Instant location pulse pin locator\n• Enhanced 24/7 background tracking");
        $forceUpdate = setting('app_force_update', '0') === '1';

        $hasUpdate = ($latestBuild > $clientBuild) || (version_compare($latestVersion, $clientVersion, '>'));

        out([
            'ok' => true,
            'has_update' => $hasUpdate,
            'latest_version' => $latestVersion,
            'latest_build' => $latestBuild,
            'apk_url' => $apkUrl,
            'changelog' => $changelog,
            'force_update' => $forceUpdate,
            'created_by' => 'GOPL IT',
        ]);
    }

    case 'login': {
        $b = body();
        $u = q('SELECT * FROM users WHERE phone=? AND active=1', [trim($b['phone'] ?? '')])->fetch();
        if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) fail('Phone number or password is wrong', 401);
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO api_tokens (user_id, token_hash) VALUES (?,?)', [$u['id'], hash('sha256', $token)]);

        // Capture phone model & unique ID from app or User-Agent
        $devModel = trim($b['device_model'] ?? ($b['phone_model'] ?? ''));
        $devId = trim($b['unique_id'] ?? ($b['imei'] ?? ($b['device_id'] ?? '')));
        if (!$devModel) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (preg_match('/Android\s+([0-9.]+);\s*([^;\)]+)/i', $ua, $m)) {
                $devModel = 'Android ' . trim($m[1]) . ' (' . trim($m[2]) . ')';
            } elseif (preg_match('/iPhone\s*OS\s*([0-9_]+)/i', $ua, $m)) {
                $devModel = 'Apple iPhone (iOS ' . str_replace('_', '.', $m[1]) . ')';
            } elseif (stripos($ua, 'Dart') !== false) {
                $devModel = 'Galoya Android Mobile App';
            } else {
                $devModel = 'Android Smartphone (' . ($u['name'] ?? 'Staff') . ')';
            }
        }
        if (!$devId) {
            $devId = !empty($u['unique_id']) ? $u['unique_id'] : ('8661920' . str_pad((string)$u['id'], 8, '0', STR_PAD_LEFT));
        }
        q('UPDATE users SET last_seen=NOW(), on_duty=1, device_type=?, unique_id=? WHERE id=?', [$devModel, $devId, $u['id']]);

        $unitType = $u['unit_type'] ?? 'staff';
        $designation = $u['designation'] ?? 'Field Officer';
        $code = !empty($u['code']) ? $u['code'] : '';

        out(['ok' => true, 'token' => $token,
             'user' => [
                 'id' => (int)$u['id'],
                 'name' => $u['name'],
                 'code' => $code,
                 'unit_type' => $unitType,
                 'designation' => $designation,
                 'phone' => $u['phone'],
                 'device_type' => $devModel,
                 'unique_id' => $devId
             ],
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
        
        $devModel = trim($_REQUEST['device_model'] ?? ($_POST['device_model'] ?? ''));
        $devId = trim($_REQUEST['unique_id'] ?? ($_POST['unique_id'] ?? ''));
        if (!$devModel) {
            $devModel = ($u['unit_type'] === 'staff') ? 'Android Phone (Samsung Galaxy S21)' : ($u['device_type'] ?: 'Teltonika FMB125');
        }
        if (!$devId) {
            $devId = !empty($u['unique_id']) ? $u['unique_id'] : (($u['unit_type'] === 'staff' ? '8661920' : '3505445') . str_pad((string)$u['id'], 8, '0', STR_PAD_LEFT));
        }

        // Mark online immediately & update device info
        q('UPDATE users SET last_seen=NOW(), on_duty=1, device_type=?, unique_id=? WHERE id=?', [$devModel, $devId, $u['id']]);
        
        out([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'code' => $u['code'],
                'phone' => $u['phone'],
                'unit_type' => $u['unit_type'],
                'device_type' => $devModel,
                'unique_id' => $devId
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

            // Stationary Anchor Check: Prevent indoor/roof GPS drift (solar panels, multipath) from faking motion
            if (!empty($u['last_lat']) && !empty($u['last_lng'])) {
                $dLat = deg2rad($latest['lat'] - (float)$u['last_lat']);
                $dLon = deg2rad($latest['lng'] - (float)$u['last_lng']);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad((float)$u['last_lat'])) * cos(deg2rad($latest['lat'])) * sin($dLon / 2) ** 2;
                $distMeters = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
                if ($distMeters < 25.0) {
                    $spd = 0.0;
                }
            }
            if ($spd < 4.0) {
                $spd = 0.0;
            }

            $motion = $spd > 0 ? 'moving' : 'stationary';
            $ign = $spd > 0 ? 1 : 1;
            
            $b = body();
            $devModel = trim($b['device_model'] ?? ($b['phone_model'] ?? ''));
            $devId = trim($b['unique_id'] ?? ($b['imei'] ?? ($b['device_id'] ?? '')));
            $trafficKb = isset($b['data_traffic_kb']) ? (int)$b['data_traffic_kb'] : null;

            if ($devModel && $devId) {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=?, last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, device_type=?, unique_id=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devModel, $devId, $u['id']]);
            } elseif ($devModel) {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=?, last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, device_type=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devModel, $u['id']]);
            } elseif ($devId) {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=?, last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, unique_id=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devId, $u['id']]);
            } else {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=?, last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1 WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $u['id']]);
            }

            if ($trafficKb !== null && $trafficKb >= 0) {
                q('UPDATE users SET gprs_traffic_val=? WHERE id=?', [$trafficKb, $u['id']]);
            }
        }
        db()->commit();
        out(['ok' => true, 'accepted' => $n, 'unit_id' => $u['id'], 'unit_name' => $u['name'], 'config' => app_config()]);
    }

    case 'duty': {
        // body: {client_id, status:'on'|'off', lat, lng, battery, event_type, note, t}
        $u = auth_user(); $b = body();
        $status = ($b['status'] ?? '') === 'on' ? 'on' : 'off';
        $dt = to_dt($b['t'] ?? time() * 1000);
        $lat = !empty($b['lat']) ? (float)$b['lat'] : null;
        $lng = !empty($b['lng']) ? (float)$b['lng'] : null;
        $bat = isset($b['battery']) ? (int)$b['battery'] : null;
        $eventType = trim($b['event_type'] ?? ($status === 'on' ? 'tracking_on' : 'tracking_off'));
        $note = trim($b['note'] ?? '');
        $cid = $b['client_id'] ?? bin2hex(random_bytes(8));

        try {
            $ins = q('INSERT IGNORE INTO duty_logs (user_id,client_id,status,lat,lng,battery,event_type,note,at) VALUES (?,?,?,?,?,?,?,?,?)',
                     [$u['id'], $cid, $status, $lat, $lng, $bat, $eventType, $note, $dt]);
        } catch (Throwable $e) {
            $ins = q('INSERT IGNORE INTO duty_logs (user_id,client_id,status,lat,lng,at) VALUES (?,?,?,?,?,?)',
                     [$u['id'], $cid, $status, $lat, $lng, $dt]);
        }

        if ($ins->rowCount()) {
            $last = q('SELECT status FROM duty_logs WHERE user_id=? ORDER BY at DESC, id DESC LIMIT 1', [$u['id']])->fetchColumn();
            q('UPDATE users SET on_duty=? WHERE id=?', [$last === 'on' ? 1 : 0, $u['id']]);
        }
        out(['ok' => true]);
    }

    case 'geofences_light': {
        // Returns lightweight centroids of active geofences for fast mobile proximity cache (< 50KB)
        auth_user();
        $rows = q("SELECT id, name, code, coordinates FROM geofences WHERE active=1 ORDER BY name ASC")->fetchAll();
        $light = [];
        foreach ($rows as $r) {
            $coords = json_decode($r['coordinates'] ?? '[]', true);
            if (!is_array($coords) || count($coords) < 3) continue;
            $sumLat = 0.0; $sumLng = 0.0; $n = count($coords);
            foreach ($coords as $c) {
                $sumLat += (float)$c[0];
                $sumLng += (float)$c[1];
            }
            $light[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'code' => $r['code'] ?: $r['name'],
                'lat' => round($sumLat / $n, 6),
                'lng' => round($sumLng / $n, 6),
            ];
        }
        out(['ok' => true, 'geofences' => $light, 'total' => count($light)]);
    }

    case 'geofences_nearby': {
        // High-precision nearest geofence query with spatial pre-filtering, point-in-polygon ray-casting and boundary segment distance
        auth_user();
        $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : (isset($_POST['lat']) ? (float)$_POST['lat'] : null);
        $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : (isset($_POST['lng']) ? (float)$_POST['lng'] : null);
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;

        if ($lat === null || $lng === null || $lat == 0.0 || $lng == 0.0) {
            fail('Missing or invalid lat/lng coordinates');
        }

        $rows = q("SELECT id, name, code, coordinates FROM geofences WHERE active=1")->fetchAll();
        $results = [];

        // 2-Phase scan: Phase 1 fast spatial bbox filter (< 0.04 deg ~ 4.4km), expands if few results
        $candidates = [];
        foreach ($rows as $r) {
            if (empty($r['coordinates'])) continue;
            $coords = json_decode($r['coordinates'], true);
            if (!is_array($coords) || count($coords) < 3) continue;

            $cLat0 = (float)$coords[0][0];
            $cLng0 = (float)$coords[0][1];
            if (abs($cLat0 - $lat) <= 0.045 && abs($cLng0 - $lng) <= 0.045) {
                $candidates[] = [$r, $coords];
            }
        }

        // Expand if needed
        if (count($candidates) < 5) {
            $candidates = [];
            foreach ($rows as $r) {
                if (empty($r['coordinates'])) continue;
                $coords = json_decode($r['coordinates'], true);
                if (!is_array($coords) || count($coords) < 3) continue;

                $cLat0 = (float)$coords[0][0];
                $cLng0 = (float)$coords[0][1];
                if (abs($cLat0 - $lat) <= 0.15 && abs($cLng0 - $lng) <= 0.15) {
                    $candidates[] = [$r, $coords];
                }
            }
        }

        foreach ($candidates as $cand) {
            $r = $cand[0];
            $coords = $cand[1];
            $inside = false;
            $n = count($coords);

            // Ray-casting point in polygon
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $xi = (float)$coords[$i][0]; $yi = (float)$coords[$i][1];
                $xj = (float)$coords[$j][0]; $yj = (float)$coords[$j][1];
                $diffY = $yj - $yi;
                if (abs($diffY) < 1e-10) $diffY = ($diffY < 0 ? -1e-10 : 1e-10);
                $intersect = (($yi > $lng) !== ($yj > $lng)) &&
                             ($lat < ($xj - $xi) * ($lng - $yi) / $diffY + $xi);
                if ($intersect) $inside = !$inside;
            }

            // Min distance to polygon boundary segments
            $minEdgeDist = INF;
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $aLat = (float)$coords[$j][0]; $aLng = (float)$coords[$j][1];
                $bLat = (float)$coords[$i][0]; $bLng = (float)$coords[$i][1];

                $dLat = $bLat - $aLat;
                $dLng = $bLng - $aLng;
                $denom = ($dLat * $dLat) + ($dLng * $dLng);
                if ($denom < 1e-12) {
                    $pLat = $aLat; $pLng = $aLng;
                } else {
                    $t = ((($lat - $aLat) * $dLat) + (($lng - $aLng) * $dLng)) / $denom;
                    $t = max(0.0, min(1.0, $t));
                    $pLat = $aLat + ($t * $dLat);
                    $pLng = $aLng + ($t * $dLng);
                }

                $d1 = deg2rad($lat - $pLat);
                $d2 = deg2rad($lng - $pLng);
                $val = sin($d1 / 2) ** 2 + cos(deg2rad($pLat)) * cos(deg2rad($lat)) * sin($d2 / 2) ** 2;
                $val = max(0.0, min(1.0, $val));
                $dist = 6371000 * 2 * atan2(sqrt($val), sqrt(max(0.0, 1.0 - $val)));
                if ($dist < $minEdgeDist) $minEdgeDist = $dist;
            }

            $sumLat = 0.0; $sumLng = 0.0;
            foreach ($coords as $c) { $sumLat += (float)$c[0]; $sumLng += (float)$c[1]; }

            $finalDist = is_finite($minEdgeDist) ? round($minEdgeDist, 1) : 99999.0;
            $results[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'code' => $r['code'] ?: $r['name'],
                'lat' => round($sumLat / $n, 6),
                'lng' => round($sumLng / $n, 6),
                'is_inside' => $inside,
                'edge_distance' => $finalDist,
                'distance' => $inside ? 0.0 : $finalDist,
                'coordinates' => $coords,
            ];
        }

        usort($results, function($a, $b) {
            if ($a['is_inside'] && !$b['is_inside']) return -1;
            if (!$a['is_inside'] && $b['is_inside']) return 1;
            return $a['distance'] <=> $b['distance'];
        });

        $top = array_slice($results, 0, $limit);
        out(['ok' => true, 'geofences' => $top, 'count' => count($top)]);
    }

    case 'field_photos':
    case 'my_field_photos': {
        // Fetch field photos for logged-in user on a selected date (or today)
        $u = get_auth_user();
        $date = trim($_GET['date'] ?? ($_POST['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        
        if ($u && !empty($u['id'])) {
            $photos = q("SELECT id, file_path, lat, lng, note, geofence_name, activity_type, taken_at,
                         CONCAT('https://track.galoya.lk/uploads/', file_path) AS full_url
                         FROM field_photos 
                         WHERE user_id=? AND DATE(taken_at)=?
                         ORDER BY id DESC", [$u['id'], $date])->fetchAll();
        } else {
            $photos = q("SELECT id, user_id, file_path, lat, lng, note, geofence_name, activity_type, taken_at,
                         CONCAT('https://track.galoya.lk/uploads/', file_path) AS full_url
                         FROM field_photos 
                         WHERE DATE(taken_at)=?
                         ORDER BY id DESC LIMIT 100", [$date])->fetchAll();
        }
        foreach ($photos as &$p) {
            if (isset($p['lat']) && $p['lat'] !== null && $p['lat'] !== '') $p['lat'] = (float)$p['lat'];
            if (isset($p['lng']) && $p['lng'] !== null && $p['lng'] !== '') $p['lng'] = (float)$p['lng'];
        }
        unset($p);
        out(['ok' => true, 'date' => $date, 'photos' => $photos, 'count' => count($photos)]);
    }

    case 'plot_polygon': {
        // Query full polygon boundary coordinates on-demand for a searched plot (keeps mobile app tiny)
        $name = trim($_GET['name'] ?? ($_POST['name'] ?? ''));
        if (!$name) fail('Plot name required', 400);

        $row = q("SELECT id, name, code, type, coordinates, color FROM geofences WHERE name = ? OR code = ? OR name LIKE ? LIMIT 1",
                 [$name, $name, "%$name%"])->fetch();
        if (!$row) {
            fail('Plot polygon not found', 404);
        }
        $coords = json_decode($row['coordinates'] ?? '[]', true);
        $sumLat = 0.0; $sumLng = 0.0; $n = is_array($coords) ? count($coords) : 0;
        if ($n > 0) {
            foreach ($coords as $c) { $sumLat += (float)$c[0]; $sumLng += (float)$c[1]; }
        }
        out([
            'ok' => true,
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'type' => $row['type'],
            'color' => $row['color'] ?: '#2563eb',
            'lat' => $n > 0 ? round($sumLat / $n, 6) : 0.0,
            'lng' => $n > 0 ? round($sumLng / $n, 6) : 0.0,
            'coordinates' => $coords
        ]);
    }

    case 'sos': {
        // body: {lat, lng, battery, unit_id?, user_id?}
        $u = get_auth_user();
        if (!$u) {
            $b = body();
            $simId = (int)($b['unit_id'] ?? ($_GET['unit_id'] ?? ($b['user_id'] ?? ($_GET['user_id'] ?? 0))));
            if ($simId > 0) {
                $u = q('SELECT * FROM users WHERE id=? AND active=1', [$simId])->fetch();
            }
        }
        if (!$u) fail('Unauthorized', 401);

        $b = body();
        $lat = (float)($b['lat'] ?? $u['last_lat'] ?? 7.2095);
        $lng = (float)($b['lng'] ?? $u['last_lng'] ?? 81.6575);
        $bat = isset($b['battery']) ? (int)$b['battery'] : null;

        try {
            q("INSERT INTO sos_alerts (user_id, lat, lng, battery, status) VALUES (?,?,?,?,'active')",
              [$u['id'], $lat, $lng, $bat]);
        } catch (Throwable $e) {
            error_log("SOS alert insert error: " . $e->getMessage());
        }

        try {
            q("UPDATE users SET sos_active=1, last_lat=?, last_lng=?, last_seen=NOW(), on_duty=1 WHERE id=?",
              [$lat, $lng, $u['id']]);
        } catch (Throwable $e) {}

        // Add to notifications table for real-time alarm center
        try {
            q("INSERT INTO notifications (unit_id, type, title, message, lat, lng, is_read) 
               VALUES (?, 'sos', '🆘 SOS Panic Button Dispatched', ?, ?, ?, 0)",
              [$u['id'], "Emergency alarm triggered from mobile app on " . $u['name'], $lat, $lng]);
        } catch (Throwable $e) {}

        out([
            'ok' => true,
            'message' => 'EMERGENCY SOS transmitted to Control Room',
            'unit_id' => (int)$u['id'],
            'unit_name' => $u['name']
        ]);
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
        $date = trim($_GET['date'] ?? ($_POST['date'] ?? ''));
        $history = !empty($_GET['history']) || !empty($_POST['history']);
        $rows = [];

        $where = ["t.assigned_to = ?"];
        $params = [$u['id']];

        if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = "(DATE(t.due_date) = ? OR DATE(t.completed_at) = ? OR DATE(t.created_at) = ?)";
            $params[] = $date;
            $params[] = $date;
            $params[] = $date;
        } elseif ($history) {
            $where[] = "t.status IN ('done', 'cancelled')";
        } else {
            $where[] = "(t.status IN ('pending','in_progress') OR t.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
        }

        $whereSql = implode(' AND ', $where);

        try {
            $rows = q("SELECT t.*, s.name section_name, d.name department_name,
                         gf.name geofence_name, gf.code geofence_code, gf.coordinates geofence_coords,
                         (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='before' ORDER BY id DESC LIMIT 1) before_photo,
                         (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='after' ORDER BY id DESC LIMIT 1) after_photo
                       FROM tasks t 
                       LEFT JOIN sections s ON s.id=t.section_id
                       LEFT JOIN departments d ON d.id=t.department_id
                       LEFT JOIN geofences gf ON gf.id=t.geofence_id
                       WHERE $whereSql
                       ORDER BY FIELD(t.status,'in_progress','pending','done','cancelled'), t.due_date IS NULL, t.due_date, t.id DESC",
                      $params)->fetchAll();
        } catch (Throwable $e) {
            $rows = q("SELECT t.*, 
                         (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='before' ORDER BY id DESC LIMIT 1) before_photo,
                         (SELECT file_path FROM task_photos WHERE task_id=t.id AND type='after' ORDER BY id DESC LIMIT 1) after_photo
                       FROM tasks t WHERE $whereSql
                       ORDER BY FIELD(t.status,'in_progress','pending','done','cancelled'), t.due_date IS NULL, t.due_date, t.id DESC",
                      $params)->fetchAll();
        }
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
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed (code ' . ($f['error'] ?? 'none') . ')');
        if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
        $validTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (defined('IMAGETYPE_WEBP')) $validTypes[] = IMAGETYPE_WEBP;
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], $validTypes, true)) fail('Invalid image');
        $ext = 'jpg';
        if ($info[2] === IMAGETYPE_PNG) $ext = 'png';
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP) $ext = 'webp';

        $sub = date('Y/m');
        $dir = $CONFIG['upload_dir'] . '/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $cid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['client_id'] ?? '') ?: bin2hex(random_bytes(6));
        $name = "field_{$u['id']}_{$cid}.{$ext}";
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save photo', 500);

        $dt = to_dt($_POST['t'] ?? time() * 1000);
        $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : null;
        $note = trim($_POST['note'] ?? '');
        $activityType = trim($_POST['activity_type'] ?? 'Field Visit');
        $autoGeofence = trim($_POST['auto_geofence'] ?? '');
        $selectedGeofence = trim($_POST['selected_geofence'] ?? '');
        $geofence = $selectedGeofence ?: ($autoGeofence ?: find_nearest_geofence($lat, $lng));

        insert_field_photo($u['id'], "$sub/$name", $lat, $lng, $note, $geofence, $dt, $activityType, $autoGeofence, $selectedGeofence);

        out(['ok' => true, 'file_path' => "$sub/$name", 'geofence' => $geofence, 'activity_type' => $activityType]);
    }

    case 'task_photo': {
        // multipart: task_id, type(before|after), client_id, lat, lng, t, note, photo(file)
        global $CONFIG;
        $u = auth_user();
        $taskId = (int)($_POST['task_id'] ?? 0);
        $type = ($_POST['type'] ?? '') === 'after' ? 'after' : 'before';
        $cid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['client_id'] ?? '') ?: bin2hex(random_bytes(6));

        // If no task_id or 0: instantly save as general field photo with nearest geofence
        if (!$taskId) {
            $f = $_FILES['photo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed');
            if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Invalid image');
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';

            $sub = date('Y/m');
            $dir = $CONFIG['upload_dir'] . '/' . $sub;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $name = "field_{$u['id']}_{$cid}.{$ext}";
            if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save photo', 500);

            $dt = to_dt($_POST['t'] ?? time() * 1000);
            $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : null;
            $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : null;
            $note = trim($_POST['note'] ?? '');
            $geofence = find_nearest_geofence($lat, $lng);

            insert_field_photo($u['id'], "$sub/$name", $lat, $lng, $note, $geofence, $dt);

            out(['ok' => true, 'file_path' => "$sub/$name", 'geofence' => $geofence]);
        }

        $task = q('SELECT * FROM tasks WHERE id=? AND assigned_to=?', [$taskId, $u['id']])->fetch();
        if (!$task) fail('Task not found', 404);

        // Already uploaded (retry from offline queue)? -> success.
        if (q('SELECT id FROM task_photos WHERE client_id=?', [$cid])->fetchColumn()) out(['ok' => true, 'duplicate' => true]);

        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed');
        if ($f['size'] > $CONFIG['max_photo_mb'] * 1048576) fail('Photo too large');
        $validTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (defined('IMAGETYPE_WEBP')) $validTypes[] = IMAGETYPE_WEBP;
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], $validTypes, true)) fail('Invalid image');
        $ext = 'jpg';
        if ($info[2] === IMAGETYPE_PNG) $ext = 'png';
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP) $ext = 'webp';

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
        $clearAll = !empty($_GET['all']) || !empty($_POST['all']);

        if ($clearAll) {
            q("UPDATE sos_alerts SET status='resolved' WHERE status='active'");
            q("UPDATE users SET sos_active=0");
            out(['ok' => true, 'cleared_all' => true]);
        }

        if ($id > 0) {
            $alert = q('SELECT * FROM sos_alerts WHERE id=?', [$id])->fetch();
            if ($alert && !$uid) {
                $uid = (int)$alert['user_id'];
            }
            q("UPDATE sos_alerts SET status='resolved' WHERE id=?", [$id]);
        }
        if ($uid > 0) {
            q("UPDATE sos_alerts SET status='resolved' WHERE user_id=? AND status='active'", [$uid]);
            q("UPDATE users SET sos_active=0 WHERE id=?", [$uid]);
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
    fail('Server error: ' . $e->getMessage() . ' on line ' . $e->getLine(), 500);
}
