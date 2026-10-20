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
$model = trim((string)setting('gemini_model', 'gemini-3.5-flash'));
if (!$model || $model === 'gemini-3.8-flash') $model = 'gemini-3.5-flash';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'chat');

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

    $candidates = array_unique([$testModel, 'gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.1-flash-lite']);
    $payload = [
        'contents' => [
            ['parts' => [['text' => 'Ping. Return only "OK"']]]
        ]
    ];

    $res = gemini_generate_content($testKey, $candidates, $payload, 15);
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
    $sectionScopeSql = user_section_scope_sql('u');
    $accessibleSections = user_accessible_section_ids();
    $scopeDesc = $accessibleSections === null 
        ? "Super Admin / Global Access (All divisions and sections)" 
        : "Restricted to Section IDs: (" . implode(', ', $accessibleSections) . ")";

    $modelsToTry = array_unique([$model, 'gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.1-flash-lite']);

    // System prompt explaining database schema and strict read-only query generation
    $systemInstruction = "You are Track360 AI Analyst, an enterprise telematics & agronomy AI for GalOya Plantations (Sri Lanka).
The current local date & time in Sri Lanka is " . date('Y-m-d H:i:s') . " (Asia/Colombo, UTC+05:30).
User Scope: $scopeDesc.

DATABASE SCHEMA:
1. `users` (Field officers, vehicles, staff):
   - id, username, name (Full officer name), phone, role ('staff','admin'), unit_type ('Field Officer','Tractor','Harvester','Bowser','Bike','Cab'), department_id, section_id (1=Section 1, 2=Section 2), on_duty (1/0), battery (0-100), speed (km/h), last_seen (DATETIME), lat, lng, active (1/0).

2. `locations` (Historical GPS trail):
   - id, user_id (FK users.id), lat, lng, speed, battery, recorded_at (DATETIME).
   - Distance traveled today: SUM distance between consecutive locations or count of active movements.

3. `field_photos` (Photos taken in field by officers):
   - id, user_id, file_path, lat, lng, note, geofence_name (plot identifier e.g. 'H/182/06'), activity_type, taken_at (DATETIME).

4. `geofences` (Agricultural plots & zones):
   - id, name (plot name), group_id, active.

5. `geofence_groups` (Divisions / Sectors):
   - id, name (e.g. 'Hingurana Sector 1').

6. `tasks` (Field tasks):
   - id, user_id, title, description, due_date, status ('pending','in_progress','done','cancelled'), created_at.

7. `sos_alerts` (Emergency panics):
   - id, user_id, lat, lng, battery, status ('active','resolved'), created_at.

8. `sections` (id, department_id, name) and `departments` (id, name).

RULES:
- ONLY output a single valid MySQL SELECT query to fetch the necessary data to answer the user question.
- Do NOT generate ANY query other than SELECT. NO INSERT, UPDATE, DELETE, DROP, ALTER.
- Always include `LIMIT 50`.
- Output format: Return JSON only with no markdown backticks:
{\"sql\": \"SELECT ...\", \"need_query\": true, \"direct_answer\": \"\"}";

    $payload1 = [
        'system_instruction' => [
            'parts' => [['text' => $systemInstruction]]
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => "Question: " . $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.1
        ]
    ];

    $step1Res = gemini_generate_content($apiKey, $modelsToTry, $payload1, 25);
    if (!$step1Res['success']) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gemini API Error: ' . $step1Res['error']
        ]);
        exit;
    }

    $rawReply = $step1Res['text'];
    $step1 = [];
    if (preg_match('/\{[\s\S]*\}/', $rawReply, $matches)) {
        $step1 = json_decode($matches[0], true) ?: [];
    } else {
        $step1 = ['direct_answer' => $rawReply];
    }

    $sql = trim((string)($step1['sql'] ?? ''));
    $queryResult = [];
    $rowCount = 0;

    // Step 2: Validate and execute SQL if present
    if (!empty($sql)) {
        $cleanSql = trim($sql);
        if (!preg_match('/^SELECT\b/i', $cleanSql)) {
            echo json_encode(['status' => 'error', 'message' => 'Security violation: Only SELECT queries are permitted.']);
            exit;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|EXEC|CREATE|GRANT|REVOKE)\b/i', $cleanSql)) {
            echo json_encode(['status' => 'error', 'message' => 'Security violation: Dangerous SQL statement detected and blocked.']);
            exit;
        }

        if (!preg_match('/\bLIMIT\b/i', $cleanSql)) {
            $cleanSql .= " LIMIT 50";
        }

        try {
            $stmt = q($cleanSql);
            $queryResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rowCount = count($queryResult);
        } catch (Throwable $e) {
            $queryResult = ["error" => $e->getMessage()];
        }
    }

    // Step 3: Format the final executive answer
    $formatPrompt = "You are Track360 AI Analyst for GalOya Plantations.
User asked: \"$prompt\"
" . (!empty($sql) ? "Executed SQL:\n$cleanSql\n\nData Rows ($rowCount results):\n" . json_encode($queryResult, JSON_PRETTY_PRINT) : "") . "

INSTRUCTIONS:
1. Provide a direct, professional executive summary in friendly Singlish or English (matching user language).
2. If asked in Sinhala or Singlish, answer naturally in clear Singlish.
3. Highlight key metrics and top performers using bold text.
4. Present detailed tabular records in a clean Markdown Table (`| Header 1 | Header 2 | ... |`).";

    $payload2 = [
        'system_instruction' => [
            'parts' => [['text' => "You are an executive telematics AI analyst for agricultural plantation managers."]]
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $formatPrompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ];

    $step2Res = gemini_generate_content($apiKey, $modelsToTry, $payload2, 25);
    $finalAnswer = $step2Res['success'] 
        ? $step2Res['text'] 
        : ($step1['direct_answer'] ?? 'Data retrieved successfully but formatting timed out.');

    echo json_encode([
        'status' => 'success',
        'answer' => $finalAnswer,
        'sql' => $cleanSql ?? null,
        'row_count' => $rowCount,
        'data' => $queryResult
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
