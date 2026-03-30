<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('quotes');

	$posters = glob('*.{png,jpg}',GLOB_BRACE);
	$requestedPoster = basename((string)($_GET['file'] ?? ''));
	if ($requestedPoster !== '' && in_array($requestedPoster, array_map('basename', $posters), true)) {
		$poster = $requestedPoster;
	} else {
		shuffle($posters);
		$poster = $posters[0];
	}

	function urlencodeurl($url) {
		$parts = array();
		foreach(explode('/', $url) as $part) {
			$parts[] = urlencode($part);
		}
		return implode('/', $parts);
	}

	visionect_record_frame_response('quotes', '/quotes/?file=' . rawurlencode((string)$poster), 'page', array(
		'asset_file' => (string)$poster,
	));

?>
<!DOCTYPE html>
<html>
<head>
	<title>Art</title>
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
			background: <?= preg_match('/\.black/', basename($poster)) ? '#000' : '#fff' ?>;
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
	<img src="<?= urlencodeurl($poster) ?>">
</body>
</html>
