<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('newspaper');

$config = json_decode(file_get_contents('config.json'), true);
if (!$config) {
	die("!! Can't open/read config.json\n");
}

$papers = array_filter($config, function ($paper) {
	return !array_key_exists('enabled', $paper) || $paper['enabled'];
});
$papers = array_values($papers);
$requestedPrefix = trim((string)($_GET['prefix'] ?? ''));
$paper = null;

if ($requestedPrefix !== '') {
	foreach ($papers as $candidate) {
		if (($candidate['prefix'] ?? '') === $requestedPrefix) {
			$paper = $candidate;
			break;
		}
	}
}

if ($paper === null && !empty($papers)) {
	$status = visionect_read_runtime_status();
	$nextIndex = max(0, (int)($status['modules']['newspaper']['next_index'] ?? 0));
	$paper = $papers[$nextIndex % count($papers)];
	if (!isset($_GET['preview'])) {
		visionect_update_runtime_status([
			'modules' => [
				'newspaper' => [
					'next_index' => ($nextIndex + 1) % count($papers),
				],
			],
		]);
	}
}

$imageFile = $paper ? (($paper['prefix'] ?? '') . '_latest.jpg') : '';
$imageStyle = trim((string)($paper['style'] ?? 'width:100%;height:auto;'));
$paperPrefix = trim((string)($paper['prefix'] ?? ''));
$paperName = trim((string)($paper['name'] ?? $paperPrefix));

if ($paperPrefix !== '') {
	visionect_record_frame_response('newspaper', '/newspaper/?prefix=' . rawurlencode($paperPrefix), 'page', [
		'paper_prefix' => $paperPrefix,
		'paper_name' => $paperName,
		'asset_file' => $imageFile,
	]);
}

?>
<!DOCTYPE html>
<html>
<head>
<style>
	body {
		margin: 0;
		text-align: center;
		overflow: hidden;
		font-family: sans-serif;
	}
	.empty {
		height: 100vh;
		display: flex;
		align-items: center;
		justify-content: center;
		color: #666;
		font-size: 48px;
	}
</style>
</head>
<body>
	<?php if ($paper): ?>
		<img src="<?= htmlspecialchars($imageFile) ?><?= isset($_GET['preview']) ? '?v=' . rawurlencode((string)($_GET['preview'] ?? '')) : '' ?>" style="<?= htmlspecialchars($imageStyle) ?>">
	<?php else: ?>
		<div class="empty">No enabled newspapers</div>
	<?php endif; ?>
</body>
</html>
