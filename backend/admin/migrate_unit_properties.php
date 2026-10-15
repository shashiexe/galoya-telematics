<?php
require __DIR__ . '/../bootstrap.php';
$pdo = db();

// Ensure columns exist on users table
$cols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);

$needed = [
    'device_type' => "VARCHAR(100) NOT NULL DEFAULT 'Teltonika FMB125'",
    'server_address' => "VARCHAR(150) NOT NULL DEFAULT 'nl.gpsgsm.org:21448'",
    'unique_id' => "VARCHAR(100) NULL",
    'icon_key' => "VARCHAR(50) NOT NULL DEFAULT 'tractor_blue'",
    'mileage_val' => "FLOAT NOT NULL DEFAULT 0",
    'engine_hours_val' => "FLOAT NOT NULL DEFAULT 0",
    'gprs_traffic_val' => "INT NOT NULL DEFAULT 0",
    'consumption_rate' => "FLOAT NOT NULL DEFAULT 10",
    'speed_limit' => "INT NOT NULL DEFAULT 60",
    'sensors_json' => "LONGTEXT NULL",
    'properties_json' => "LONGTEXT NULL"
];

foreach ($needed as $col => $def) {
    if (!in_array($col, $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        echo "Added column: $col\n";
    }
}

// Seed default realistic unit properties for 49-1665 matching screenshot
$defaultSensors491665 = json_encode([
    ['id' => 1, 'name' => 'Engine', 'type' => 'Engine ignition sensor', 'unit' => 'Start/Stop', 'param' => 'pwr_ext', 'desc' => 'Main ignition', 'visible' => true],
    ['id' => 2, 'name' => 'Fls', 'type' => 'Fuel level sensor', 'unit' => 'l', 'param' => 'io_201', 'desc' => 'Diesel tank 180L', 'visible' => true],
    ['id' => 3, 'name' => 'ACC', 'type' => 'Engine ignition sensor', 'unit' => 'On/Off', 'param' => 'io_1', 'desc' => 'Accessory switch', 'visible' => true],
    ['id' => 4, 'name' => 'Battery', 'type' => 'Custom digital sensor', 'unit' => 'Ok/Not ok', 'param' => 'pwr_ext', 'desc' => '12V internal battery', 'visible' => true],
    ['id' => 5, 'name' => 'AB', 'type' => 'Custom digital sensor', 'unit' => 'On/Off', 'param' => 'pwr_ext', 'desc' => 'Air brake sensor', 'visible' => false],
    ['id' => 6, 'name' => 'Voltage', 'type' => 'Voltage sensor', 'unit' => 'V', 'param' => 'pwr_int', 'desc' => 'Alternator voltage', 'visible' => true]
]);

$defaultProps491665 = json_encode([
    'custom_fields' => [
        'Chassis No' => 'FMB-49-1665-LK',
        'Engine No' => 'CUMMINS-6BTA-5.9',
        'Operator' => 'Chandrasena (Driver)',
        'Division' => 'Hingurana Sector D/207',
        'Year' => '2021'
    ],
    'advanced' => [
        'consumption_rates' => 10,
        'seasonal_consumption' => true,
        'seasonal_rate' => 12,
        'seasonal_from' => '1 December',
        'seasonal_to' => '29 February',
        'urban_speed_limit' => 60,
        'max_interval_msg' => 0,
        'avg_speed_msg' => 1000,
        'dist_coords' => 1000,
        'daily_engine_hours' => 0,
        'mileage_coef' => 0
    ],
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
]);

$stmt = $pdo->prepare("UPDATE users SET 
    device_type='Teltonika FMB125',
    server_address='nl.gpsgsm.org:21448',
    unique_id='359632104298952',
    phone='+94742198748',
    icon_key='tractor_blue',
    sensors_json=?,
    properties_json=?
WHERE name LIKE '%49-1665%'");
$stmt->execute([$defaultSensors491665, $defaultProps491665]);

// Give all other units good default device_type and unique_ids
$pdo->exec("UPDATE users SET unique_id = CONCAT('359632104', LPAD(id, 6, '0')) WHERE unique_id IS NULL");
$pdo->exec("UPDATE users SET icon_key = CASE 
    WHEN unit_type='tractor' THEN 'tractor_blue'
    WHEN unit_type='harvester' THEN 'harvester_green'
    WHEN unit_type='infielder' THEN 'infielder_orange'
    WHEN unit_type='vehicle' THEN 'vehicle_white'
    WHEN unit_type='excavator' THEN 'excavator_yellow'
    ELSE 'staff_person'
END WHERE icon_key IS NULL OR icon_key='tractor_blue'");

echo "Unit properties migrated and seeded successfully.\n";
