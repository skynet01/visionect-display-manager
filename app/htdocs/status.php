<?php
$libDir = file_exists('/app/lib/security.php') ? '/app/lib' : __DIR__ . '/../lib';
require_once $libDir . '/security.php';
require_once $libDir . '/runtime.php';
visionect_send_no_cache_headers();

// Public: the frame shell's WebSocket token. role=display can watch but not control.
if (isset($_GET['ws_token'])) {
	header('Content-Type: application/json');
	echo json_encode(array(
		'token' => visionect_issue_websocket_token('display', 86400, 'display'),
	), JSON_UNESCAPED_SLASHES);
	return;
}

// Plain-text activity: sleep, away or home (same logic as visionectd).
echo visionect_compute_activity();
