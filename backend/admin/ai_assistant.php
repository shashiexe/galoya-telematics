<?php
require_once __DIR__ . '/_common.php';
require_admin();

// Check if user has permission to view reports or AI analytics
if (!can('reports', 'view') && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied: You do not have permission to use AI Analyst.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$apiKey = trim((string)setting('gemini_api_key', ''));
$model = trim((string)setting('gemini_model', 'gemini-3.1-flash-lite'));
if (!$model || $model === 'gemini-3.8-flash' || $model === 'gemini-flash-latest' || $model === 'gemini-2.5-flash') $model = 'gemini-3.1-flash-lite';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'chat');

if (!function_exists('haversine_dist_km')) {
    function haversine_dist_km($lat1, $lon1, $lat2, $lon2) {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

/** Robust cURL wrapper for Google Gemini API with IPv4 resolve, SSL bypass, and detailed errors */
function call_gemini_api(string $url, string $payload, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Track360/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'res' => $res,
        'http' => $httpCode,
        'err' => $curlErr,
        'errno' => $curlErrno
    ];
}

/** Robust multi-model generator with automatic fallback across fast Gemini models */
function gemini_generate_content(string $apiKey, array $models, array $payload, int $timeout = 30): array {
    $lastErr = '';
    foreach ($models as $m) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($m) . ":generateContent?key=" . urlencode($apiKey);
        $resp = call_gemini_api($url, json_encode($payload), $timeout);
        if ($resp['http'] === 200 && !empty($resp['res'])) {
            $data = json_decode($resp['res'], true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!empty($text)) {
                return ['success' => true, 'text' => $text, 'model' => $m];
            }
        }
        $lastErr = $resp['err'] ?: ($resp['res'] ?: "HTTP {$resp['http']}");
    }
    return ['success' => false, 'error' => $lastErr];
}

// 1. Action: Test Key Connectivity
if ($action === 'test_key') {
    $testKey = trim((string)($_POST['api_key'] ?? $apiKey));
    $testModel = trim((string)($_POST['model'] ?? $model));
    if (!$testKey) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter or save a Gemini API Key to test.']);
        exit;
    }

    $candidates = array_unique([$testModel, 'gemini-3.1-flash-lite', 'gemini-3.5-flash', 'gemini-3.6-flash']);
    $payload = [
        'contents' => [
            ['parts' => [['text' => 'Ping. Return only "OK"']]]
        ]
    ];

    $res = gemini_generate_content($testKey, $candidates, $payload, 12);
    if ($res['success']) {
        echo json_encode(['status' => 'ok', 'message' => 'Gemini AI connection successful with ' . $res['model'] . '!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Connection test failed: ' . $res['error']]);
    }
    exit;
}

// Ensure API key is configured for Chat
if (!$apiKey) {
    echo json_encode(['status' => 'error', 'message' => 'Gemini API Key is not configured. Please go to Settings to enter your Gemini API key.']);
    exit;
}

