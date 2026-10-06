<?php
$libDir = file_exists('/app/lib/security.php') ? '/app/lib' : __DIR__ . '/../lib';
require_once $libDir . '/security.php';
require_once $libDir . '/runtime.php';
visionect_send_no_cache_headers();

header('Content-Type: application/json');

if (!visionect_is_private_network_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Local network access only'], JSON_UNESCAPED_SLASHES);
    exit;
}

$body = null;
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $body = $decoded;
        }
    }
}

$input = array_merge($_GET, $_POST, is_array($body) ? $body : []);

// Optional shared token (admin > General > control token). Empty: open to the LAN, as before.
$providedToken = $_SERVER['HTTP_X_CONTROL_TOKEN'] ?? ($input['token'] ?? '');
$providedToken = is_string($providedToken) ? $providedToken : '';
if (!visionect_control_token_ok($providedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing or invalid control token'], JSON_UNESCAPED_SLASHES);
    exit;
}

$task = trim((string)($input['task'] ?? ''));
$page = trim((string)($input['page'] ?? ''));

$allowedTasks = ['setPage', 'reloadCurrent', 'resumeSchedule', 'pause', 'unpause', 'reloadPrefs', 'refreshActivity'];
if ($task === '' || !in_array($task, $allowedTasks, true)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid task',
        'allowed' => $allowedTasks,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($task === 'setPage' && $page === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing page for setPage'], JSON_UNESCAPED_SLASHES);
    exit;
}

$queued = visionect_queue_remote_control([
    'task' => $task,
    'page' => $page !== '' ? $page : null,
    'source' => 'local-http',
    'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
]);

if (empty($queued['queued'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not queue the command'], JSON_UNESCAPED_SLASHES);
    exit;
}

unset($queued['queued'], $queued['queue_file']);
echo json_encode([
    'ok' => true,
    'queued' => $queued,
], JSON_UNESCAPED_SLASHES);
