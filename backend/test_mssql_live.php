<?php
header('Content-Type: application/json; charset=utf-8');

$host = '124.43.10.210';
$port = 41511;
$db   = 'GaloyaTracker';
$user = 'sa';
$pass = '(GopliT@#4900%)';

$res = [
    'time' => date('Y-m-d H:i:s'),
    'host' => $host,
    'port' => $port,
    'socket_open' => false,
    'pdo_drivers' => pdo_drivers(),
    'mssql_connected' => false,
    'error' => null
];

// 1. Test TCP port reachability
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp) {
    $res['socket_open'] = true;
    fclose($fp);
} else {
    $res['socket_error'] = "$errno - $errstr";
}

// 2. Try PDO dblib or ODBC or sqlsrv if available
if (in_array('dblib', $res['pdo_drivers'])) {
    try {
        $pdo = new PDO("dblib:host=$host:$port;dbname=$db", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
        $row = $pdo->query("SELECT @@VERSION as ver, DB_NAME() as db")->fetch(PDO::FETCH_ASSOC);
        $res['mssql_connected'] = true;
        $res['driver_used'] = 'dblib';
        $res['server_info'] = $row;
    } catch (Throwable $e) {
        $res['dblib_error'] = $e->getMessage();
    }
}

if (!$res['mssql_connected'] && in_array('sqlsrv', $res['pdo_drivers'])) {
    try {
        $pdo = new PDO("sqlsrv:Server=$host,$port;Database=$db", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
        $row = $pdo->query("SELECT @@VERSION as ver, DB_NAME() as db")->fetch(PDO::FETCH_ASSOC);
        $res['mssql_connected'] = true;
        $res['driver_used'] = 'sqlsrv';
        $res['server_info'] = $row;
    } catch (Throwable $e) {
        $res['sqlsrv_error'] = $e->getMessage();
    }
}

if (!$res['mssql_connected'] && in_array('odbc', $res['pdo_drivers'])) {
    try {
        $pdo = new PDO("odbc:Driver={FreeTDS};Server=$host;Port=$port;Database=$db;TDS_Version=7.2;Uid=$user;Pwd=$pass;");
        $row = $pdo->query("SELECT @@VERSION as ver, DB_NAME() as db")->fetch(PDO::FETCH_ASSOC);
        $res['mssql_connected'] = true;
        $res['driver_used'] = 'odbc';
        $res['server_info'] = $row;
    } catch (Throwable $e) {
        $res['odbc_error'] = $e->getMessage();
    }
}

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
