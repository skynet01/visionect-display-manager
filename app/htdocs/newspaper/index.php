<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('newspaper');

$config = json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
	die("!! Can't open/read config.json\n");
}

$papers = array_filter($config, function ($paper) {
	return is_array($paper) && (!array_key_exists('enabled', $paper) || $paper['enabled']);
});
$papers = array_values($papers);

/** True if the paper's _latest.jpg link resolves to an existing file. */
function newspaper_has_image(array $paper): bool
{
	$prefix = (string)($paper['prefix'] ?? '');
	return $prefix !== '' && is_file(__DIR__ . '/' . basename($prefix . '_latest.jpg'));
}

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
	$count = count($papers);
	// Rotate, skipping papers whose image is missing. Start at next_index; when every image
	// is missing, $chosen stays at next_index and the page shows a message instead.
	$pick = function (array $status) use ($papers, $count): int {
		$start = max(0, (int)($status['modules']['newspaper']['next_index'] ?? 0)) % $count;
		for ($k = 0; $k < $count; $k++) {
			$idx = ($start + $k) % $count;
			if (newspaper_has_image($papers[$idx])) {
				return $idx;
			}
		}
		return $start;
	};

	if (isset($_GET['preview'])) {
		$chosen = $pick(visionect_read_runtime_status());
	} else {
		// Locked read-modify-write so concurrent requests/writers can't lose the rotation index.
		$chosen = 0;
		visionect_mutate_runtime_status(function (array $status) use ($pick, $count, &$chosen) {
			$chosen = $pick($status);
			$status['modules']['newspaper']['next_index'] = ($chosen + 1) % $count;
			return $status;
		});
	}
	$paper = $papers[$chosen];
}

$paperPrefix = trim((string)($paper['prefix'] ?? ''));
$paperName = trim((string)($paper['name'] ?? $paperPrefix));
$imageFile = $paperPrefix !== '' ? basename($paperPrefix . '_latest.jpg') : '';
$hasImage = $paper !== null && newspaper_has_image($paper);
$imageStyle = trim((string)($paper['style'] ?? 'width:100%;height:auto;'));

if ($paperPrefix !== '') {
	visionect_record_frame_response('newspaper', '/newspaper/?prefix=' . rawurlencode($paperPrefix), 'page', [
		'paper_prefix' => $paperPrefix,
		'paper_name' => $paperName,
		'asset_file' => $hasImage ? $imageFile : null,
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
	<?php if ($paper && $hasImage): ?>
		<img src="<?= htmlspecialchars($imageFile) ?><?= isset($_GET['preview']) ? '?v=' . rawurlencode((string)($_GET['preview'] ?? '')) : '' ?>" style="<?= htmlspecialchars($imageStyle) ?>">
	<?php elseif ($paper): ?>
		<div class="empty">Today's <?= htmlspecialchars($paperName) ?> front page is not available</div>
	<?php else: ?>
		<div class="empty">No enabled newspapers</div>
	<?php endif; ?>
</body>
</html>
