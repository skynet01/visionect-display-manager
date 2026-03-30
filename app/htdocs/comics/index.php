<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('comics');
visionect_record_frame_response('comics', '/comics/', 'page');

// Read metadata written by cron.php
$metaFile = __DIR__ . '/metadata.json';
$meta = null;
if (file_exists($metaFile)) {
	$decoded = json_decode(file_get_contents($metaFile), true);
	if (json_last_error() === JSON_ERROR_NONE) {
		$meta = $decoded;
	}
}

// Frame dimensions (set to match actual display, minus 16px body padding each side)
const FRAME_WIDTH  = 1440;
const FRAME_HEIGHT = 2560;

// Gap values can now be tuned from config.json.
$_comicsCfg = @json_decode(file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$GAP_STRIP = (int)($_comicsCfg['gap_strip'] ?? 32);
$GAP_MIN   = (int)($_comicsCfg['gap_min'] ?? 6);
$GAP_MAX   = (int)($_comicsCfg['gap_max'] ?? 48);

$farside     = [];
$rows        = [];
$gap         = $GAP_STRIP;
$useFallback = false;

if ($meta && (!empty($meta['farside']) || !empty($meta['strips']))) {
	$farside   = $meta['farside'];
	$allStrips = $meta['strips'] ?? [];
	$configRows = $_comicsCfg['strips'] ?? [];
	usort($configRows, fn($a, $b) => (($a['order'] ?? 999) <=> ($b['order'] ?? 999)));
	$configRows = array_values(array_filter($configRows, fn($row) => $row['enabled'] ?? true));

	// --- Far Side row height (all panels in one row, equal width) ---
	$fsCount      = count($farside);
	$fsRowHeight  = 0;
	if ($fsCount > 0) {
		$fsPanelWidth = (int)(FRAME_WIDTH / $fsCount);
		foreach ($farside as $panel) {
			$h = (int)ceil($panel['height'] * ($fsPanelWidth / $panel['width']));
			$fsRowHeight = max($fsRowHeight, $h);
		}
	}

	// --- Scale strips to full frame width ---
	$scaledStrips = [];
	foreach ($allStrips as $strip) {
		$h = (int)ceil($strip['height'] * (FRAME_WIDTH / $strip['width']));
		$scaledStrips[] = array_merge($strip, ['scaled_height' => $h]);
	}
	$stripMap = [];
	foreach ($scaledStrips as $strip) {
		$stripMap[pathinfo($strip['file'], PATHINFO_FILENAME)] = $strip;
	}

	$orderedRows = [];
	foreach ($configRows as $rowCfg) {
		if (($rowCfg['slug'] ?? '') === 'farside' && !empty($farside)) {
			$orderedRows[] = ['type' => 'farside', 'height' => $fsRowHeight, 'panels' => $farside];
			continue;
		}
		$slug = $rowCfg['slug'] ?? '';
		if (isset($stripMap[$slug])) {
			$orderedRows[] = ['type' => 'strip', 'height' => $stripMap[$slug]['scaled_height'], 'strip' => $stripMap[$slug]];
		}
	}

	// --- Greedy fill: add strips until frame is full, drop last if it won't fit ---
	// Before dropping, try squeezing gaps down to GAP_MIN to fit one more strip.
	$selected = $orderedRows;
	while (true) {
		$gapCount = count($selected);
		$contentH = array_sum(array_column($selected, 'height'));

		if ($contentH + $gapCount * $GAP_STRIP <= FRAME_HEIGHT) {
			// Fits at standard gap — distribute leftover space up to GAP_MAX
			$gap = $gapCount > 0
				? min($GAP_MAX, max($GAP_STRIP, (int)(floor((FRAME_HEIGHT - $contentH) / $gapCount))))
				: $GAP_STRIP;
			break;
		}
		if ($gapCount > 0 && $contentH + $gapCount * $GAP_MIN <= FRAME_HEIGHT) {
			// Fits by squeezing gaps
			$gap = max($GAP_MIN, (int)floor((FRAME_HEIGHT - $contentH) / $gapCount));
			break;
		}
		// Doesn't fit even at minimum gap — drop the last strip and retry
		if (empty($selected)) {
			$gap = $GAP_STRIP;
			break;
		}
		array_pop($selected);
	}
	$rows = $selected;

} else {
	$useFallback = true;
}
?>
<!DOCTYPE html>
<html>
<head>
	<title>Comics</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Cabin:wght@600&display=swap" rel="stylesheet">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }

		body {
			padding: 16px;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			justify-content: center;
			background: #fff;
		}

		/* --- Main column, full width --- */
		.layout {
			display: flex;
			flex-direction: column;
			gap: <?= $gap ?>px;
		}

		/* --- Far Side row: all panels side by side --- */
		.farside-row {
			display: flex;
			gap: 8px;
			align-items: flex-start;
		}
		.farside-panel {
			padding: 15px 5px;
			flex: 1;
			min-width: 0;
		}
		.farside-panel img {
			width: 100%;
			height: auto;
			display: block;
		}
		.farside-caption {
			font-family: Georgia, serif;
			font-size: 1.05em;
			text-align: center;
			margin-top: 15px;
			line-height: 1.3;
			color: #111;
		}

		/* --- Full-width comic strips --- */
		.strip-row img {
			width: 100%;
			height: auto;
			display: block;
		}
	</style>
</head>
<body>

<?php if ($useFallback): ?>
	<!-- metadata.json missing — fallback to static layout -->
	<div style="text-align:center;margin-bottom:16px"><img src="farside.jpg" style="width:95%"></div>
	<div style="text-align:center;margin-bottom:16px"><img src="garfield.gif" style="width:95%"></div>
	<div style="text-align:center;margin-bottom:16px"><img src="pearlsbeforeswine.gif" style="width:95%"></div>
	<div style="text-align:center;margin-bottom:16px"><img src="calvinandhobbes.gif" style="width:95%"></div>
	<div style="text-align:center;margin-bottom:16px"><img src="babyblues.gif" style="width:95%"></div>

<?php else: ?>
	<div class="layout">

		<?php foreach ($rows as $row): ?>
			<?php if ($row['type'] === 'farside'): ?>
			<div class="farside-row">
				<?php foreach ($row['panels'] as $panel): ?>
				<div class="farside-panel">
					<img src="<?= htmlspecialchars($panel['file']) ?>" alt="">
					<?php if (!empty($panel['caption'])): ?>
					<div class="farside-caption"><?= htmlspecialchars($panel['caption']) ?></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<div class="strip-row">
				<img src="<?= htmlspecialchars($row['strip']['file']) ?>" alt="">
			</div>
			<?php endif; ?>
		<?php endforeach; ?>

	</div>
<?php endif; ?>

</body>
</html>
