<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('clock');

$allStyles = ['digital', 'analog', 'words', 'clocks', 'flip'];
$cfg = @json_decode(file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$enabled = $cfg['enabled_styles'] ?? $allStyles;
$enabled = array_values(array_intersect($allStyles, $enabled));

if (empty($enabled)) {
	$enabled = $allStyles;
}

$requestedStyle = trim((string)($_GET['style'] ?? ''));
$style = in_array($requestedStyle, $enabled, true)
	? $requestedStyle
	: $enabled[array_rand($enabled)];
visionect_record_frame_response('clock', '/clock/?style=' . rawurlencode($style), 'page', [
	'style' => $style,
]);
include __DIR__ . "/clock.{$style}.html";
