<?php
// backend/sync_server_room.php - High Speed Wialon Batch Telematics Sync
require_once __DIR__ . '/bootstrap.php';

function sync_wialon_fleet_fast() {
    $token = "14534c72a9fd9f73e079d703d6fe9a8a01178A0335ADA2D1A9647DBD59561FC816FB2570";

    $calibrations = [
        14086277 => [ // LK-3313 (Crew Cab)
            ['x' => 1, 'a' => 0.092592592593, 'b' => -0.092592592593],
            ['x' => 55, 'a' => 0.066666666667, 'b' => 1.33333333333],
            ['x' => 130, 'a' => 0.074626865672, 'b' => 0.298507462687],
            ['x' => 197, 'a' => 0.073529411765, 'b' => 0.514705882353],
            ['x' => 265, 'a' => 0.074626865672, 'b' => 0.223880597015],
            ['x' => 332, 'a' => 0.074626865672, 'b' => 0.223880597015],
            ['x' => 399, 'a' => 0.074626865672, 'b' => 0.223880597015],
            ['x' => 466, 'a' => 0.076923076923, 'b' => -0.846153846154],
            ['x' => 596, 'a' => 0.147058823529, 'b' => -42.6470588235],
            ['x' => 664, 'a' => 0.073529411765, 'b' => 6.17647058824],
            ['x' => 732, 'a' => 0.072463768116, 'b' => 6.95652173913],
            ['x' => 801, 'a' => 0.068493150685, 'b' => 10.1369863014],
            ['x' => 874, 'a' => 0.064935064935, 'b' => 13.2467532468],
            ['x' => 951, 'a' => 0.069444444444, 'b' => 8.95833333333]
        ],
        13275566 => [ // PY-3029 (Lighting Cab)
            ['x' => 1, 'a' => 0.416666666667, 'b' => -0.416666666667],
            ['x' => 13, 'a' => 0.056818181818, 'b' => 4.26136363636],
            ['x' => 101, 'a' => 0.061728395062, 'b' => 3.76543209876],
            ['x' => 182, 'a' => 0.059523809524, 'b' => 4.16666666667],
            ['x' => 266, 'a' => 0.061728395062, 'b' => 3.58024691358],
            ['x' => 347, 'a' => 0.061728395062, 'b' => 3.58024691358],
            ['x' => 428, 'a' => 0.065789473684, 'b' => 1.84210526316],
            ['x' => 504, 'a' => 0.0625, 'b' => 3.5],
            ['x' => 584, 'a' => 0.061728395062, 'b' => 3.95061728395],
            ['x' => 665, 'a' => 0.058139534884, 'b' => 6.33720930233],
            ['x' => 751, 'a' => 0.054945054945, 'b' => 8.73626373626],
            ['x' => 842, 'a' => 0.046728971963, 'b' => 15.6542056075],
            ['x' => 949, 'a' => 0.027027027027, 'b' => 34.3513513514]
        ],
        26684057 => [ // DAH-8851 (Bolero)
            ['x' => 5, 'a' => 0.131578947368, 'b' => -0.657894736842],
            ['x' => 43, 'a' => 0.04132231405, 'b' => 3.22314049587],
            ['x' => 164, 'a' => 0.04347826087, 'b' => 2.86956521739],
            ['x' => 279, 'a' => 0.04347826087, 'b' => 2.86956521739],
            ['x' => 394, 'a' => 0.043103448276, 'b' => 3.01724137931],
            ['x' => 510, 'a' => 0.043859649123, 'b' => 2.63157894737],
            ['x' => 624, 'a' => 0.044642857143, 'b' => 2.14285714286],
            ['x' => 736, 'a' => 0.030303030303, 'b' => 12.696969697]
        ],
        13265179 => [ // LK-1539 (Fire Bows)
            ['x' => 1, 'a' => 0.625, 'b' => -0.625],
            ['x' => 17, 'a' => 0.25, 'b' => 5.75],
            ['x' => 57, 'a' => 0.294117647059, 'b' => 3.23529411765],
            ['x' => 125, 'a' => 0.344827586207, 'b' => -3.10344827586],
            ['x' => 183, 'a' => 0.37037037037, 'b' => -7.77777777778],
            ['x' => 237, 'a' => 0.425531914894, 'b' => -20.8510638298],
            ['x' => 284, 'a' => 0.408163265306, 'b' => -15.9183673469],
            ['x' => 333, 'a' => 0.434782608696, 'b' => -24.7826086957],
            ['x' => 379, 'a' => 0.434782608696, 'b' => -24.7826086957],
            ['x' => 425, 'a' => 0.454545454545, 'b' => -33.1818181818],
            ['x' => 469, 'a' => 0.444444444444, 'b' => -28.4444444444],
            ['x' => 514, 'a' => 0.434782608696, 'b' => -23.4782608696],
            ['x' => 560, 'a' => 0.434782608696, 'b' => -23.4782608696],
            ['x' => 606, 'a' => 0.425531914894, 'b' => -17.8723404255],
            ['x' => 653, 'a' => 0.425531914894, 'b' => -17.8723404255],
            ['x' => 700, 'a' => 0.392156862745, 'b' => 5.49019607843],
            ['x' => 751, 'a' => 0.363636363636, 'b' => 26.9090909091],
            ['x' => 806, 'a' => 0.322580645161, 'b' => 60],
            ['x' => 868, 'a' => 0.253164556962, 'b' => 120.253164557],
            ['x' => 947, 'a' => 0.131578947368, 'b' => 235.394736842]
        ]
    ];

    $unitDefs = [
        28160408 => [
            'code' => 'SRV-ROOM',
            'unit_type' => 'other',
            'role' => 'facility',
            'icon' => 'server_temp',
            'device' => 'Teltonika FMB920',
            'imei' => '354018110429762',
            'phone' => '+94764429460'
        ],
        14086277 => [
            'code' => 'LK-3313',
            'unit_type' => 'bolero',
            'role' => 'unit',
            'icon' => 'bolero_silver',
            'device' => 'Teltonika FMB125',
            'imei' => '350544508657437',
            'phone' => '+94764397219'
        ],
        13275566 => [
            'code' => 'PY-3029',
            'unit_type' => 'bolero',
            'role' => 'unit',
            'icon' => 'bolero_silver',
            'device' => 'Teltonika FMB125',
            'imei' => '350544508198895',
            'phone' => '+94741227168'
        ],
        26684057 => [
            'code' => 'DAH-8851',
            'unit_type' => 'bolero',
            'role' => 'unit',
            'icon' => 'bolero_silver',
            'device' => 'Teltonika FMB125',
            'imei' => '350544508661272',
            'phone' => '+94768752781'
        ],
        13265179 => [
            'code' => 'LK-1539',
            'unit_type' => 'bowser',
            'role' => 'unit',
            'icon' => 'bowser_yellow',
            'device' => 'Teltonika FMB125',
            'imei' => '350544508198994',
            'phone' => '+94740666438'
        ]
    ];

    // Login
    $loginParams = json_encode(['token' => $token]);
    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=token/login&params=" . urlencode($loginParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $loginRaw = curl_exec($ch);
    curl_close($ch);

    $loginData = json_decode($loginRaw, true);
    $sid = $loginData['eid'] ?? null;
    if (!$sid) return ['success' => false, 'error' => 'Wialon login failed'];

    // Batch query
    $unitIdsStr = implode(',', array_keys($unitDefs));
    $searchSpec = [
        'spec' => [
            'itemsType' => 'avl_unit',
            'propName' => 'sys_id',
            'propValueMask' => $unitIdsStr,
            'sortType' => 'sys_name'
        ],
        'force' => 1,
        'flags' => 1025,
        'from' => 0,
        'to' => 0
    ];

    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=core/search_items&sid=$sid&params=" . urlencode(json_encode($searchSpec)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $unitsRaw = curl_exec($ch);
    curl_close($ch);

    $uData = json_decode($unitsRaw, true);
    $items = $uData['items'] ?? [];
    if (empty($items)) return ['success' => false, 'error' => 'No units returned'];

    $results = [];

    foreach ($items as $item) {
        $wId = (int)$item['id'];
        $cfg = $unitDefs[$wId] ?? null;
        if (!$cfg) continue;

        $name = $item['nm'];
        $uid = !empty($cfg['imei']) ? (string)$cfg['imei'] : (!empty($item['uid']) ? (string)$item['uid'] : 'WIA-' . $wId);
        $phone = !empty($cfg['phone']) ? (string)$cfg['phone'] : (!empty($item['ph']) ? (string)$item['ph'] : ('veh_' . preg_replace('/[^A-Za-z0-9]/', '', $cfg['code'])));
        $deviceType = $cfg['device'] ?? 'Teltonika FMB125';
        $lat = !empty($item['pos']['y']) ? (float)$item['pos']['y'] : 7.222895;
        $lng = !empty($item['pos']['x']) ? (float)$item['pos']['x'] : 81.675295;
        $speed = !empty($item['pos']['s']) ? (float)$item['pos']['s'] : 0.0;
        $posTime = !empty($item['pos']['t']) ? date('Y-m-d H:i:s', (int)$item['pos']['t']) : date('Y-m-d H:i:s');

        $lmsg = $item['lmsg']['p'] ?? [];
        $pwrExt = isset($lmsg['pwr_ext']) ? round((float)$lmsg['pwr_ext'], 2) : 12.0;
        $pwrInt = isset($lmsg['pwr_int']) ? round((float)$lmsg['pwr_int'], 2) : 4.0;
        $ignition = (!empty($lmsg['io_1']) || !empty($lmsg['ignition'])) ? 1 : 0;
        $motionState = ($speed > 0) ? 'moving' : ($ignition ? 'idle' : 'stationary');

        $isServerRoom = ($wId === 28160408);
        $tempCelsius = null;
        $fuelLiters = null;

        if ($isServerRoom) {
            $rawTemp = isset($lmsg['io_25']) ? (float)$lmsg['io_25'] : (isset($lmsg['io_26']) ? (float)$lmsg['io_26'] : 3000);
            $tempCelsius = round($rawTemp / 100.0, 1);
            $motionState = 'stationary';
            $ignition = 1;
        } else {
            $rawFuel = $lmsg['io_201'] ?? null;
            if ($rawFuel !== null && isset($calibrations[$wId])) {
                $rawVal = (float)$rawFuel;
                $tbl = $calibrations[$wId];
                $matched = null;
                foreach ($tbl as $seg) {
                    if ($rawVal >= $seg['x']) $matched = $seg;
                    else break;
                }
                if (!$matched) $matched = $tbl[0];
                $fuelLiters = max(0, round($matched['a'] * $rawVal + $matched['b'], 1));
            }
        }

        // Sensors definition
        $sensors = [];
        if ($isServerRoom) {
            $sensors[] = ['id' => 1, 'name' => 'Server Room Temperature', 'type' => 'temperature', 'unit' => '°C', 'param' => 'io_25', 'val' => $tempCelsius, 'desc' => 'BLE Temp Sensor', 'visible' => true];
            $sensors[] = ['id' => 2, 'name' => 'Main UPS Power', 'type' => 'voltage', 'unit' => 'V', 'param' => 'pwr_ext', 'val' => $pwrExt, 'desc' => '12V DC UPS', 'visible' => true];
            $sensors[] = ['id' => 3, 'name' => 'Internal Battery', 'type' => 'voltage', 'unit' => 'V', 'param' => 'pwr_int', 'val' => $pwrInt, 'desc' => 'Li-ion Battery', 'visible' => true];
        } else {
            if ($fuelLiters !== null) {
                $sensors[] = ['id' => 1, 'name' => 'Fuel Level (FLS)', 'type' => 'fuel', 'unit' => 'L', 'param' => 'io_201', 'val' => $fuelLiters, 'desc' => 'Diesel Fuel Tank', 'visible' => true];
            }
            $sensors[] = ['id' => 2, 'name' => 'Engine Ignition', 'type' => 'ignition', 'unit' => 'On/Off', 'param' => 'io_1', 'val' => $ignition ? 'On' : 'Off', 'desc' => 'Engine Status', 'visible' => true];
            $sensors[] = ['id' => 3, 'name' => 'Main Battery', 'type' => 'voltage', 'unit' => 'V', 'param' => 'pwr_ext', 'val' => $pwrExt, 'desc' => 'Alternator/Battery Voltage', 'visible' => true];
        }
        $sensorsJson = json_encode($sensors, JSON_UNESCAPED_UNICODE);

        // Properties JSON
        $props = [
            'wialon_id' => $wId,
            'pwr_ext' => $pwrExt,
            'pwr_int' => $pwrInt,
            'last_sync' => date('Y-m-d H:i:s')
        ];
        if ($tempCelsius !== null) $props['temperature'] = $tempCelsius;
        if ($fuelLiters !== null) $props['fuel_liters'] = $fuelLiters;
        $propertiesJson = json_encode($props, JSON_UNESCAPED_UNICODE);

        // Upsert into users table
        $existing = q("SELECT id FROM users WHERE code = ? OR unique_id = ? LIMIT 1", [$cfg['code'], $uid])->fetch();

        if ($existing) {
            $dbId = (int)$existing['id'];
            q("UPDATE users SET 
                name = ?, code = ?, unit_type = ?, role = ?, device_type = ?,
                phone = ?, unique_id = ?, last_lat = ?, last_lng = ?, speed = ?, ignition = ?, motion_state = ?,
                on_duty = 1, active = 1, last_seen = ?, sensors_json = ?, properties_json = ?,
                icon_key = ?, last_battery = ?
               WHERE id = ?",
              [$name, $cfg['code'], $cfg['unit_type'], $cfg['role'], $deviceType,
               $phone, $uid, $lat, $lng, $speed, $ignition, $motionState, $posTime, $sensorsJson, $propertiesJson,
               $cfg['icon'], ($fuelLiters !== null ? (int)$fuelLiters : ($tempCelsius !== null ? (int)$tempCelsius : (int)$pwrExt)), $dbId]);
        } else {
            $pwd = password_hash('unit_' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
            q("INSERT INTO users 
               (name, code, role, unit_type, device_type, phone, password_hash, unique_id, icon_key,
                last_lat, last_lng, speed, ignition, motion_state, on_duty, active, last_seen,
                sensors_json, properties_json, last_battery)
               VALUES 
               (?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 1, 1, ?,
                ?, ?, ?)",
              [$name, $cfg['code'], $cfg['role'], $cfg['unit_type'], $deviceType, $phone, $pwd, $uid, $cfg['icon'],
               $lat, $lng, $speed, $ignition, $motionState, $posTime,
               $sensorsJson, $propertiesJson, ($fuelLiters !== null ? (int)$fuelLiters : ($tempCelsius !== null ? (int)$tempCelsius : (int)$pwrExt))]);
            $dbId = (int)db()->lastInsertId();
        }

        q("INSERT IGNORE INTO locations (user_id, lat, lng, speed, battery, recorded_at) VALUES (?, ?, ?, ?, ?, ?)",
          [$dbId, $lat, $lng, (int)$speed, ($fuelLiters !== null ? (int)$fuelLiters : (int)$pwrExt), $posTime]);

        $results[] = [
            'id' => $dbId,
            'name' => $name,
            'code' => $cfg['code'],
            'speed' => $speed,
            'fuel_liters' => $fuelLiters,
            'temperature' => $tempCelsius,
            'voltage' => $pwrExt . 'V',
            'time' => $posTime
        ];
    }

    return ['success' => true, 'count' => count($results), 'synced_units' => $results];
}

// Standalone execution if requested directly
if (!debug_backtrace()) {
    header('Content-Type: application/json');
    try {
        $res = sync_wialon_fleet_fast();
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], JSON_PRETTY_PRINT);
    }
}
