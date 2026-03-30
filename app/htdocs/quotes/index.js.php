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

	<?php
		$posters = glob('*.{png,jpg}',GLOB_BRACE);
		print 'var posters = ' . json_encode($posters) . ";\n";
	?>
	var index = +localStorage.getItem('poster-index') || 0;
	var poster = posters[Object.keys(posters)[index]];
	localStorage.setItem('poster-index', index < Object.keys(posters).length-1 ? index + 1 : 0);
	</script>
</head>
<body>
	<script>
		if (/\.black/.test(poster)) {
			document.body.style.backgroundColor = '#000';
		}
		document.write('<img src="' + poster + '?' + Math.random() + '">');
	</script>
</body>
</html>
