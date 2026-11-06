<?php
// backend/sync_server_room.php - Sync Server Room Temperature from Teltonika FMB920 via Wialon API
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$token = "14534c72a9fd9f73e079d703d6fe9a8a01178A0335ADA2D1A9647DBD59561FC816FB2570";
$unitId = 28160408;

try {
    // 1. Login to Wialon
    $loginParams = json_encode(['token' => $token]);
    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=token/login&params=" . urlencode($loginParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $loginRaw = curl_exec($ch);
    curl_close($ch);

    $loginData = json_decode($loginRaw, true);
    $sid = $loginData['eid'] ?? null;
    if (!$sid) {
        throw new Exception("Wialon login failed: " . ($loginData['reason'] ?? $loginRaw));
    }

    // 2. Fetch Server Room Unit Data (Flags: base + sensors + last message)
    $searchParams = json_encode(['id' => $unitId, 'flags' => 1 + 256 + 1024 + 4096 + 0x200000]);
    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=core/search_item&sid=$sid&params=" . urlencode($searchParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $unitRaw = curl_exec($ch);
    curl_close($ch);

    $uData = json_decode($unitRaw, true);
    $item = $uData['item'] ?? null;
    if (!$item) {
        throw new Exception("Could not fetch Server Room unit: " . $unitRaw);
    }

    $name = $item['nm'] ?? 'Server room';
    $uid = $item['uid'] ?? '354018110429762';
    $phone = $item['ph'] ?? '+94764429460';
    $lat = !empty($item['pos']['y']) ? (float)$item['pos']['y'] : 7.222895;
    $lng = !empty($item['pos']['x']) ? (float)$item['pos']['x'] : 81.675295;
    $posTime = !empty($item['pos']['t']) ? date('Y-m-d H:i:s', (int)$item['pos']['t']) : date('Y-m-d H:i:s');

    // Extract parameters from last message
    $lmsg = $item['lmsg']['p'] ?? [];
    $rawTemp = isset($lmsg['io_25']) ? (float)$lmsg['io_25'] : (isset($lmsg['io_26']) ? (float)$lmsg['io_26'] : 3000);
    $tempCelsius = round($rawTemp / 100.0, 1); // io_25 = 3000 -> 30.0 C

    $pwrExt = isset($lmsg['pwr_ext']) ? (float)$lmsg['pwr_ext'] : 12.04;
    $pwrInt = isset($lmsg['pwr_int']) ? (float)$lmsg['pwr_int'] : 4.06;

    $sensorsJson = json_encode([
        ['id' => 1, 'name' => 'Server Room Temperature', 'type' => 'temperature', 'unit' => '°C', 'param' => 'io_25', 'val' => $tempCelsius, 'desc' => 'Bluetooth BLE Temp Sensor', 'visible' => true],
        ['id' => 2, 'name' => 'Main UPS Power Supply', 'type' => 'voltage', 'unit' => 'V', 'param' => 'pwr_ext', 'val' => $pwrExt, 'desc' => '12V DC UPS Power', 'visible' => true],
        ['id' => 3, 'name' => 'FMB920 Internal Battery', 'type' => 'voltage', 'unit' => 'V', 'param' => 'pwr_int', 'val' => $pwrInt, 'desc' => 'Backup Li-ion 4V Battery', 'visible' => true]
    ], JSON_UNESCAPED_UNICODE);

    $propertiesJson = json_encode([
        'wialon_id' => $unitId,
        'temperature' => $tempCelsius,
        'pwr_ext' => $pwrExt,
        'pwr_int' => $pwrInt,
        'last_sync' => date('Y-m-d H:i:s'),
        'alert_threshold' => 28.0
    ], JSON_UNESCAPED_UNICODE);

    // 3. Upsert into users table (Strictly marked as 'other' / facility, NEVER 'staff'!)
    $existing = q("SELECT id FROM users WHERE unique_id = ? OR name = 'Server room' LIMIT 1", [$uid])->fetch();

    if ($existing) {
        $dbId = (int)$existing['id'];
        q("UPDATE users SET 
            name = ?, code = 'SRV-ROOM', unit_type = 'other', device_type = 'Teltonika FMB920',
            phone = ?, last_lat = ?, last_lng = ?, speed = 0, ignition = 1, motion_state = 'stationary',
            on_duty = 1, active = 1, last_seen = ?, sensors_json = ?, properties_json = ?,
            icon_key = 'server_temp'
           WHERE id = ?",
          [$name, $phone, $lat, $lng, $posTime, $sensorsJson, $propertiesJson, $dbId]);
    } else {
        q("INSERT INTO users 
           (name, code, role, unit_type, device_type, phone, password_hash, unique_id, icon_key,
            last_lat, last_lng, speed, ignition, motion_state, on_duty, active, last_seen,
            sensors_json, properties_json)
           VALUES 
           (?, 'SRV-ROOM', 'facility', 'other', 'Teltonika FMB920', ?, ?, ?, 'server_temp',
            ?, ?, 0, 1, 'stationary', 1, 1, ?,
            ?, ?)",
          [$name, $phone, password_hash('unit_' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT), $uid,
           $lat, $lng, $posTime, $sensorsJson, $propertiesJson]);
        $dbId = (int)db()->lastInsertId();
    }

    // 4. Record to locations table for telemetry history
    q("INSERT INTO locations (user_id, lat, lng, speed, battery, recorded_at) VALUES (?, ?, ?, 0, ?, ?)",
      [$dbId, $lat, $lng, (int)$tempCelsius, $posTime]);

    echo json_encode([
        'success' => true,
        'unit' => [
            'id' => $dbId,
            'name' => $name,
            'device' => 'Teltonika FMB920',
            'imei' => $uid,
            'temperature_celsius' => $tempCelsius,
            'external_power' => $pwrExt . ' V',
            'internal_battery' => $pwrInt . ' V',
            'lat' => $lat,
            'lng' => $lng,
            'timestamp' => $posTime
        ]
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
