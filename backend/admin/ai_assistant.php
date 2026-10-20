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
$model = trim((string)setting('gemini_model', 'gemini-flash-latest'));
if (!$model || $model === 'gemini-3.8-flash') $model = 'gemini-flash-latest';

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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
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

// 1. Action: Test Key Connectivity
if ($action === 'test_key') {
    $testKey = trim((string)($_POST['api_key'] ?? $apiKey));
    $testModel = trim((string)($_POST['model'] ?? $model));
    if (!$testKey) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter or save a Gemini API Key to test.']);
        exit;
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($testModel) . ":generateContent?key=" . urlencode($testKey);
    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => 'Ping. Return only "OK"']]]
        ]
    ]);

    $resp = call_gemini_api($url, $payload, 15);
    $http = $resp['http'];
    $res = $resp['res'];

    if ($http === 200) {
        echo json_encode(['status' => 'ok', 'message' => 'Gemini AI connection successful with ' . $testModel . '!']);
    } else {
        $errMsg = '';
        if ($res) {
            $err = json_decode($res, true);
            $errMsg = $err['error']['message'] ?? '';
        }
        if (!$errMsg) {
            $errMsg = $resp['err'] ? "cURL Error ({$resp['errno']}): {$resp['err']}" : "HTTP $http error connecting to Google Gemini.";
        }
        echo json_encode(['status' => 'error', 'message' => $errMsg]);
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

    // System prompt explaining database schema and strict read-only query generation
    $systemInstruction = "You are Track360 AI Analyst, an intelligent enterprise telematics & agronomy AI for GalOya Plantations (Sri Lanka).
The current local date & time in Sri Lanka is " . date('Y-m-d H:i:s') . " (Asia/Colombo, UTC+05:30).
The user is asking questions about field officers, vehicles, plot visits, distance traveled, GPS tracking, duty hours, battery, and SOS emergencies.

DATABASE SCHEMA:
1. `users` (Field officers, vehicles, staff):
   - id (INT, PK)
   - username (VARCHAR)
   - name (VARCHAR) - Full officer name
   - phone (VARCHAR)
   - role (VARCHAR) - 'staff', 'admin'
   - unit_type (VARCHAR) - 'Field Officer', 'Tractor', 'Harvester', 'Bowser', 'Bike', 'Cab'
   - department_id (INT)
   - section_id (INT) - 1 for Section 1, 2 for Section 2, etc.
   - on_duty (TINYINT) - 1 if currently working/on duty, 0 if off
   - battery (INT) - 0 to 100%
   - speed (FLOAT) - current speed km/h
   - last_seen (DATETIME) - timestamp of last telemetry ping
   - lat, lng (DECIMAL) - current coordinates
   - active (TINYINT) - 1 = active

2. `locations` (Historical GPS trail):
   - id (BIGINT, PK)
   - user_id (INT, FK to users.id)
   - lat, lng (DECIMAL)
   - speed (FLOAT) - speed km/h
   - battery (INT)
   - recorded_at (DATETIME) - time recorded in SL time

3. `field_photos` (Photos taken in field by officers):
   - id (INT, PK)
   - user_id (INT, FK to users.id)
   - file_path (VARCHAR)
   - lat, lng (DECIMAL)
   - note (TEXT) - description entered by officer
   - geofence_name (VARCHAR) - plot identifier (e.g. 'H/182/06', 'G/51/18')
   - activity_type (VARCHAR) - 'Field Visit', 'General', 'Fertilizer', etc.
   - taken_at (DATETIME)

4. `geofences` (Agricultural plots & zones):
   - id (INT, PK)
   - name (VARCHAR) - plot name
   - group_id (INT, FK to geofence_groups.id)
   - active (TINYINT)

5. `geofence_groups` (Divisions / Sectors):
   - id (INT, PK)
   - name (VARCHAR) - group/division name (e.g. 'Hingurana Sector 1')

6. `tasks` (Field tasks assigned to staff):
   - id (INT, PK)
   - user_id (INT, FK to users.id)
   - title (VARCHAR)
   - description (TEXT)
   - due_date (DATE)
   - status (ENUM: 'pending', 'in_progress', 'done', 'cancelled')
   - created_at (DATETIME)

7. `sos_alerts` (Emergency panics):
   - id (INT, PK)
   - user_id (INT, FK to users.id)
   - lat, lng (DECIMAL)
   - battery (INT)
   - status (ENUM: 'active', 'resolved')
   - created_at (DATETIME)

8. `sections` (id, department_id, name) and `departments` (id, name).

SECURITY & ACCESS RULES:
- The logged in user scope is: $scopeDesc.
- If user scope is restricted, only query data where users.section_id IN (" . ($accessibleSections ? implode(',', $accessibleSections) : "0") . ").
- ONLY generate a single valid, optimized MySQL SELECT query to fetch the necessary rows to answer the question.
- Do NOT generate ANY query other than SELECT. NO INSERT, UPDATE, DELETE, DROP, ALTER.
- If the question can be answered without a query (e.g. greetings, capabilities), do not output SQL.
- Always add reasonable LIMIT (max 50 rows) to keep queries lightning fast.

RETURN FORMAT:
You MUST respond with a valid JSON object only with NO markdown fences:
{
  \"sql\": \"SELECT ...\",
  \"need_query\": true,
  \"direct_answer\": \"\"
}";

    // Step 1: Call Gemini to produce SQL or direct answer
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($apiKey);
    $payload = json_encode([
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $systemInstruction . "\n\nUser Question:\n" . $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'responseMimeType' => 'application/json'
        ]
    ]);

    $resp1 = call_gemini_api($url, $payload, 25);
    $http = $resp1['http'];
    $res = $resp1['res'];

    if ($http !== 200) {
        $err = json_decode($res, true);
        $errMsg = $err['error']['message'] ?? ($resp1['err'] ?: "HTTP $http error connecting to Google Gemini.");
        echo json_encode([
            'status' => 'error',
            'message' => 'Gemini API Error: ' . $errMsg
        ]);
        exit;
    }

    $geminiData = json_decode($res, true);
    $rawReply = $geminiData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $step1 = json_decode($rawReply, true);

    $sql = trim((string)($step1['sql'] ?? ''));
    $queryResult = [];
    $rowCount = 0;

    // Step 2: If SQL was generated, validate and execute it securely
    if (!empty($sql)) {
        // Enforce strict SELECT-only guardrail
        $cleanSql = trim($sql);
        if (!preg_match('/^SELECT\b/i', $cleanSql)) {
            echo json_encode(['status' => 'error', 'message' => 'Security violation: Only SELECT queries are permitted.']);
            exit;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|EXEC|CREATE|GRANT|REVOKE)\b/i', $cleanSql)) {
            echo json_encode(['status' => 'error', 'message' => 'Security violation: Dangerous SQL statement detected and blocked.']);
            exit;
        }

        // Limit execution time & enforce limit
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

    // Step 3: Call Gemini again to format the final executive answer
    $formatPrompt = "You are Track360 AI Analyst for GalOya Plantations.
User asked: \"$prompt\"
" . (!empty($sql) ? "Executed SQL Query:\n$cleanSql\n\nQuery Results (Total $rowCount rows):\n" . json_encode($queryResult, JSON_PRETTY_PRINT) : "") . "

INSTRUCTIONS FOR FINAL ANSWER:
1. Provide a direct, professional, and clear executive summary in friendly Singlish or English (matching the user's language).
2. If the user asked in Sinhala or Singlish, reply naturally in clear Sinhala Singlish (e.g., 'Ada dawase Section 1 eke officers la 5 denek duty hitiya...').
3. If data is present:
   - Highlight key metrics and findings using bullet points or bold text.
   - Present the detailed records in a clean Markdown Table (`| Header 1 | Header 2 | ... |`).
   - If no records match, explain clearly why.
4. Keep the tone helpful, accurate, and ready for decision-makers.";

    $payload2 = json_encode([
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $formatPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ]);

    $resp2 = call_gemini_api($url, $payload2, 30);
    $res2 = $resp2['res'];

    $geminiData2 = json_decode($res2, true);
    $finalAnswer = $geminiData2['candidates'][0]['content']['parts'][0]['text'] ?? ($step1['direct_answer'] ?? 'No response generated.');

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
