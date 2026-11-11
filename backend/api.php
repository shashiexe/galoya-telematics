<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
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
    $latestVersion = setting('latest_app_version', '1.4.6');
    $latestBuild = (int)setting('latest_app_build', 36);
    $apkUrl = setting('apk_download_url', 'https://track.galoya.lk/Galoya-Telematics.apk');
    return [
        'location_interval' => (int)setting('location_interval', 15),
        'allow_staff_change_interval' => setting('allow_staff_change_interval', '1') === '1',
        'upload_batch_size' => (int)setting('upload_batch_size', 500),
        'app_version' => $latestVersion,
        'app_build' => $latestBuild,
        'apk_url' => $apkUrl,
        'created_by' => 'GOPL IT',
    ];
}

$action = $_GET['action'] ?? '';
try {
    switch ($action) {

    case 'check_update': {
        $clientVersion = trim($_GET['version'] ?? ($_POST['version'] ?? '1.0.0'));
        $clientBuild = (int)($_GET['build'] ?? ($_POST['build'] ?? 1));
        
        $latestVersion = setting('latest_app_version', '1.4.6');
        $latestBuild = (int)setting('latest_app_build', 36);
        $apkUrl = setting('apk_download_url', 'https://track.galoya.lk/Galoya-Telematics.apk');
        $changelog = setting('app_update_changelog', "🌾 PLOT NAVIGATOR, NOMINEE & ACTIVE CROP UPDATE\n\n• Instant Plot Autocomplete & Suggestions dropdown in CDN form\n• Auto-fills Farmer Name, NIC, Sector & Land Area\n• GPS Nearest Plot auto-detection button\n• Factory Weighbridge Live Delivery Tracker\n• 100% Offline SQLite caching");
        $forceUpdate = setting('app_force_update', '1') === '1';

        $hasUpdate = ($latestBuild > $clientBuild) || (version_compare($latestVersion, $clientVersion, '>'));

        // Skip update prompt for 1.4.5 (build 31) because field officers are currently running a test trial on it:
        if ($clientVersion === '1.4.5' || $clientBuild === 31) {
            $hasUpdate = false;
        }

        out([
            'ok' => true,
            'has_update' => $hasUpdate,
            'latest_version' => $latestVersion,
            'latest_build' => $latestBuild,
            'apk_url' => $apkUrl,
            'changelog' => $changelog,
            'force_update' => $forceUpdate && $hasUpdate,
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
        $faceEnrolled = !empty($u['profile_photo']) && ((int)($u['face_enrolled'] ?? 0) === 1);

        out(['ok' => true, 'token' => $token,
             'user' => [
                 'id' => (int)$u['id'],
                 'name' => $u['name'],
                 'code' => $code,
                 'unit_type' => $unitType,
                 'designation' => $designation,
                 'phone' => $u['phone'],
                 'device_type' => $devModel,
                 'unique_id' => $devId,
                 'face_enrolled' => $faceEnrolled,
                 'profile_photo' => $u['profile_photo'] ?? null
             ],
             'config' => app_config()]);
    }

    case 'simulator_auth': {
        // Disabled in production so test simulator never touches real staff accounts
        out(['ok' => false, 'error' => 'Simulator disabled in production']);
    }

    case 'set_offline': {
        // Explicitly marks the unit as offline & stationary immediately
        $u = get_auth_user();
        if ($u) {
            q("UPDATE users SET 
                last_seen = NOW(),
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
        $faceEnrolled = !empty($u['profile_photo']) && ((int)($u['face_enrolled'] ?? 0) === 1);
        out(['ok' => true, 'user' => [
            'id' => (int)$u['id'],
            'name' => $u['name'],
            'phone' => $u['phone'],
            'on_duty' => (bool)$u['on_duty'],
            'face_enrolled' => $faceEnrolled,
            'profile_photo' => $u['profile_photo'] ?? null,
        ], 'config' => app_config()]);
    }

    case 'locations': {
        // body: {unit_id?, points:[{lat,lng,accuracy,speed,battery,t}]}
        $u = get_auth_user();
        if (!$u) fail('Unauthorized', 401);

        $pts = body()['points'] ?? [];
        if (!is_array($pts)) fail('points must be array');
        $st = db()->prepare('INSERT IGNORE INTO locations (user_id,lat,lng,accuracy,speed,battery,recorded_at)
                             VALUES (?,?,?,?,?,?,?)');
        $latest = null; $n = 0;
        db()->beginTransaction();
        foreach (array_slice($pts, 0, 2000) as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            // Ignore any leftover web simulator dummy packets (lat=7.20950, lng=81.65750, accuracy=4.2)
            if (abs((float)$p['lat'] - 7.20950) < 0.0005 && abs((float)$p['lng'] - 81.65750) < 0.0005 && abs((float)($p['accuracy'] ?? 0) - 4.2) < 0.05) {
                continue;
            }
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
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=COALESCE(?, last_battery), last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, device_type=?, unique_id=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devModel, $devId, $u['id']]);
            } elseif ($devModel) {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=COALESCE(?, last_battery), last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, device_type=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devModel, $u['id']]);
            } elseif ($devId) {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=COALESCE(?, last_battery), last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1, unique_id=? WHERE id=?',
                  [$latest['lat'], $latest['lng'], $latest['battery'], $spd, $motion, $ign, $devId, $u['id']]);
            } else {
                q('UPDATE users SET last_lat=?, last_lng=?, last_battery=COALESCE(?, last_battery), last_seen=NOW(), speed=?, motion_state=?, ignition=?, on_duty=1 WHERE id=?',
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
            if ($bat !== null && $bat > 0) {
                q('UPDATE users SET on_duty=?, last_battery=?, last_seen=NOW() WHERE id=?', [$last === 'on' ? 1 : 0, $bat, $u['id']]);
            } else {
                q('UPDATE users SET on_duty=?, last_seen=NOW() WHERE id=?', [$last === 'on' ? 1 : 0, $u['id']]);
            }
        }
        out(['ok' => true]);
    }

    case 'geofences_light': {
        // Returns lightweight centroids of active geofences for fast mobile proximity cache (< 150KB)
        auth_user();
        try {
            $rows = q("SELECT id, name, code, COALESCE(center_lat, 0.0) as lat, COALESCE(center_lng, 0.0) as lng 
                       FROM geofences 
                       WHERE active=1 AND center_lat IS NOT NULL 
                         AND NOT (name='D/207/01' AND (center_lat < 7.2059 OR center_lng < 81.700))
                         AND name != 'Untitled Polygon' AND name NOT LIKE 'Test%'
                       ORDER BY name ASC 
                       LIMIT 10000")->fetchAll();
            
            // Auto-heal missing center coordinates directly from polygon coordinates if less than 4500 plots indexed
            if (count($rows) < 4500) {
                @db()->exec("UPDATE `geofences` 
                    SET `center_lat` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`coordinates`, '$[0][0]')) AS DECIMAL(10,6)),
                        `center_lng` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`coordinates`, '$[0][1]')) AS DECIMAL(10,6))
                    WHERE (center_lat IS NULL OR center_lat = 0) AND `coordinates` LIKE '[[%'");
                $rows = q("SELECT id, name, code, COALESCE(center_lat, 0.0) as lat, COALESCE(center_lng, 0.0) as lng 
                           FROM geofences 
                           WHERE active=1 AND center_lat IS NOT NULL 
                             AND NOT (name='D/207/01' AND (center_lat < 7.2059 OR center_lng < 81.700))
                             AND name != 'Untitled Polygon' AND name NOT LIKE 'Test%'
                           ORDER BY name ASC 
                           LIMIT 10000")->fetchAll();
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        if (empty($rows)) {
            $rows = q("SELECT id, name, code, coordinates FROM geofences WHERE active=1 AND NOT (name='D/207/01' AND center_lat < 7.2059) ORDER BY name ASC LIMIT 500")->fetchAll();
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

        $light = [];
        foreach ($rows as $r) {
            $light[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'code' => $r['code'] ?: $r['name'],
                'lat' => (float)$r['lat'],
                'lng' => (float)$r['lng'],
            ];
        }
        out(['ok' => true, 'geofences' => $light, 'total' => count($light)]);
    }

    case 'geofences_nearby': {
        // High-precision nearest geofence query with MySQL indexed spatial bbox filter, point-in-polygon ray-casting and boundary segment distance (<10ms execution)
        auth_user();
        $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : (isset($_POST['lat']) ? (float)$_POST['lat'] : null);
        $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : (isset($_POST['lng']) ? (float)$_POST['lng'] : null);
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;

        if ($lat === null || $lng === null || $lat == 0.0 || $lng == 0.0) {
            fail('Missing or invalid lat/lng coordinates');
        }

        $results = [];
        $candidates = [];

        // Phase 1: Ultra-fast indexed MySQL Bounding-Box with Proximity Ordering (~5km radius)
        $delta = 0.045; // ~5km
        $distSql = "((center_lat - $lat)*(center_lat - $lat) + (center_lng - $lng)*(center_lng - $lng))";
        try {
            $rows = q("SELECT id, name, code, coordinates, center_lat, center_lng 
                       FROM geofences 
                       WHERE active=1 
                         AND center_lat BETWEEN ? AND ? 
                         AND center_lng BETWEEN ? AND ? 
                         AND NOT (name='D/207/01' AND (center_lat < 7.2059 OR center_lng < 81.700))
                         AND name != 'Untitled Polygon' AND name NOT LIKE 'Test%'
                       ORDER BY $distSql ASC 
                       LIMIT 70", 
                      [$lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta])->fetchAll();
            
            // Expand to ~15km if no plots in immediate 5km
            if (empty($rows)) {
                $delta = 0.14;
                $rows = q("SELECT id, name, code, coordinates, center_lat, center_lng 
                           FROM geofences 
                           WHERE active=1 
                             AND center_lat BETWEEN ? AND ? 
                             AND center_lng BETWEEN ? AND ? 
                             AND NOT (name='D/207/01' AND (center_lat < 7.2059 OR center_lng < 81.700))
                             AND name != 'Untitled Polygon' AND name NOT LIKE 'Test%'
                           ORDER BY $distSql ASC 
                           LIMIT 50", 
                          [$lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta])->fetchAll();
            }

            // If still empty, check if unindexed plots exist and auto-index them
            if (empty($rows)) {
                try {
                    @db()->exec("UPDATE `geofences` 
                        SET `center_lat` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`coordinates`, '$[0][0]')) AS DECIMAL(10,6)),
                            `center_lng` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`coordinates`, '$[0][1]')) AS DECIMAL(10,6))
                        WHERE (center_lat IS NULL OR center_lat = 0) AND `coordinates` LIKE '[[%'");
                    $rows = q("SELECT id, name, code, coordinates, center_lat, center_lng 
                               FROM geofences 
                               WHERE active=1 
                                 AND center_lat BETWEEN ? AND ? 
                                 AND center_lng BETWEEN ? AND ? 
                               LIMIT 50", 
                              [$lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta])->fetchAll();
                } catch (Throwable $e) {}
            }

            foreach ($rows as $r) {
                if (empty($r['coordinates'])) continue;
                $coords = json_decode($r['coordinates'], true);
                if (!is_array($coords) || count($coords) < 3) continue;
                $candidates[] = [$r, $coords];
            }
        } catch (Throwable $e) {}

        // Fallback if center_lat column is unindexed or pending migration
        if (empty($candidates)) {
            $rows = q("SELECT id, name, code, coordinates FROM geofences WHERE active=1 LIMIT 100")->fetchAll();
            foreach ($rows as $r) {
                if (empty($r['coordinates'])) continue;
                $coords = json_decode($r['coordinates'], true);
                if (!is_array($coords) || count($coords) < 3) continue;
                $candidates[] = [$r, $coords];
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

    case 'enroll_face':
    case 'upload_profile_photo': {
        global $CONFIG;
        $u = auth_user();
        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Photo upload failed (code ' . ($f['error'] ?? 'none') . ')');
        if ($f['size'] > ($CONFIG['max_photo_mb'] ?? 8) * 1048576) fail('Photo too large');
        $validTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (defined('IMAGETYPE_WEBP')) $validTypes[] = IMAGETYPE_WEBP;
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], $validTypes, true)) fail('Invalid image');

        $ext = 'jpg';
        if ($info[2] === IMAGETYPE_PNG) $ext = 'png';
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP) $ext = 'webp';

        $sub = 'profiles';
        $uploadDir = $CONFIG['upload_dir'] ?? (__DIR__ . '/uploads');
        $dir = $uploadDir . '/' . $sub;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $name = 'master_' . (int)$u['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Failed to store master profile photo on server');

        $relPath = "$sub/$name";
        q("UPDATE users SET profile_photo = ?, face_enrolled = 1, face_enrolled_at = NOW() WHERE id = ?", [$relPath, $u['id']]);

        try {
            q("INSERT INTO duty_logs (user_id, event_type, status, note, at) VALUES (?, 'face_enrolled', 'on', 'Official Master Face enrolled via Mobile App Liveness Check', NOW())", [$u['id']]);
        } catch (Throwable $e) {}

        out([
            'ok' => true,
            'profile_photo' => $relPath,
            'face_enrolled' => true,
            'message' => 'Official Master Face ID registered successfully!'
        ]);
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

        $isSelfie = !empty($_POST['is_selfie']) ? 1 : ($activityType === 'Officer Verification Selfie' ? 1 : 0);
        $faceStatus = $isSelfie ? 'pending' : 'skipped';
        $faceConfidence = null;
        $faceReason = null;

        if ($isSelfie) {
            $masterPhoto = q('SELECT profile_photo FROM users WHERE id=?', [$u['id']])->fetchColumn();
            if ($masterPhoto) {
                $aiRes = compare_faces_with_ai($masterPhoto, "$sub/$name");
                if (!empty($aiRes['ok'])) {
                    $faceStatus = $aiRes['status'];
                    $faceConfidence = $aiRes['confidence'];
                    $faceReason = $aiRes['reason'];
                }
            }
        }

        insert_field_photo($u['id'], "$sub/$name", $lat, $lng, $note, $geofence, $dt, $activityType, $autoGeofence, $selectedGeofence, $isSelfie, $faceStatus, $faceConfidence, $faceReason);

        out([
            'ok' => true,
            'file_path' => "$sub/$name",
            'geofence' => $geofence,
            'activity_type' => $activityType,
            'is_selfie' => $isSelfie,
            'face_match_status' => $faceStatus,
            'face_confidence' => $faceConfidence,
            'face_match_reason' => $faceReason
        ]);
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

    case 'reset_face': {
        $u = auth_user();
        $targetId = (int)($_POST['user_id'] ?? $u['id']);
        if ($targetId !== (int)$u['id'] && ($u['role'] ?? '') !== 'admin') {
            fail('Forbidden', 403);
        }
        $uRow = q('SELECT profile_photo FROM users WHERE id=?', [$targetId])->fetch();
        if ($uRow && !empty($uRow['profile_photo'])) {
            global $CONFIG;
            $upPath = __DIR__ . '/' . ($CONFIG['upload_url'] ?? 'uploads') . '/' . ltrim($uRow['profile_photo'], '/');
            if (file_exists($upPath)) @unlink($upPath);
        }
        q('UPDATE users SET profile_photo=NULL, face_enrolled=0, face_enrolled_at=NULL WHERE id=?', [$targetId]);
        out(['ok' => true, 'message' => 'Face enrollment reset to 0']);
    }

    // ==========================================
    // CANE DELIVERY NOTE (CDN) & ERP INTEGRATION
    // ==========================================
    case 'cdn_categories': {
        $cats = q("SELECT category_code, category_name FROM erp_cane_categories ORDER BY category_code ASC")->fetchAll();
        if (empty($cats)) {
            $cats = [
                ['category_code' => 'CAT1', 'category_name' => 'Green'],
                ['category_code' => 'CAT2', 'category_name' => 'Burnt'],
                ['category_code' => 'CAT3', 'category_name' => 'Seed Cane'],
                ['category_code' => 'CAT4', 'category_name' => 'Seed Cane Top Cutting'],
                ['category_code' => 'CAT5', 'category_name' => 'Compost'],
                ['category_code' => 'CAT6', 'category_name' => 'Machine Harvested'],
            ];
        }
        out(['ok' => true, 'categories' => $cats]);
    }

    case 'cdn_issue': {
        $u = auth_user();
        $cdn = preg_replace('/\s+/', '', trim($_POST['cdn'] ?? ''));
        if (!$cdn) fail('CDN Number is required', 400);

        $plotNo = trim($_POST['plot_no'] ?? '');
        $plotManualCode = trim($_POST['plot_manual_code'] ?? '');
        $farmerNo = trim($_POST['farmer_no'] ?? '');
        $farmerName = trim($_POST['farmer_name'] ?? '');
        $fieldAsstNo = trim($_POST['field_asst_no'] ?? ($u['code'] ?? ''));
        $fieldAsstName = trim($_POST['field_asst_name'] ?? ($u['name'] ?? ''));
        $type = trim($_POST['type'] ?? 'OUT');
        $caneCat = trim($_POST['cane_category_code'] ?? 'CAT1');
        $vehicleNo = trim($_POST['vehicle_no'] ?? '');
        $trailerNo = trim($_POST['trailer_no'] ?? '');
        $cropCycle = trim($_POST['crop_cycle'] ?? '');
        $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : null;
        $createUser = $u['name'] ?? ($u['phone'] ?? 'FieldOfficer');

        // Verification Photos: CDN Slip, Tractor Front, Trailer No & Cane Load (Total 4 Photos)
        $photoPath = null;
        $dir = __DIR__ . '/uploads/cdn';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        if (!empty($_FILES['cdn_photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['cdn_photo']['name'] ?? 'photo.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';
            $fname = 'cdn_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cdn) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['cdn_photo']['tmp_name'], $dir . '/' . $fname)) {
                $photoPath = 'uploads/cdn/' . $fname;
            }
        }

        $tractorPhotoPath = null;
        if (!empty($_FILES['tractor_photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['tractor_photo']['name'] ?? 'photo.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';
            $fname = 'tractor_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cdn) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['tractor_photo']['tmp_name'], $dir . '/' . $fname)) {
                $tractorPhotoPath = 'uploads/cdn/' . $fname;
            }
        }

        $trailerPhotoPath = null;
        if (!empty($_FILES['trailer_photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['trailer_photo']['name'] ?? 'photo.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';
            $fname = 'trailer_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cdn) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['trailer_photo']['tmp_name'], $dir . '/' . $fname)) {
                $trailerPhotoPath = 'uploads/cdn/' . $fname;
            }
        }

        $loadPhotoPath = null;
        if (!empty($_FILES['load_photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['load_photo']['name'] ?? 'photo.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';
            $fname = 'load_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cdn) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['load_photo']['tmp_name'], $dir . '/' . $fname)) {
                $loadPhotoPath = 'uploads/cdn/' . $fname;
            }
        }

        // Upsert into cdn_details with all 4 photos
        $stmt = db()->prepare("INSERT INTO cdn_details 
            (cdn, cdn_issued, plot_no, plot_manual_code, farmer_no, farmer_name, field_asst_no, field_asst_name, type, cane_category_code, vehicle_no, trailer_no, crop_cycle, cdn_photo, tractor_photo, trailer_photo, load_photo, lat, lng, create_user, status, synced_to_mssql)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 0)
            ON DUPLICATE KEY UPDATE 
            plot_no=VALUES(plot_no), plot_manual_code=VALUES(plot_manual_code), farmer_no=VALUES(farmer_no), farmer_name=VALUES(farmer_name),
            field_asst_no=VALUES(field_asst_no), field_asst_name=VALUES(field_asst_name), type=VALUES(type), cane_category_code=VALUES(cane_category_code),
            vehicle_no=VALUES(vehicle_no), trailer_no=VALUES(trailer_no), crop_cycle=VALUES(crop_cycle),
            cdn_photo=COALESCE(VALUES(cdn_photo), cdn_photo),
            tractor_photo=COALESCE(VALUES(tractor_photo), tractor_photo),
            trailer_photo=COALESCE(VALUES(trailer_photo), trailer_photo),
            load_photo=COALESCE(VALUES(load_photo), load_photo),
            lat=COALESCE(VALUES(lat), lat), lng=COALESCE(VALUES(lng), lng)");
        $stmt->execute([
            $cdn, $plotNo, $plotManualCode, $farmerNo, $farmerName, $fieldAsstNo, $fieldAsstName, $type, $caneCat,
            $vehicleNo, $trailerNo, $cropCycle, $photoPath, $tractorPhotoPath, $trailerPhotoPath, $loadPhotoPath, $lat, $lng, $createUser
        ]);

        out(['ok' => true, 'cdn' => $cdn, 'status' => 'PENDING', 'message' => 'CDN registered successfully']);
    }

    case 'cdn_list': {
        $u = auth_user();
        $date = trim($_GET['date'] ?? ($_POST['date'] ?? ''));
        $status = strtoupper(trim($_GET['status'] ?? ($_POST['status'] ?? 'COMPLETED'))); // Default COMPLETED as requested!
        $search = trim($_GET['search'] ?? ($_POST['search'] ?? ''));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

        $where = ["1=1"];
        $params = [];

        if ($date) {
            $where[] = "DATE(create_time) = ?";
            $params[] = $date;
        }

        if ($status && $status !== 'ALL') {
            $where[] = "status = ?";
            $params[] = $status;
        }

        if ($search) {
            $where[] = "(cdn LIKE ? OR vehicle_no LIKE ? OR plot_manual_code LIKE ? OR farmer_name LIKE ? OR weige_ticket_no LIKE ?)";
            $qLike = "%$search%";
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
        }

        $whereSql = implode(" AND ", $where);

        // Calculate summary metrics
        $sumStmt = db()->prepare("SELECT 
            COUNT(*) as total_count,
            COALESCE(SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END), 0) as completed_count,
            COALESCE(SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END), 0) as pending_count,
            COALESCE(SUM(CASE WHEN status='COMPLETED' THEN tonnage_received ELSE 0.00 END), 0.00) as total_tonnage
            FROM cdn_details WHERE $whereSql");
        $sumStmt->execute($params);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch records
        $dataStmt = db()->prepare("SELECT 
            id, cdn, cdn_issued, plot_no, plot_manual_code, farmer_no, farmer_name, field_asst_no, field_asst_name,
            type, cane_category_code, vehicle_no, trailer_no, crop_cycle, cdn_photo, tractor_photo, trailer_photo, load_photo, lat, lng,
            create_user, create_time, weige_ticket_no, tonnage_received, gross_weight, tare_weight, weighed_date, status, synced_to_mssql
            FROM cdn_details WHERE $whereSql ORDER BY id DESC LIMIT $limit");
        $dataStmt->execute($params);
        $items = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        out([
            'ok' => true,
            'summary' => [
                'total_count' => (int)($summary['total_count'] ?? 0),
                'completed_count' => (int)($summary['completed_count'] ?? 0),
                'pending_count' => (int)($summary['pending_count'] ?? 0),
                'total_tonnage' => round((float)($summary['total_tonnage'] ?? 0), 2),
            ],
            'items' => $items,
        ]);
    }

    case 'search_plots_master': {
        $u = get_auth_user();
        if (!$u && ($_REQUEST['key'] ?? '') !== 'galoya2026') {
            fail('Unauthorized', 401);
        }
        $q = trim($_GET['query'] ?? ($_POST['query'] ?? ''));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 30)));
        if (strlen($q) < 1) {
            out(['ok' => true, 'plots' => []]);
        }

        $like = "%$q%";
        try {
            $sql = "SELECT 
                p.plot_no, p.plot_manual_code, p.sector_no, p.farmer_no, p.land_area, p.payment_category, p.field_asst_no,
                p.nominee_id, p.payment_permission, p.debit_permission, p.block_id,
                f.name AS farmer_name, f.contact_no AS farmer_phone, f.nic_no AS farmer_nic,
                n.nomininame AS nominee_name, n.nic AS nominee_nic, n.contact_no AS nominee_phone,
                n.bank_code, n.branch_code, n.bank_accno,
                a.name AS field_asst_name, a.feild_asst_code,
                g.id AS geofence_id, g.center_lat AS gf_lat, g.center_lng AS gf_lng,
                CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END AS has_geofence
                FROM erp_plots p
                LEFT JOIN erp_farmers f ON p.farmer_no = f.farmer_no
                LEFT JOIN erp_nominees n ON CAST(p.nominee_id AS CHAR) = CAST(n.id AS CHAR)
                LEFT JOIN erp_field_assistants a ON p.field_asst_no = a.field_asst_no
                LEFT JOIN geofences g ON g.name = p.plot_manual_code AND g.active = 1
                WHERE (p.plot_manual_code LIKE ? OR p.plot_no LIKE ? OR f.name LIKE ? OR f.nic_no LIKE ? OR p.sector_no LIKE ?)
                ORDER BY has_geofence DESC, p.plot_manual_code ASC
                LIMIT $limit";
            $st = db()->prepare($sql);
            $st->execute([$like, $like, $like, $like, $like]);
            $plots = $st->fetchAll(PDO::FETCH_ASSOC);
            out(['ok' => true, 'plots' => $plots]);
        } catch (Throwable $e) {
            // Fallback without geofences table join
            try {
                $sqlFallback = "SELECT 
                    p.plot_no, p.plot_manual_code, p.sector_no, p.farmer_no, p.land_area, p.payment_category, p.field_asst_no,
                    f.name AS farmer_name, f.contact_no AS farmer_phone, f.nic_no AS farmer_nic,
                    a.name AS field_asst_name, a.feild_asst_code,
                    0 AS geofence_id, 0 AS has_geofence
                    FROM erp_plots p
                    LEFT JOIN erp_farmers f ON p.farmer_no = f.farmer_no
                    LEFT JOIN erp_field_assistants a ON p.field_asst_no = a.field_asst_no
                    WHERE p.plot_manual_code LIKE ? OR p.plot_no LIKE ? OR f.name LIKE ? OR f.nic_no LIKE ? OR p.sector_no LIKE ?
                    ORDER BY p.plot_manual_code ASC
                    LIMIT $limit";
                $st = db()->prepare($sqlFallback);
                $st->execute([$like, $like, $like, $like, $like]);
                $plots = $st->fetchAll(PDO::FETCH_ASSOC);
                out(['ok' => true, 'plots' => $plots, 'fallback' => true]);
            } catch (Throwable $e2) {
                out(['ok' => false, 'error' => $e2->getMessage()]);
            }
        }
    }

    case 'erp_master_sync': {
        auth_user();
        $since = trim($_GET['since'] ?? '');
        $type = trim($_GET['type'] ?? 'all'); // 'plots', 'farmers', 'fa', 'all'

        $res = ['ok' => true];
        if ($type === 'all' || $type === 'plots') {
            $plotSql = "SELECT p.plot_no, p.plot_manual_code, p.sector_no, p.farmer_no, p.land_area, p.field_asst_no,
                               f.name as farmer_name, f.contact_no as farmer_phone, f.nic_no as farmer_nic,
                               a.name as field_asst_name, a.feild_asst_code
                        FROM erp_plots p
                        LEFT JOIN erp_farmers f ON p.farmer_no = f.farmer_no
                        LEFT JOIN erp_field_assistants a ON p.field_asst_no = a.field_asst_no";
            if ($since) {
                $plotSql .= " WHERE p.updated_at > ?";
                $st = db()->prepare($plotSql);
                $st->execute([$since]);
            } else {
                $st = db()->query($plotSql);
            }
            $res['plots'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        out($res);
    }

    case 'get_plot_crops': {
        $u = get_auth_user();
        if (!$u && ($_REQUEST['key'] ?? '') !== 'galoya2026') fail('Unauthorized', 401);
        $plotNo = trim($_GET['plot_no'] ?? ($_POST['plot_no'] ?? ''));
        if (!$plotNo) fail('Plot number required', 400);

        // 1. Cross-match crops by internal plot_no or manual code (e.g. p402 or V/01/20)
        $st = db()->prepare("SELECT c.crop_cycle, c.crop_extent, c.variety, c.status, c.age_month, c.is_agronomy,
                                    p.plot_manual_code, p.plot_no, p.sector_no, p.land_area,
                                    f.name AS farmer_name, f.farmer_no, f.contact_no AS farmer_phone, f.nic_no AS farmer_nic,
                                    n.nomininame AS nominee_name, n.contact_no AS nominee_phone, n.nic AS nominee_nic,
                                    n.bank_code, n.branch_code, n.bank_accno,
                                    p.payment_permission, p.debit_permission
                             FROM erp_crops c
                             JOIN erp_plots p ON c.plot_no = p.plot_no
                             LEFT JOIN erp_farmers f ON p.farmer_no = f.farmer_no
                             LEFT JOIN erp_nominees n ON CAST(p.nominee_id AS CHAR) = CAST(n.id AS CHAR)
                             WHERE (c.plot_no = ? OR p.plot_manual_code = ?) AND c.status = 1
                             ORDER BY c.crop_cycle ASC");
        $st->execute([$plotNo, $plotNo]);
        $crops = $st->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch full plot + farmer profile (even if crops are empty)
        $plotInfo = null;
        if (!empty($crops)) {
            $first = $crops[0];
            $plotInfo = [
                'plot_no' => $first['plot_no'],
                'plot_manual_code' => $first['plot_manual_code'],
                'sector_no' => $first['sector_no'],
                'land_area' => $first['land_area'],
                'farmer_name' => $first['farmer_name'],
                'farmer_no' => $first['farmer_no'],
                'farmer_phone' => $first['farmer_phone'],
                'farmer_nic' => $first['farmer_nic'],
                'nominee_name' => $first['nominee_name'],
                'nominee_phone' => $first['nominee_phone'],
                'nominee_nic' => $first['nominee_nic'],
                'bank_code' => $first['bank_code'],
                'branch_code' => $first['branch_code'],
                'bank_accno' => $first['bank_accno'],
                'payment_permission' => $first['payment_permission'],
                'debit_permission' => $first['debit_permission'],
            ];
        } else {
            $stPlot = db()->prepare("SELECT p.plot_manual_code, p.plot_no, p.sector_no, p.land_area,
                                            f.name AS farmer_name, f.farmer_no, f.contact_no AS farmer_phone, f.nic_no AS farmer_nic,
                                            n.nomininame AS nominee_name, n.contact_no AS nominee_phone, n.nic AS nominee_nic,
                                            n.bank_code, n.branch_code, n.bank_accno,
                                            p.payment_permission, p.debit_permission
                                     FROM erp_plots p
                                     LEFT JOIN erp_farmers f ON p.farmer_no = f.farmer_no
                                     LEFT JOIN erp_nominees n ON CAST(p.nominee_id AS CHAR) = CAST(n.id AS CHAR)
                                     WHERE p.plot_no = ? OR p.plot_manual_code = ?
                                     LIMIT 1");
            $stPlot->execute([$plotNo, $plotNo]);
            $plotInfo = $stPlot->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        out([
            'ok' => true,
            'crops' => $crops,
            'plot' => $plotInfo,
        ]);
    }

    case 'get_active_vehicles': {
        $u = get_auth_user();
        if (!$u && ($_REQUEST['key'] ?? '') !== 'galoya2026') fail('Unauthorized', 401);
        $st = db()->query("SELECT vehicle_no, vehicle_name, contractor_no, transporter_no FROM erp_vehicles WHERE status = 'Active' ORDER BY vehicle_no ASC");
        out(['ok' => true, 'vehicles' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'get_trailers': {
        $u = get_auth_user();
        if (!$u && ($_REQUEST['key'] ?? '') !== 'galoya2026') fail('Unauthorized', 401);
        $st = db()->query("SELECT trailer_no, trailer_name, weight, vehicle_number FROM erp_trailers ORDER BY trailer_no ASC");
        out(['ok' => true, 'trailers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'erp_sync_bridge': {
        // Authenticate bridge via secret key
        $key = $_GET['key'] ?? ($_POST['key'] ?? '');
        if ($key !== 'galoya2026') {
            fail('Unauthorized bridge access', 403);
        }

        $sub = $_GET['sub'] ?? ($_POST['sub'] ?? '');

        if ($sub === 'pull_pending_cdns') {
            // CDNs issued on mobile app waiting to be pushed to MSSQL [dbo].[CDN_Details]
            $limit = min(200, (int)($_GET['limit'] ?? 100));
            $rows = q("SELECT cdn, cdn_issued, plot_no, plot_manual_code, type, cane_category_code, vehicle_no, trailer_no, crop_cycle, create_user, create_time, field_asst_no
                       FROM cdn_details WHERE synced_to_mssql = 0 ORDER BY id ASC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
            out(['ok' => true, 'count' => count($rows), 'cdns' => $rows]);
        }

        if ($sub === 'mark_cdn_synced') {
            $input = json_decode(file_get_contents('php://input'), true);
            $cdns = $input['cdns'] ?? ($_POST['cdns'] ?? []);
            if (is_string($cdns)) $cdns = explode(',', $cdns);
            if (!empty($cdns)) {
                $in = implode(',', array_fill(0, count($cdns), '?'));
                $st = db()->prepare("UPDATE cdn_details SET synced_to_mssql = 1 WHERE cdn IN ($in)");
                $st->execute($cdns);
            }
            out(['ok' => true, 'updated' => count($cdns)]);
        }

        if ($sub === 'push_wbt_batch') {
            // Weighbridge ticket updates from MSSQL [dbo].[CDN_Details]
            $input = json_decode(file_get_contents('php://input'), true);
            $items = $input['items'] ?? [];
            if (!is_array($items) || empty($items)) fail('No items provided', 400);

            $stmt = db()->prepare("UPDATE cdn_details SET 
                weige_ticket_no = ?, 
                tonnage_received = ?, 
                gross_weight = ?, 
                tare_weight = ?, 
                weighed_date = ?, 
                status = 'COMPLETED',
                synced_to_mssql = 1
                WHERE cdn = ?");

            $updated = 0;
            foreach ($items as $item) {
                $cdn = trim($item['cdn'] ?? '');
                $wbt = trim($item['WeigeTicket_No'] ?? ($item['weige_ticket_no'] ?? ''));
                $tonnage = (float)($item['TonnageRecived'] ?? ($item['tonnage_received'] ?? 0));
                $gross = (float)($item['gross_weight'] ?? 0);
                $tare = (float)($item['tare_weight'] ?? 0);
                $weighedDate = !empty($item['weighed_date']) ? $item['weighed_date'] : date('Y-m-d H:i:s');
                if ($cdn && $wbt) {
                    $stmt->execute([$wbt, $tonnage, $gross, $tare, $weighedDate, $cdn]);
                    $updated += $stmt->rowCount();
                }
            }
            out(['ok' => true, 'updated' => $updated]);
        }

        if ($sub === 'push_master_batch') {
            $input = json_decode(file_get_contents('php://input'), true);
            $entity = $input['entity'] ?? ''; // 'plots', 'farmers', 'fa'
            $data = $input['data'] ?? [];

            if ($entity === 'plots') {
                $stmt = db()->prepare("INSERT INTO erp_plots 
                    (plot_no, plot_manual_code, sector_no, farmer_no, nominee_id, payment_permission, debit_permission, block_id, land_area, payment_category, field_asst_no, registration_no, method, ownership, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    plot_manual_code=VALUES(plot_manual_code), sector_no=VALUES(sector_no), farmer_no=VALUES(farmer_no),
                    nominee_id=VALUES(nominee_id), payment_permission=VALUES(payment_permission), debit_permission=VALUES(debit_permission),
                    block_id=VALUES(block_id), land_area=VALUES(land_area), payment_category=VALUES(payment_category), field_asst_no=VALUES(field_asst_no),
                    registration_no=VALUES(registration_no), method=VALUES(method), ownership=VALUES(ownership), is_active=VALUES(is_active)");
                $cnt = 0;
                foreach ($data as $r) {
                    $stmt->execute([
                        $r['Plot_No'], $r['PlotManual_Code'], $r['Sector_No'], $r['Farmer_No'],
                        $r['Nominee'] ?? null,
                        isset($r['PaymentPermission']) ? ((int)$r['PaymentPermission']) : 0,
                        isset($r['DebitPermission']) ? ((int)$r['DebitPermission']) : 0,
                        !empty($r['Block_ID']) ? ((int)$r['Block_ID']) : null,
                        (float)($r['Land_Area'] ?? 0), $r['Payment_Catagory'] ?? null, $r['FieldAsst_No'] ?? null,
                        $r['Registration_No'] ?? null, $r['method'] ?? null, $r['Ownership'] ?? null,
                        isset($r['Is_Active']) ? ((int)$r['Is_Active']) : 1
                    ]);
                    $cnt++;
                }
                out(['ok' => true, 'entity' => 'plots', 'count' => $cnt]);
            }

            if ($entity === 'nominees') {
                $stmt = db()->prepare("INSERT INTO erp_nominees 
                    (id, nomininame, nic, contact_no, address, bank_code, branch_code, bank_accno)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    nomininame=VALUES(nomininame), nic=VALUES(nic), contact_no=VALUES(contact_no),
                    address=VALUES(address), bank_code=VALUES(bank_code), branch_code=VALUES(branch_code), bank_accno=VALUES(bank_accno)");
                $cnt = 0;
                foreach ($data as $r) {
                    $id = trim((string)($r['ID'] ?? ($r['id'] ?? '')));
                    if ($id !== '') {
                        $stmt->execute([
                            $id,
                            $r['nomininame'] ?? ($r['name'] ?? null),
                            $r['NIC'] ?? ($r['nic'] ?? null),
                            $r['Contact_No'] ?? ($r['contact_no'] ?? null),
                            $r['Address'] ?? ($r['address'] ?? null),
                            $r['Bank_Code'] ?? ($r['bank_code'] ?? null),
                            $r['Branch_Code'] ?? ($r['branch_code'] ?? null),
                            $r['Bank_AccNo'] ?? ($r['bank_accno'] ?? null),
                        ]);
                        $cnt++;
                    }
                }
                out(['ok' => true, 'entity' => 'nominees', 'count' => $cnt]);
            }

            if ($entity === 'crops') {
                $stmt = db()->prepare("INSERT INTO erp_crops 
                    (plot_no, crop_cycle, crop_extent, variety, status, age_month, is_agronomy)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    crop_extent=VALUES(crop_extent), variety=VALUES(variety), status=VALUES(status), age_month=VALUES(age_month)");
                $cnt = 0;
                foreach ($data as $r) {
                    $plotNo = trim((string)($r['Plot_No'] ?? ($r['plot_no'] ?? '')));
                    $cycle = trim((string)($r['Crop_Cycle'] ?? ($r['crop_cycle'] ?? '')));
                    if ($plotNo !== '' && $cycle !== '') {
                        $stmt->execute([
                            $plotNo,
                            $cycle,
                            !empty($r['Crop_Extent']) ? (float)$r['Crop_Extent'] : null,
                            $r['Variety'] ?? ($r['variety'] ?? null),
                            isset($r['Status']) ? (int)$r['Status'] : 1,
                            $r['Age_Month'] ?? ($r['age_month'] ?? null),
                            !empty($r['is_agronomy']) ? 1 : 0,
                        ]);
                        $cnt++;
                    }
                }
                out(['ok' => true, 'entity' => 'crops', 'count' => $cnt]);
            }

            if ($entity === 'vehicles') {
                $stmt = db()->prepare("INSERT INTO erp_vehicles 
                    (vehicle_no, vehicle_name, contractor_no, transporter_no, status)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    vehicle_name=VALUES(vehicle_name), contractor_no=VALUES(contractor_no),
                    transporter_no=VALUES(transporter_no), status=VALUES(status)");
                $cnt = 0;
                foreach ($data as $r) {
                    $vNo = trim((string)($r['Vehicle_No'] ?? ($r['vehicle_no'] ?? '')));
                    if ($vNo !== '') {
                        $stmt->execute([
                            $vNo,
                            $r['Vehicle'] ?? ($r['vehicle_name'] ?? null),
                            $r['Contractor_No'] ?? ($r['contractor_no'] ?? null),
                            $r['Transporter_No'] ?? ($r['transporter_no'] ?? null),
                            $r['status'] ?? 'Active'
                        ]);
                        $cnt++;
                    }
                }
                out(['ok' => true, 'entity' => 'vehicles', 'count' => $cnt]);
            }

            if ($entity === 'trailers') {
                $stmt = db()->prepare("INSERT INTO erp_trailers 
                    (trailer_no, trailer_name, weight, vehicle_number)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    trailer_name=VALUES(trailer_name), weight=VALUES(weight), vehicle_number=VALUES(vehicle_number)");
                $cnt = 0;
                foreach ($data as $r) {
                    $tNo = trim((string)($r['Trailerno'] ?? ($r['trailer_no'] ?? '')));
                    if ($tNo !== '') {
                        $stmt->execute([
                            $tNo,
                            $r['Trailer'] ?? ($r['trailer_name'] ?? null),
                            !empty($r['Weight']) ? (float)$r['Weight'] : 0,
                            $r['VehicleNumber'] ?? ($r['vehicle_number'] ?? null)
                        ]);
                        $cnt++;
                    }
                }
                out(['ok' => true, 'entity' => 'trailers', 'count' => $cnt]);
            }

            if ($entity === 'farmers') {
                $stmt = db()->prepare("INSERT INTO erp_farmers (farmer_no, name, contact_no, nic_no, address)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name=VALUES(name), contact_no=VALUES(contact_no), nic_no=VALUES(nic_no), address=VALUES(address)");
                $cnt = 0;
                foreach ($data as $r) {
                    $stmt->execute([$r['Farmer_No'], $r['Name'], $r['Contact_No'] ?? null, $r['Nic_No'] ?? null, $r['Address'] ?? null]);
                    $cnt++;
                }
                out(['ok' => true, 'entity' => 'farmers', 'count' => $cnt]);
            }

            if ($entity === 'fa') {
                $stmt = db()->prepare("INSERT INTO erp_field_assistants (field_asst_no, feild_asst_code, name, contact_no, nic_no, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE feild_asst_code=VALUES(feild_asst_code), name=VALUES(name), contact_no=VALUES(contact_no), nic_no=VALUES(nic_no), is_active=VALUES(is_active)");
                $cnt = 0;
                foreach ($data as $r) {
                    $stmt->execute([$r['FieldAssistant_No'], $r['FeildAsst_Code'] ?? null, $r['Name'], $r['Contact_No'] ?? null, $r['Nic_No'] ?? null, $r['Is_Active'] ?? '1']);
                    $cnt++;
                }
                out(['ok' => true, 'entity' => 'fa', 'count' => $cnt]);
            }

            fail('Unknown entity or data', 400);
        }

        fail('Unknown sub-action', 400);
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
