<?php
// this one atempts to fix some sizing
$posters = glob( '*.{png,jpg}', GLOB_BRACE );
shuffle( $posters );
$poster = $posters[0];

function urlencodeurl( $url ) {
	$parts = array();
	foreach ( explode( '/', $url ) as $part ) {
		$parts[] = urlencode( $part );
	}

	return implode( '/', $parts );
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>Hannes Beer</title>
	<style>
        body {
            margin: 0;
            display: flex;
            position: fixed;
            left: 0;
            top: 0;
            width: 100vw;
            height: 100vh;
            justify-content: center;
            align-items: center;
            background: #ffffff;
        }

        img {
            object-fit: contain;
            height: 2560px ;
            max-height: 100%;
        }

        @media (orientation: landscape) {
            img {
                width: auto;
            }
        }

        @media (orientation: portrait) {
            img {
                width: auto;
            }
        }
	</style>
</head>
<body>
<img src="<?= urlencodeurl( $poster ) ?>">
</body>
</html>
