<?php
// backend/sync_wialon_sensors.php - Auto-Capture and Import All Sensors from Wialon
require_once __DIR__ . '/bootstrap.php';

function sync_wialon_sensors_all() {
    $token = "14534c72a9fd9f73e079d703d6fe9a8a01178A0335ADA2D1A9647DBD59561FC816FB2570";

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
    if (!$sid) return ['success' => false, 'error' => 'Wialon login failed'];

    // 2. Fetch units with sensors flag (4096 = sensors, 1 = base info)
    $searchSpec = [
        'spec' => [
            'itemsType' => 'avl_unit',
            'propName' => 'sys_name',
            'propValueMask' => '*',
            'sortType' => 'sys_name'
        ],
        'force' => 1,
        'flags' => 4097, // 4096 + 1
        'from' => 0,
        'to' => 0
    ];

    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=core/search_items&sid=$sid&params=" . urlencode(json_encode($searchSpec)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $unitsRaw = curl_exec($ch);
    curl_close($ch);

    $uData = json_decode($unitsRaw, true);
    $items = $uData['items'] ?? [];
    if (empty($items)) return ['success' => false, 'error' => 'No units returned'];

    $wialonMap = [
        28160408 => 'SRV-ROOM',
        14086277 => 'LK-3313',
        13275566 => 'PY-3029',
        26684057 => 'DAH-8851',
        13265179 => 'LK-1539'
    ];

    $updated = [];

    foreach ($items as $item) {
        $wId = (int)$item['id'];
        $code = $wialonMap[$wId] ?? null;
        if (!$code) continue;

        $dbUnit = q("SELECT id, name, sensors_json FROM users WHERE code = ? LIMIT 1", [$code])->fetch();
        if (!$dbUnit) continue;

        $sensors = [];
        if (!empty($item['sens'])) {
            foreach ($item['sens'] as $s) {
                $c = [];
                if (!empty($s['c'])) {
                    $c = is_string($s['c']) ? json_decode($s['c'], true) : $s['c'];
                }

                $fuelParams = $c['fuel_params'] ?? [];

                $sensors[] = [
                    'id' => (int)$s['id'],
                    'name' => (string)$s['n'],
                    'type' => (string)$s['t'],
                    'param' => (string)$s['p'],
                    'unit' => (string)$s['m'],
                    'desc' => (string)($s['d'] ?? ''),
                    'visible' => isset($c['appear_in_popup']) ? (bool)$c['appear_in_popup'] : true,
                    'lower_bound' => $c['lower_bound'] ?? null,
                    'upper_bound' => $c['upper_bound'] ?? null,
                    'min_filling' => $fuelParams['minFillingVolume'] ?? null,
                    'min_theft' => $fuelParams['minTheftVolume'] ?? null,
                    'consumption' => $c['consumption'] ?? null,
                    'table' => $s['tbl'] ?? []
                ];
            }
        }

        $sensorsJson = json_encode($sensors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        q("UPDATE users SET sensors_json = ? WHERE id = ?", [$sensorsJson, $dbUnit['id']]);

        $updated[] = [
            'id' => $dbUnit['id'],
            'name' => $dbUnit['name'],
            'code' => $code,
            'wialon_id' => $wId,
            'sensors_count' => count($sensors)
        ];
    }

    return ['success' => true, 'updated_units' => $updated];
}

if (!debug_backtrace()) {
    header('Content-Type: application/json');
    echo json_encode(sync_wialon_sensors_all(), JSON_PRETTY_PRINT);
}
