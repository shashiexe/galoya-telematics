<?php
require __DIR__ . '/_common.php';
require_admin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $unit = q("SELECT id, name, code, phone, role, unit_type, device_type, server_address, unique_id, icon_key,
                      mileage_val, engine_hours_val, gprs_traffic_val, consumption_rate, speed_limit,
                      sensors_json, properties_json, active
               FROM users WHERE id=?", [$id])->fetch();

    if (!$unit) {
        http_response_code(404);
        echo json_encode(['error' => 'Unit not found']);
        exit;
    }

    $sensors = json_decode($unit['sensors_json'] ?? '', true);
    if (!is_array($sensors) || empty($sensors)) {
        // Default standard telematics sensors matching Screenshot 5
        $sensors = [
            ['id' => 1, 'name' => 'Engine', 'type' => 'Engine ignition sensor', 'unit' => 'Start/Stop', 'param' => 'pwr_ext', 'desc' => 'Engine ignition status', 'visible' => true],
            ['id' => 2, 'name' => 'Fls', 'type' => 'Fuel level sensor', 'unit' => 'l', 'param' => 'io_201', 'desc' => 'Diesel fuel tank sensor', 'visible' => true],
            ['id' => 3, 'name' => 'ACC', 'type' => 'Engine ignition sensor', 'unit' => 'On/Off', 'param' => 'io_1', 'desc' => 'Accessory switch status', 'visible' => true],
            ['id' => 4, 'name' => 'Battery', 'type' => 'Custom digital sensor', 'unit' => 'Ok/Not ok', 'param' => 'pwr_ext', 'desc' => 'Internal battery health', 'visible' => true],
            ['id' => 5, 'name' => 'AB', 'type' => 'Custom digital sensor', 'unit' => 'On/Off', 'param' => 'pwr_ext', 'desc' => 'Air brake sensor', 'visible' => false],
            ['id' => 6, 'name' => 'Voltage', 'type' => 'Voltage sensor', 'unit' => 'V', 'param' => 'pwr_int', 'desc' => 'Alternator power line', 'visible' => true]
        ];
    }

    $properties = json_decode($unit['properties_json'] ?? '', true);
    if (!is_array($properties)) {
        $properties = [
            'custom_fields' => [
                'Chassis No' => 'CH-' . ($unit['code'] ?: '491665'),
                'Engine No' => 'ENG-' . rand(100000, 999999),
                'Operator' => 'Chandrasena',
                'Division' => 'Hingurana Sector D/207',
                'Year' => '2022'
            ],
            'advanced' => [
                'consumption_rates' => $unit['consumption_rate'] ?: 10,
                'seasonal_consumption' => true,
                'seasonal_rate' => 12,
                'seasonal_from' => '1 December',
                'seasonal_to' => '29 February',
                'urban_speed_limit' => $unit['speed_limit'] ?: 60,
                'max_interval_msg' => 0,
                'avg_speed_msg' => 1000,
                'dist_coords' => 1000,
                'daily_engine_hours' => 0,
                'mileage_coef' => 0
            ],
            'unit_groups' => ['Hingurana Fleet', 'Sector D Machinery'],
            'service_intervals' => [
                ['name' => 'Engine Oil & Filter Service', 'interval' => '250 h', 'last' => '120 h', 'remaining' => '130 h', 'status' => 'ok'],
                ['name' => 'Hydraulic Filter Replacement', 'interval' => '500 h', 'last' => '350 h', 'remaining' => '150 h', 'status' => 'ok'],
                ['name' => 'Full Estate Machinery Inspection', 'interval' => '1000 h', 'last' => '900 h', 'remaining' => '100 h', 'status' => 'warning']
            ],
            'access_rights' => [
                'fleet.galoya' => 'full',
                'fleet.galoyanew' => 'full',
                'fleet2.galoya' => 'full',
                'galoya' => 'full',
                'galoya1' => 'full',
                'galoya_geofence' => 'full',
                'chandrasena' => 'full',
                'viraj' => 'full'
            ]
        ];
    }

    $unit['sensors'] = $sensors;
    $unit['properties'] = $properties;
    
    // Default server address and device type if not populated
    if (empty($unit['device_type'])) $unit['device_type'] = 'Teltonika FMB125';
    if (empty($unit['server_address'])) $unit['server_address'] = 'nl.gpsgsm.org:21448';
    if (empty($unit['unique_id'])) $unit['unique_id'] = '359632104' . str_pad($unit['id'], 6, '0', STR_PAD_LEFT);
    if (empty($unit['icon_key'])) $unit['icon_key'] = 'tractor_blue';

    echo json_encode(['success' => true, 'unit' => $unit]);
    exit;
}

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Unit ID required']);
        exit;
    }

    $name = trim($input['name'] ?? '');
    $unit_type = trim($input['unit_type'] ?? 'tractor');
    $device_type = trim($input['device_type'] ?? 'Teltonika FMB125');
    $server_address = trim($input['server_address'] ?? 'nl.gpsgsm.org:21448');
    $unique_id = trim($input['unique_id'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $icon_key = trim($input['icon_key'] ?? 'tractor_blue');
    $mileage_val = (float)($input['mileage_val'] ?? 0);
    $engine_hours_val = (float)($input['engine_hours_val'] ?? 0);
    $gprs_traffic_val = (int)($input['gprs_traffic_val'] ?? 0);
    $consumption_rate = (float)($input['consumption_rate'] ?? 10);
    $speed_limit = (int)($input['speed_limit'] ?? 60);

    if (!can('units', 'edit')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: You do not have permission to edit fleet units']);
        exit;
    }

    $sensors_json = is_string($input['sensors_json'] ?? null) 
        ? $input['sensors_json'] 
        : json_encode($input['sensors'] ?? []);

    $properties_json = is_string($input['properties_json'] ?? null) 
        ? $input['properties_json'] 
        : json_encode($input['properties'] ?? []);

    $stmt = q("UPDATE users SET 
        name=?, unit_type=?, device_type=?, server_address=?, unique_id=?, phone=?, icon_key=?,
        mileage_val=?, engine_hours_val=?, gprs_traffic_val=?, consumption_rate=?, speed_limit=?,
        sensors_json=?, properties_json=?
        WHERE id=?",
        [
            $name, $unit_type, $device_type, $server_address, $unique_id, $phone, $icon_key,
            $mileage_val, $engine_hours_val, $gprs_traffic_val, $consumption_rate, $speed_limit,
            $sensors_json, $properties_json,
            $id
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => "Properties of '$name' updated successfully!"
    ]);
    exit;
}

if ($action === 'create') {
    if (!can('units', 'create')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: You do not have permission to register new fleet units']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $name = trim($input['name'] ?? '');
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unit name is required']);
        exit;
    }
    $code = trim($input['code'] ?? '');
    $unit_type = trim($input['unit_type'] ?? 'tractor');
    $device_type = trim($input['device_type'] ?? 'Fifotrack A300');
    $unique_id = trim($input['unique_id'] ?? ('86619203' . rand(1000000, 9999999)));
    $phone = trim($input['phone'] ?? ('+9474' . rand(1000000, 9999999)));
    $icon_key = trim($input['icon_key'] ?? 'tractor_blue');
    $lat = 7.2095 + (rand(-80, 80) / 10000);
    $lng = 81.6575 + (rand(-80, 80) / 10000);
    $pwd = password_hash('123456', PASSWORD_DEFAULT);

    q("INSERT INTO users (name, code, phone, password_hash, role, unit_type, device_type, unique_id, icon_key, last_lat, last_lng, last_battery, speed, active, on_duty, last_seen)
       VALUES (?,?,?,?,'staff',?,?,?,?,?,?,85,0,1,1,NOW())",
      [$name, $code, $phone, $pwd, $unit_type, $device_type, $unique_id, $icon_key, $lat, $lng]);
    $newId = (int)db()->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'message' => "Unit '$name' created and added to fleet!"
    ]);
    exit;
}

if ($action === 'delete') {
    if (!can('units', 'delete')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: You do not have permission to delete fleet units']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    if ($id > 0) {
        q("DELETE FROM users WHERE id=? AND role='staff'", [$id]);
        echo json_encode(['success' => true, 'message' => 'Unit deleted from fleet successfully!']);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Invalid unit ID']);
    exit;
}

