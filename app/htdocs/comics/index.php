<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('comics');
visionect_record_frame_response('comics', '/comics/', 'page');

// Frame and page geometry. Keep these in sync with the CSS below.
const FRAME_WIDTH       = 1440;
const FRAME_HEIGHT      = 2560;
const BODY_PADDING      = 16;                               // body padding, each side
const CONTENT_WIDTH     = FRAME_WIDTH - 2 * BODY_PADDING;   // 1408
const CONTENT_HEIGHT    = FRAME_HEIGHT - 2 * BODY_PADDING;  // 2528
const FS_ROW_GAP        = 8;    // .farside-row gap between panels
const FS_PANEL_PAD_X    = 5;    // .farside-panel horizontal padding, each side
const FS_PANEL_PAD_Y    = 15;   // .farside-panel vertical padding, each side (30px total)
const FS_CAPTION_MARGIN = 15;   // .farside-caption margin-top
const FS_CAPTION_FONT   = 16.8; // 1.05em of 16px
const FS_CAPTION_LINE   = 1.3;  // .farside-caption line-height
// Average serif glyph width as a fraction of the font size. The frame renders Georgia as
// DejaVu Serif, which is wide (~0.55em); erring wide over-estimates lines, which is safe.
const FS_CAPTION_CHAR_EM = 0.55;
const LAYOUT_SAFETY     = 16;   // spare pixels so rounding never pushes the page past 2560

// Read metadata written by cron.php
$metaFile = __DIR__ . '/metadata.json';
$meta = null;
if (file_exists($metaFile)) {
	$decoded = json_decode((string)file_get_contents($metaFile), true);
	if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
		$meta = $decoded;
	}
}

// Gap values can be tuned from config.json.
$_comicsCfg = @json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$GAP_STRIP = (int)($_comicsCfg['gap_strip'] ?? 32);
$GAP_MIN   = (int)($_comicsCfg['gap_min'] ?? 6);
$GAP_MAX   = (int)($_comicsCfg['gap_max'] ?? 48);

$configRows = is_array($_comicsCfg['strips'] ?? null) ? $_comicsCfg['strips'] : [];
usort($configRows, fn($a, $b) => (($a['order'] ?? 999) <=> ($b['order'] ?? 999)));
$configRows = array_values(array_filter($configRows, fn($row) => is_array($row) && ($row['enabled'] ?? true)));

/** Image size of a local file in this folder, or null if missing/unreadable. */
function comics_image_size(string $file): ?array
{
	$path = __DIR__ . '/' . basename($file);
	$info = is_file($path) ? @getimagesize($path) : false;
	return $info ? ['width' => (int)$info[0], 'height' => (int)$info[1]] : null;
}

/** Estimated rendered height of a Far Side caption (including its top margin). */
function comics_caption_height(string $caption, float $width): int
{
	$caption = trim($caption);
	if ($caption === '') {
		return 0;
	}
	$charsPerLine = max(1, (int)floor($width / (FS_CAPTION_FONT * FS_CAPTION_CHAR_EM)));
	$chars = function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption);
	// Word wrapping wastes part of each line; 10% slack covers it for normal prose.
	$lines = (int)ceil(($chars * 1.1) / $charsPerLine);
	return FS_CAPTION_MARGIN + (int)ceil($lines * FS_CAPTION_FONT * FS_CAPTION_LINE);
}

if ($meta && (!empty($meta['farside']) || !empty($meta['strips']))) {
	$farside   = is_array($meta['farside'] ?? null) ? $meta['farside'] : [];
	$allStrips = is_array($meta['strips'] ?? null) ? $meta['strips'] : [];
} else {
	// metadata.json missing or empty: fall back to whatever strip images exist on disk.
	$farside = [];
	foreach (glob(__DIR__ . '/farside_*.jpg') ?: [] as $path) {
		$size = comics_image_size(basename($path));
		if ($size) {
			$farside[] = array_merge(['file' => basename($path), 'caption' => ''], $size);
		}
	}
	$allStrips = [];
	foreach ($configRows as $row) {
		$slug = (string)($row['slug'] ?? '');
		$size = $slug !== '' && $slug !== 'farside' ? comics_image_size($slug . '.jpg') : null;
		if ($size) {
			$allStrips[] = array_merge(['slug' => $slug, 'file' => $slug . '.jpg'], $size);
		}
	}
}

// Drop entries whose image is gone or has no usable size (avoids broken images and division by zero).
$farside = array_values(array_filter($farside, fn($p) => is_array($p) && !empty($p['file']) && (int)($p['width'] ?? 0) > 0 && (int)($p['height'] ?? 0) > 0 && is_file(__DIR__ . '/' . basename((string)$p['file']))));
$allStrips = array_values(array_filter($allStrips, fn($s) => is_array($s) && !empty($s['file']) && (int)($s['width'] ?? 0) > 0 && (int)($s['height'] ?? 0) > 0 && is_file(__DIR__ . '/' . basename((string)$s['file']))));

