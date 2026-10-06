<?php
/**
 * Shared full-frame image gallery page for the art, quotes and haynesmann modules.
 * Each module's index.php just includes this file and calls visionect_render_gallery_page().
 */

require_once __DIR__ . '/security.php';

/** Gallery image file names (basename) in $dir, sorted. */
function visionect_gallery_images(string $dir): array
{
	$files = array_map('basename', glob(rtrim($dir, '/') . '/*.{png,jpg}', GLOB_BRACE) ?: []);
	sort($files, SORT_STRING);
	return $files;
}

/** Encode each path segment of a relative URL. */
function visionect_gallery_urlencode_path(string $path): string
{
	return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Render one random image (or ?file=<name> if it exists) full-frame, and record it in the
 * runtime status as the module's current frame asset.
 * Images whose name contains ".black" get a black background instead of white.
 */
function visionect_render_gallery_page(string $module, string $title, ?string $dir = null): void
{
	$dir = $dir ?? dirname($_SERVER['SCRIPT_FILENAME'] ?? (__DIR__ . '/../htdocs/' . $module . '/index.php'));

	visionect_send_no_cache_headers();
	visionect_track_frame_request($module);

	$posters = visionect_gallery_images($dir);
	$requested = basename((string)($_GET['file'] ?? ''));
	if ($requested !== '' && in_array($requested, $posters, true)) {
		$poster = $requested;
	} elseif (!empty($posters)) {
		$poster = $posters[array_rand($posters)];
	} else {
		$poster = '';
	}

	if ($poster !== '') {
		visionect_record_frame_response($module, '/' . $module . '/?file=' . rawurlencode($poster), 'page', [
			'asset_file' => $poster,
		]);
	}

	$background = preg_match('/\.black/', $poster) ? '#000' : '#fff';
	?>
<!DOCTYPE html>
<html>
<head>
	<title><?= htmlspecialchars($title) ?></title>
	<style>
		body {
			margin: 0;
			display:flex;
			position:fixed;
			left:0;
			top:0;
			width:100vw;
			height:100vh;
			justify-content:center;
			align-items:center;
			background: <?= $background ?>;
			font-family: sans-serif;
		}
		img {
			object-fit: contain;
			width:auto;
			height:auto;
			max-width:100%;
			max-height:100%;
		}

		@media (orientation: landscape) { img { height:100%; } }
		@media (orientation: portrait) { img { width:100%; } }
	</style>
</head>
<body>
<?php if ($poster !== ''): ?>
	<img src="<?= htmlspecialchars(visionect_gallery_urlencode_path($poster)) ?>">
<?php else: ?>
	<div style="font-size:48px;color:#666">No images in this gallery</div>
<?php endif; ?>
</body>
</html>
<?php
}

/**
 * Legacy client-side variant (index.js.php): cycles through the images in order using
 * localStorage. Not used by the frame rotation; kept for manual use.
 */
function visionect_render_gallery_js_page(string $title, ?string $dir = null): void
{
	$dir = $dir ?? dirname($_SERVER['SCRIPT_FILENAME'] ?? '.');
	$posters = visionect_gallery_images($dir);
	?>
<!DOCTYPE html>
<html>
<head>
	<title><?= htmlspecialchars($title) ?></title>
	<style>
		body {
			margin: 0;
			display:flex;
			position:fixed;
			left:0;
			top:0;
			width:100vw;
			height:100vh;
			justify-content:center;
			align-items:center;
		}
		img {
			object-fit: contain;
			width:auto;
			height:auto;
			max-width:100%;
			max-height:100%;
		}

		@media (orientation: landscape) { img { height:100%; } }
		@media (orientation: portrait) { img { width:100%; } }
	</style>
	<script>
	var posters = <?= json_encode(array_values($posters), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
	var index = +localStorage.getItem('poster-index') || 0;
	if (index >= posters.length) index = 0;
	var poster = posters[index];
	localStorage.setItem('poster-index', index < posters.length - 1 ? index + 1 : 0);
	</script>
</head>
<body>
	<script>
		if (/\.black/.test(poster)) {
			document.body.style.backgroundColor = '#000';
		}
		if (poster) {
			var img = document.createElement('img');
			img.src = encodeURIComponent(poster) + '?' + Math.random();
			document.body.appendChild(img);
		}
	</script>
</body>
</html>
<?php
}
