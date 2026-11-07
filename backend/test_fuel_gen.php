<?php
require __DIR__ . '/bootstrap.php';

if (($_GET['key'] ?? '') !== 'galoya2026') {
    die("Access denied");
}

header('Content-Type: application/json');

$unit = q("SELECT id, name, code, unit_type, icon_key, sensors_json, properties_json, unique_id FROM users WHERE id=34")->fetch();

if (!$unit) {
    echo json_encode(['error' => 'Unit 34 not found']);
    exit;
}

$dateFrom = date('Y-m-d 00:00:00');
$dateTo = date('Y-m-d 23:59:59');

// Copy function from reports.php
function test_fetch_wialon_history_points($wId, $dateFrom, $dateTo) {
    $token = "14534c72a9fd9f73e079d703d6fe9a8a01178A0335ADA2D1A9647DBD59561FC816FB2570";
    $loginParams = json_encode(['token' => $token]);
    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=token/login&params=" . urlencode($loginParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $loginRaw = curl_exec($ch);
    curl_close($ch);
    
    $loginData = json_decode($loginRaw, true);
    $sid = $loginData['eid'] ?? null;
    if (!$sid) return ['error' => 'Login failed', 'raw' => $loginRaw];
    
    $fromUnix = strtotime($dateFrom);
    $toUnix = strtotime($dateTo);
    
    $params = [
        'itemId' => (int)$wId,
        'timeFrom' => $fromUnix,
        'timeTo' => $toUnix,
        'flags' => 1,
        'flagsMask' => 65281,
        'loadCount' => 5000
    ];
    
    $ch = curl_init("https://hst-api.wialon.com/wialon/ajax.html?svc=messages/load_interval&sid=$sid&params=" . urlencode(json_encode($params)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $msgRaw = curl_exec($ch);
    curl_close($ch);
    
    $msgData = json_decode($msgRaw, true);
    return [
        'count' => count($msgData['messages'] ?? []),
        'sample' => array_slice($msgData['messages'] ?? [], 0, 2),
        'error' => $msgData['error'] ?? null
    ];
}

$props = json_decode($unit['properties_json'] ?? '[]', true) ?: [];
$wId = $props['wialon_id'] ?? null;

$wRes = test_fetch_wialon_history_points($wId, $dateFrom, $dateTo);

echo json_encode([
    'unit' => $unit['name'],
    'wId' => $wId,
    'wialon_response' => $wRes
], JSON_PRETTY_PRINT);