// --- Far Side row height (all panels in one row, equal width) ---
$fsCount     = count($farside);
$fsRowHeight = 0;
if ($fsCount > 0) {
	$fsPanelWidth = (CONTENT_WIDTH - FS_ROW_GAP * ($fsCount - 1)) / $fsCount;
	$fsImageWidth = $fsPanelWidth - 2 * FS_PANEL_PAD_X;
	foreach ($farside as $panel) {
		$h = 2 * FS_PANEL_PAD_Y
			+ (int)ceil($panel['height'] * ($fsImageWidth / $panel['width']))
			+ comics_caption_height((string)($panel['caption'] ?? ''), $fsImageWidth);
		$fsRowHeight = max($fsRowHeight, $h);
	}
}

// --- Scale strips to the full content width ---
$stripMap = [];
foreach ($allStrips as $strip) {
	$h = (int)ceil($strip['height'] * (CONTENT_WIDTH / $strip['width']));
	$stripMap[pathinfo($strip['file'], PATHINFO_FILENAME)] = array_merge($strip, ['scaled_height' => $h]);
}

$orderedRows = [];
foreach ($configRows as $rowCfg) {
	$slug = (string)($rowCfg['slug'] ?? '');
	if ($slug === 'farside') {
		if ($fsCount > 0) {
			$orderedRows[] = ['type' => 'farside', 'height' => $fsRowHeight, 'panels' => $farside];
		}
		continue;
	}
	if (isset($stripMap[$slug])) {
		$orderedRows[] = ['type' => 'strip', 'height' => $stripMap[$slug]['scaled_height'], 'strip' => $stripMap[$slug]];
	}
}

// --- Greedy fill: add rows until the frame is full, drop the last if it won't fit ---
// Before dropping, try squeezing gaps down to GAP_MIN to fit one more row.
// CSS gap only goes BETWEEN rows, so n rows have n-1 gaps.
$available = CONTENT_HEIGHT - LAYOUT_SAFETY;
$selected  = $orderedRows;
$gap       = $GAP_STRIP;
while (!empty($selected)) {
	$gapCount = count($selected) - 1;
	$contentH = array_sum(array_column($selected, 'height'));

	if ($contentH + $gapCount * $GAP_STRIP <= $available) {
		// Fits at the standard gap: spread leftover space, up to GAP_MAX
		$gap = $gapCount > 0
			? min($GAP_MAX, max($GAP_STRIP, (int)floor(($available - $contentH) / $gapCount)))
			: $GAP_STRIP;
		break;
	}
	if ($gapCount > 0 && $contentH + $gapCount * $GAP_MIN <= $available) {
		// Fits by squeezing gaps
		$gap = max($GAP_MIN, (int)floor(($available - $contentH) / $gapCount));
		break;
	}
	array_pop($selected);
}
$rows = $selected;
$estimatedHeight = empty($rows) ? 0 : array_sum(array_column($rows, 'height')) + (count($rows) - 1) * $gap + 2 * BODY_PADDING;
?>
<!DOCTYPE html>
<html>
<head>
	<title>Comics</title>
	<!-- layout estimate: <?= (int)$estimatedHeight ?>px of <?= FRAME_HEIGHT ?>px, gap <?= (int)$gap ?>px -->
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }

		body {
			padding: <?= BODY_PADDING ?>px;
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
			gap: <?= (int)$gap ?>px;
		}

		/* --- Far Side row: all panels side by side --- */
		.farside-row {
			display: flex;
			gap: <?= FS_ROW_GAP ?>px;
			align-items: flex-start;
		}
		.farside-panel {
			padding: <?= FS_PANEL_PAD_Y ?>px <?= FS_PANEL_PAD_X ?>px;
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
			margin-top: <?= FS_CAPTION_MARGIN ?>px;
			line-height: <?= FS_CAPTION_LINE ?>;
			color: #111;
		}

		/* --- Full-width comic strips --- */
		.strip-row img {
			width: 100%;
			height: auto;
			display: block;
		}

		.empty {
			font-family: Georgia, serif;
			font-size: 48px;
			color: #444;
			text-align: center;
		}
	</style>
</head>
<body>

<?php if (empty($rows)): ?>
	<div class="empty">Today's comics are not available yet.</div>
<?php else: ?>
	<div class="layout">

		<?php foreach ($rows as $row): ?>
			<?php if ($row['type'] === 'farside'): ?>
			<div class="farside-row">
				<?php foreach ($row['panels'] as $panel): ?>
				<div class="farside-panel">
					<img src="<?= htmlspecialchars(basename((string)$panel['file'])) ?>" alt="">
					<?php if (!empty($panel['caption'])): ?>
					<div class="farside-caption"><?= htmlspecialchars((string)$panel['caption']) ?></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<div class="strip-row">
				<img src="<?= htmlspecialchars(basename((string)$row['strip']['file'])) ?>" alt="">
			</div>
			<?php endif; ?>
		<?php endforeach; ?>

	</div>
<?php endif; ?>

</body>
</html>