// 2. Action: Chat & Report Generation
if ($action === 'chat') {
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    if (!$prompt) {
        echo json_encode(['status' => 'error', 'message' => 'Prompt cannot be empty.']);
        exit;
    }

    // Check user section scope
    $secScopeU = user_section_scope_sql('u');
    $accessibleSections = user_accessible_section_ids();
    $scopeDesc = $accessibleSections === null 
        ? "Super Admin / Global Access (All divisions and sections)" 
        : "Restricted to Section IDs: (" . implode(', ', $accessibleSections) . ")";

    // -------------------------------------------------------------
    // INSTANT LIVE TELEMATICS SNAPSHOT GENERATION (Computed in ~50ms)
    // -------------------------------------------------------------
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd = date('Y-m-d 23:59:59');

    // 1. Fetch active staff / units
    $units = q("SELECT u.id, u.name, u.phone, u.role, u.unit_type, u.on_duty, u.battery, u.speed, u.last_seen, 
                       s.name as section_name, d.name as dept_name 
                FROM users u 
                LEFT JOIN sections s ON s.id=u.section_id 
                LEFT JOIN departments d ON d.id=u.department_id 
                WHERE u.role='staff' AND u.active=1 AND $secScopeU 
                ORDER BY u.name ASC")->fetchAll();

    // 2. Calculate today's exact distance, points, and work hours for each unit
    $unitTelemetryToday = [];
    foreach ($units as $u) {
        $uid = (int)$u['id'];
        $pts = q("SELECT lat, lng, speed, recorded_at 
                  FROM locations 
                  WHERE user_id=? AND recorded_at BETWEEN ? AND ? 
                  ORDER BY recorded_at ASC", [$uid, $todayStart, $todayEnd])->fetchAll();

        $distKm = 0.0;
        $maxSpd = 0.0;
        $prev = null;
        foreach ($pts as $p) {
            if ($prev) {
                $d = haversine_dist_km((float)$prev['lat'], (float)$prev['lng'], (float)$p['lat'], (float)$p['lng']);
                if ($d < 5.0) $distKm += $d; // filter GPS teleports
            }
            if ((float)$p['speed'] > $maxSpd) $maxSpd = (float)$p['speed'];
            $prev = $p;
        }

        $firstPing = !empty($pts) ? $pts[0]['recorded_at'] : null;
        $lastPing = !empty($pts) ? end($pts)['recorded_at'] : null;
        $dutyHours = ($firstPing && $lastPing) ? round((strtotime($lastPing) - strtotime($firstPing)) / 3600, 1) : 0.0;

        $unitTelemetryToday[] = [
            'id' => $uid,
            'name' => $u['name'],
            'unit_type' => $u['unit_type'] ?: 'Field Officer',
            'section' => $u['section_name'] ?: 'General',
            'department' => $u['dept_name'] ?: 'Agriculture',
            'distance_km' => round($distKm, 2),
            'max_speed_kmh' => round($maxSpd, 1),
            'current_speed_kmh' => round((float)$u['speed'], 1),
            'points_today' => count($pts),
            'first_seen' => $firstPing ? date('H:i', strtotime($firstPing)) : 'Not Started',
            'last_seen' => $lastPing ? date('H:i', strtotime($lastPing)) : ($u['last_seen'] ? date('H:i', strtotime($u['last_seen'])) : 'Offline'),
            'duty_hours' => $dutyHours,
            'battery' => $u['battery'] !== null ? $u['battery'] . '%' : 'N/A',
            'status' => ((int)$u['on_duty'] === 1 && strtotime($u['last_seen'] ?? '') >= strtotime('-15 minutes')) ? 'Online' : 'Offline'
        ];
    }

    // Sort officers by distance traveled today (descending)
    usort($unitTelemetryToday, function($a, $b) {
        return $b['distance_km'] <=> $a['distance_km'];
    });

    // 3. Fetch latest field visit photos
    $recentPhotos = q("SELECT p.id, u.name as officer, p.geofence_name as plot, p.activity_type, p.note, p.taken_at 
                       FROM field_photos p 
                       JOIN users u ON u.id=p.user_id 
                       WHERE $secScopeU 
                       ORDER BY p.taken_at DESC LIMIT 15")->fetchAll();

    // 4. Fetch SOS emergency alerts
    $sosAlerts = q("SELECT s.id, u.name as officer, s.lat, s.lng, s.battery, s.status, s.created_at 
                    FROM sos_alerts s 
                    JOIN users u ON u.id=s.user_id 
                    WHERE $secScopeU 
                    ORDER BY s.created_at DESC LIMIT 10")->fetchAll();

    // 5. Fetch recent tasks
    $recentTasks = q("SELECT t.id, u.name as officer, t.title, t.status, t.due_date 
                      FROM tasks t 
                      JOIN users u ON u.id=t.user_id 
                      WHERE $secScopeU 
                      ORDER BY t.created_at DESC LIMIT 10")->fetchAll();

    // Context package
    $liveContext = [
        'current_time_sl' => date('Y-m-d H:i:s'),
        'user_scope' => $scopeDesc,
        'summary' => [
            'total_units' => count($unitTelemetryToday),
            'online_units' => count(array_filter($unitTelemetryToday, fn($x) => $x['status'] === 'Online')),
            'total_distance_km' => round(array_sum(array_column($unitTelemetryToday, 'distance_km')), 2),
            'total_photos_today' => count(array_filter($recentPhotos, fn($x) => substr($x['taken_at'], 0, 10) === date('Y-m-d'))),
            'active_sos_alerts' => count(array_filter($sosAlerts, fn($x) => $x['status'] === 'active')),
        ],
        'ranked_officers_by_distance_today' => $unitTelemetryToday,
        'latest_field_photos' => $recentPhotos,
        'emergency_sos_alerts' => $sosAlerts,
        'assigned_tasks' => $recentTasks
    ];

    // Single-turn super prompt: Give live telematics context directly to Gemini!
    $systemInstruction = "You are Track360 AI Analyst for GalOya Plantations (Hingurana, Sri Lanka).
You are an executive telematics AI assistant.
The current local time is " . date('Y-m-d H:i:s') . ".
User access scope: $scopeDesc.

LIVE REAL-TIME TELEMATICS CONTEXT:
" . json_encode($liveContext, JSON_PRETTY_PRINT) . "

INSTRUCTIONS:
1. Answer the user question directly, accurately, and immediately using the live telematics context provided above.
2. If the user asked in Sinhala or Singlish (e.g. 'top distance filde officer kawda?', 'ada dawase summary 1k denna'), reply naturally in clear, professional Sinhala Singlish (or English if user asked in English).
3. Always highlight top performers, distances, and key metrics in bold.
4. When presenting multiple officers, distance rankings, plots, or summaries, ALWAYS format them as a clean Markdown Table (`| Rank | Officer Name | Section | Distance (km) | Duty Hours | Battery | Status |`).
5. Be concise, authoritative, and direct. No filler words.";

    $payload = [
        'system_instruction' => [
            'parts' => [['text' => $systemInstruction]]
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ];

    $modelsToTry = array_unique([$model, 'gemini-3.1-flash-lite', 'gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-3-flash-preview']);

    $aiRes = gemini_generate_content($apiKey, $modelsToTry, $payload, 15);
    if (!$aiRes['success']) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gemini API Error: ' . $aiRes['error']
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'answer' => $aiRes['text'],
        'model' => $aiRes['model'],
        'row_count' => count($unitTelemetryToday),
        'data' => $unitTelemetryToday
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
