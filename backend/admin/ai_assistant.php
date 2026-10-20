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

// 0. Action: Quick Online Status Check
if ($action === 'status_check') {
    echo json_encode([
        'status' => 'ok',
        'online' => true,
        'ai_ready' => !empty($apiKey),
        'model' => $model
    ]);
    exit;
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

    // -------------------------------------------------------------------------
    // INSTANT MULTI-PERIOD TELEMATICS ENGINE (Today, Yesterday, Week, & Month)
    // -------------------------------------------------------------------------
    $monthStart = date('Y-m-01 00:00:00');
    $todayDate = date('Y-m-d');
    $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
    $weekStartTs = strtotime('-7 days 00:00:00');

    // 1. Fetch active staff / units
    $units = q("SELECT u.id, u.name, u.phone, u.role, u.unit_type, u.on_duty, u.last_battery as battery, u.speed, u.last_seen, 
                       s.name as section_name, d.name as dept_name 
                FROM users u 
                LEFT JOIN sections s ON s.id=u.section_id 
                LEFT JOIN departments d ON d.id=u.department_id 
                WHERE u.role='staff' AND u.active=1 AND $secScopeU 
                ORDER BY u.name ASC")->fetchAll();

    $monthlyOfficerRankings = [];
    $todayOfficerRankings = [];
    $yesterdayOfficerRankings = [];
    $weeklyOfficerRankings = [];
    $sectionAggregation = [];

    // 2. Compute multi-period metrics for each unit in ONE single fast pass
    foreach ($units as $u) {
        $uid = (int)$u['id'];
        $secName = $u['section_name'] ?: 'General';
        $deptName = $u['dept_name'] ?: 'Agriculture';

        if (!isset($sectionAggregation[$secName])) {
            $sectionAggregation[$secName] = [
                'section' => $secName,
                'department' => $deptName,
                'staff_count' => 0,
                'distance_today_km' => 0.0,
                'distance_month_km' => 0.0,
                'photos_this_month' => 0
            ];
        }
        $sectionAggregation[$secName]['staff_count']++;

        // Fetch all points for this month in ONE query per user
        $pts = q("SELECT lat, lng, speed, recorded_at 
                  FROM locations 
                  WHERE user_id=? AND recorded_at >= ? 
                  ORDER BY recorded_at ASC", [$uid, $monthStart])->fetchAll();

        $monthKm = 0.0;
        $weekKm = 0.0;
        $yesterdayKm = 0.0;
        $todayKm = 0.0;

        $maxSpdMonth = 0.0;
        $maxSpdToday = 0.0;

        $workDates = [];
        $todayPts = [];
        $yesterdayPts = [];
        $prev = null;

        foreach ($pts as $p) {
            $d = 0.0;
            $rDate = substr($p['recorded_at'], 0, 10);
            $rTs = strtotime($p['recorded_at']);
            $workDates[$rDate] = true;

            if ($prev) {
                if (substr($prev['recorded_at'], 0, 10) === $rDate) {
                    $dist = haversine_dist_km((float)$prev['lat'], (float)$prev['lng'], (float)$p['lat'], (float)$p['lng']);
                    if ($dist < 5.0) $d = $dist; // filter GPS teleports
                }
            }

            $spd = (float)$p['speed'];
            if ($spd > $maxSpdMonth) $maxSpdMonth = $spd;

            $monthKm += $d;

            if ($rTs >= $weekStartTs) {
                $weekKm += $d;
            }

            if ($rDate === $yesterdayDate) {
                $yesterdayKm += $d;
                $yesterdayPts[] = $p;
            }

            if ($rDate === $todayDate) {
                $todayKm += $d;
                if ($spd > $maxSpdToday) $maxSpdToday = $spd;
                $todayPts[] = $p;
            }

            $prev = $p;
        }

        $todayFirst = !empty($todayPts) ? $todayPts[0]['recorded_at'] : null;
        $todayLast = !empty($todayPts) ? end($todayPts)['recorded_at'] : null;
        $todayDutyHours = ($todayFirst && $todayLast) ? round((strtotime($todayLast) - strtotime($todayFirst)) / 3600, 1) : 0.0;

        $yesterdayFirst = !empty($yesterdayPts) ? $yesterdayPts[0]['recorded_at'] : null;
        $yesterdayLast = !empty($yesterdayPts) ? end($yesterdayPts)['recorded_at'] : null;
        $yesterdayDutyHours = ($yesterdayFirst && $yesterdayLast) ? round((strtotime($yesterdayLast) - strtotime($yesterdayFirst)) / 3600, 1) : 0.0;

        $sectionAggregation[$secName]['distance_today_km'] = round($sectionAggregation[$secName]['distance_today_km'] + $todayKm, 2);
        $sectionAggregation[$secName]['distance_month_km'] = round($sectionAggregation[$secName]['distance_month_km'] + $monthKm, 2);

        $baseInfo = [
            'id' => $uid,
            'name' => $u['name'],
            'unit_type' => $u['unit_type'] ?: 'Field Officer',
            'section' => $secName,
            'department' => $deptName,
            'battery' => $u['battery'] !== null ? $u['battery'] . '%' : 'N/A',
            'status' => ((int)$u['on_duty'] === 1 && strtotime($u['last_seen'] ?? '') >= strtotime('-15 minutes')) ? 'Online' : 'Offline'
        ];

        // Monthly Record
        $monthlyOfficerRankings[] = array_merge($baseInfo, [
            'month_distance_km' => round($monthKm, 2),
            'days_worked_this_month' => count($workDates),
            'month_points' => count($pts),
            'max_speed_kmh' => round($maxSpdMonth, 1),
            'today_distance_km' => round($todayKm, 2),
        ]);

        // Today Record
        $todayOfficerRankings[] = array_merge($baseInfo, [
            'distance_km' => round($todayKm, 2),
            'duty_hours' => $todayDutyHours,
            'first_seen' => $todayFirst ? date('H:i', strtotime($todayFirst)) : 'Not Started',
            'last_seen' => $todayLast ? date('H:i', strtotime($todayLast)) : ($u['last_seen'] ? date('H:i', strtotime($u['last_seen'])) : 'Offline'),
            'max_speed_kmh' => round($maxSpdToday, 1),
            'points_today' => count($todayPts),
            'month_total_km' => round($monthKm, 2),
        ]);

        // Yesterday Record
        $yesterdayOfficerRankings[] = array_merge($baseInfo, [
            'distance_km' => round($yesterdayKm, 2),
            'duty_hours' => $yesterdayDutyHours,
            'points_yesterday' => count($yesterdayPts)
        ]);

        // Weekly Record
        $weeklyOfficerRankings[] = array_merge($baseInfo, [
            'week_distance_km' => round($weekKm, 2),
            'days_worked_week' => count(array_filter(array_keys($workDates), fn($d) => strtotime($d) >= $weekStartTs))
        ]);
    }

    // Sort rankings descending by distance
    usort($monthlyOfficerRankings, fn($a, $b) => $b['month_distance_km'] <=> $a['month_distance_km']);
    usort($todayOfficerRankings, fn($a, $b) => $b['distance_km'] <=> $a['distance_km']);
    usort($yesterdayOfficerRankings, fn($a, $b) => $b['distance_km'] <=> $a['distance_km']);
    usort($weeklyOfficerRankings, fn($a, $b) => $b['week_distance_km'] <=> $a['week_distance_km']);

    // 3. Fetch field visit photos for this month
    $photos = q("SELECT p.id, p.user_id, p.file_path, u.name as officer, s.name as section, 
                        p.selected_geofence, p.auto_geofence, p.geofence_name, 
                        p.activity_type, p.note, p.taken_at, p.lat, p.lng 
                 FROM field_photos p 
                 JOIN users u ON u.id=p.user_id 
                 LEFT JOIN sections s ON s.id=u.section_id 
                 WHERE $secScopeU AND p.taken_at >= ? 
                 ORDER BY p.taken_at DESC", [$monthStart])->fetchAll();

    $distinctPlotsMonth = [];
    $formattedPhotos = [];
    foreach ($photos as $ph) {
        $sec = $ph['section'] ?: 'General';
        if (isset($sectionAggregation[$sec])) {
            $sectionAggregation[$sec]['photos_this_month']++;
        }
        $plt = trim((string)($ph['selected_geofence'] ?: ($ph['auto_geofence'] ?: ($ph['geofence_name'] ?: 'Field Plot'))));
        $pUrl = str_replace('\\', '/', photo_url($ph['file_path']));

        $formattedPhotos[] = [
            'id' => (int)$ph['id'],
            'officer' => $ph['officer'],
            'section' => $sec,
            'plot' => $plt,
            'activity_type' => $ph['activity_type'] ?: 'Field Visit',
            'note' => $ph['note'] ?: '',
            'taken_at' => $ph['taken_at'],
            'time' => date('H:i', strtotime($ph['taken_at'])),
            'date' => substr($ph['taken_at'], 0, 10),
            'photo_url' => $pUrl
        ];

        if ($plt) {
            if (!isset($distinctPlotsMonth[$plt])) {
                $distinctPlotsMonth[$plt] = [
                    'plot' => $plt,
                    'visit_count' => 0,
                    'officers' => [],
                    'activities' => [],
                    'latest_date' => $ph['taken_at'],
                    'sample_photo_url' => $pUrl
                ];
            }
            $distinctPlotsMonth[$plt]['visit_count']++;
            if (!in_array($ph['officer'], $distinctPlotsMonth[$plt]['officers'])) {
                $distinctPlotsMonth[$plt]['officers'][] = $ph['officer'];
            }
            if ($ph['activity_type'] && !in_array($ph['activity_type'], $distinctPlotsMonth[$plt]['activities'])) {
                $distinctPlotsMonth[$plt]['activities'][] = $ph['activity_type'];
            }
        }
    }

    // 4. Fetch SOS emergency alerts
    $sosAlerts = q("SELECT s.id, u.name as officer, s.lat, s.lng, s.battery, s.status, s.created_at 
                    FROM sos_alerts s 
                    JOIN users u ON u.id=s.user_id 
                    WHERE $secScopeU AND s.created_at >= ? 
                    ORDER BY s.created_at DESC LIMIT 15", [$monthStart])->fetchAll();

    // 5. Fetch tasks overview
    $recentTasks = q("SELECT t.id, u.name as officer, t.title, t.status, t.due_date, t.created_at 
                      FROM tasks t 
                      JOIN users u ON u.id=t.assigned_to 
                      WHERE $secScopeU AND t.created_at >= ? 
                      ORDER BY t.created_at DESC LIMIT 15", [$monthStart])->fetchAll();

    // Multi-Period Context Package for Gemini
    $liveContext = [
        'current_time_sl' => date('Y-m-d H:i:s'),
        'current_month' => date('F Y'),
        'user_scope' => $scopeDesc,
        'monthly_overview' => [
            'period' => date('F 01, Y') . ' to ' . date('F d, Y'),
            'total_fleet_distance_km' => round(array_sum(array_column($monthlyOfficerRankings, 'month_distance_km')), 2),
            'total_field_photos_this_month' => count($photos),
            'distinct_plots_visited_this_month' => count($distinctPlotsMonth),
            'total_active_officers_this_month' => count(array_filter($monthlyOfficerRankings, fn($m) => $m['days_worked_this_month'] > 0)),
            'total_tasks_completed_this_month' => count(array_filter($recentTasks, fn($t) => $t['status'] === 'done')),
        ],
        'today_overview' => [
            'date' => date('Y-m-d'),
            'total_fleet_distance_km' => round(array_sum(array_column($todayOfficerRankings, 'distance_km')), 2),
            'online_units_now' => count(array_filter($todayOfficerRankings, fn($x) => $x['status'] === 'Online')),
            'photos_taken_today' => count(array_filter($photos, fn($x) => substr($x['taken_at'], 0, 10) === date('Y-m-d'))),
            'active_sos_alerts' => count(array_filter($sosAlerts, fn($x) => $x['status'] === 'active')),
        ],
        'ranked_officers_this_month' => $monthlyOfficerRankings,
        'ranked_officers_today' => $todayOfficerRankings,
        'ranked_officers_yesterday' => $yesterdayOfficerRankings,
        'ranked_officers_past_7_days' => $weeklyOfficerRankings,
        'section_performance_this_month' => array_values($sectionAggregation),
        'plots_inspected_this_month' => array_values($distinctPlotsMonth),
        'today_field_photos' => array_values(array_filter($formattedPhotos, fn($p) => $p['date'] === date('Y-m-d'))),
        'latest_field_photos' => array_slice($formattedPhotos, 0, 25),
        'emergency_sos_alerts' => $sosAlerts,
        'assigned_tasks' => $recentTasks
    ];

    // Single-turn super prompt with complete Multi-Period Telematics awareness:
    $systemInstruction = "You are Track360 AI Analyst for GalOya Plantations (Hingurana, Sri Lanka).
You are an executive telematics AI assistant.
Current Local Time: " . date('Y-m-d H:i:s') . ".
Current Month: " . date('F Y') . ".
User access scope: $scopeDesc.

LIVE REAL-TIME TELEMATICS MULTI-PERIOD DATASET:
" . json_encode($liveContext, JSON_PRETTY_PRINT) . "

CRITICAL PERIOD-AWARE INSTRUCTIONS:
1. THIS MONTH ('me mase', 'this month', 'current month', 'monthly analysis', 'October mase', 'mase summary', etc.):
   - Use `monthly_overview`, `ranked_officers_this_month`, `section_performance_this_month`, and `plots_inspected_this_month`!
   - State clearly that this is the comprehensive report for THIS MONTH (" . date('F Y') . ").
   - Always display the Monthly Distance Leaderboard as a Markdown Table:
     | Rank | Officer Name | Section | Month Distance (km) | Days Worked | GPS Points | Today (km) |
   - Highlight the top performers of the month, total fleet distance for the month (" . $liveContext['monthly_overview']['total_fleet_distance_km'] . " km), and plots inspected.

2. TODAY ('ada', 'today', 'ada dawase', etc.):
   - Use `today_overview` and `ranked_officers_today`.
   - Display today's distance table:
     | Rank | Officer Name | Section | Distance (km) | Duty Hours | Battery | Status |

3. YESTERDAY ('iye', 'yesterday'):
   - Use `ranked_officers_yesterday`.

4. THIS WEEK / PAST 7 DAYS ('me sathiya', 'this week', 'last 7 days'):
   - Use `ranked_officers_past_7_days`.

5. PHOTOS & FIELD VISITS ('photos', 'photos pennanna', 'ada gaththa photos', 'plot photos', etc.):
   - When the user asks to see photos, or asks what photos were taken:
   - You MUST display the actual images using Markdown Image format:
     `![Plot Name - Officer (Time)](photo_url)`
   - Always put them inside a clean Markdown Table:
     | Photo | Plot | Officer | Activity | Time | Note |
     | ![{plot} - {officer}]({photo_url}) | **{plot}** | {officer} | {activity_type} | {time} | {note} |
   - Use the exact `photo_url` provided in `latest_field_photos` or `today_field_photos`.
   - If user asks for photos of a specific officer (e.g. Nimesh, Viraj), filter from `latest_field_photos` and show that officer's photos!

6. GENERAL OR COMPARISON QUESTIONS:
   - If user asks a general question, synthesize both today's active status and this month's overall achievements.

7. Language & Tone:
   - Reply naturally in professional Sinhala Singlish (or English if the user asked in English).
   - Be authoritative, accurate, and direct.
   - Always format structured data into clean Markdown Tables with bold highlights.";

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
        'row_count' => count($todayOfficerRankings),
        'data' => [
            'today' => $todayOfficerRankings,
            'monthly' => $monthlyOfficerRankings,
            'weekly' => $weeklyOfficerRankings
        ]
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
