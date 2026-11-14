<?php
/**
 * GalOya ERP & Telematics Cloud Sync Daemon (PHP Server Edition)
 * -------------------------------------------------------------
 * This script runs directly on the cloud server (cPanel / Linux Cron / HTTP trigger)
 * to eliminate the dependency on local laptop.
 *
 * Requirements on server:
 * 1. PHP PDO with pdo_dblib OR sqlsrv OR direct cURL bridge.
 * 2. Access to MSSQL Server 124.43.10.210:41511.
 *
 * Usage:
 * - cPanel Cron: php -f /home/galoya5/public_html/server_erp_sync.php
 * - Or HTTP Webhook: https://track.galoya.lk/server_erp_sync.php?key=galoya2026
 */

require_once __DIR__ . '/bootstrap.php';

$key = $_GET['key'] ?? ($argv[1] ?? '');
if ($key !== 'galoya2026' && php_sapi_name() !== 'cli') {
    die("Access denied.");
}

header('Content-Type: application/json; charset=utf-8');

$mssqlHost = '124.43.10.210:41511';
$mssqlDb   = 'GaloyaTracker';
$mssqlUser = 'sa';
$mssqlPass = '(GopliT@#4900%)';

$report = [
    'time' => date('Y-m-d H:i:s'),
    'drivers' => pdo_drivers(),
    'mssql_connected' => false,
    'cdns_synced' => 0,
    'wbt_updated' => 0,
    'errors' => []
];

// Test if MSSQL PDO driver is available on cPanel
$pdoMssql = null;
try {
    if (in_array('dblib', pdo_drivers())) {
        $pdoMssql = new PDO("dblib:host=$mssqlHost;dbname=$mssqlDb", $mssqlUser, $mssqlPass, [PDO::ATTR_TIMEOUT => 15]);
        $report['mssql_connected'] = true;
    } elseif (in_array('sqlsrv', pdo_drivers())) {
        $pdoMssql = new PDO("sqlsrv:Server=$mssqlHost;Database=$mssqlDb", $mssqlUser, $mssqlPass, ["LoginTimeout" => 15]);
        $report['mssql_connected'] = true;
    } else {
        $report['errors'][] = "MSSQL driver (pdo_dblib / pdo_sqlsrv) is not enabled on this PHP server.";
    }
} catch (Throwable $e) {
    $report['errors'][] = "MSSQL Connect error: " . $e->getMessage();
}

if ($pdoMssql) {
    // 1. Sync pending CDNs from MySQL -> MSSQL
    $pendingCdns = q("SELECT * FROM cdn_details WHERE synced_to_mssql = 0 ORDER BY id ASC LIMIT 50")->fetchAll();
    foreach ($pendingCdns as $c) {
        try {
            $st = $pdoMssql->prepare("INSERT INTO [dbo].[CDN_Details] 
                ([cdn], [cdn_issued], [plot_no], [plot_manual_code], [farmer_no], [farmer_name], [field_asst_no], [field_asst_name], [type], [cane_category_code], [vehicle_no], [trailer_no], [crop_cycle], [crop_cycle_id], [lst_hvst_tonnage], [status], [create_user], [create_time])
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $c['cdn'], $c['cdn_issued'], $c['plot_no'], $c['plot_manual_code'], $c['farmer_no'], $c['farmer_name'],
                $c['field_asst_no'], $c['field_asst_name'], $c['type'], $c['cane_category_code'], $c['vehicle_no'],
                $c['trailer_no'], $c['crop_cycle'], $c['crop_cycle_id'], $c['lst_hvst_tonnage'], $c['status'],
                $c['create_user'], $c['create_time']
            ]);
            q("UPDATE cdn_details SET synced_to_mssql = 1 WHERE id = ?", [$c['id']]);
            $report['cdns_synced']++;
        } catch (Throwable $ex) {
            $report['errors'][] = "CDN {$c['cdn']} sync error: " . $ex->getMessage();
        }
    }

    // 2. Sync Weighbridge tickets from MSSQL -> MySQL
    try {
        $wbtRows = $pdoMssql->query("SELECT [cdn], [WeigeTicket_No], [net_weight], [TonnageRecived], [WBTCreate_Time], [modifyDate] FROM [dbo].[CDN_Details] WHERE [WeigeTicket_No] IS NOT NULL AND LTRIM(RTRIM([WeigeTicket_No])) != '' AND ([Delete] = 0 OR [Delete] IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($wbtRows as $w) {
            $net = (float)($w['net_weight'] ?? 0);
            $tonnage = $net > 0 ? round($net / 1000.0, 2) : (float)($w['TonnageRecived'] ?? 0);
            $stUp = db()->prepare("UPDATE cdn_details SET weige_ticket_no = ?, net_weight = ?, tonnage_received = ?, status = 'COMPLETED', weighed_date = ? WHERE cdn = ? AND (weige_ticket_no IS NULL OR weige_ticket_no = '')");
            $stUp->execute([$w['WeigeTicket_No'], $net, $tonnage, $w['WBTCreate_Time'], $w['cdn']]);
            $report['wbt_updated'] += $stUp->rowCount();
        }
    } catch (Throwable $ex) {
        $report['errors'][] = "WBT sync error: " . $ex->getMessage();
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
