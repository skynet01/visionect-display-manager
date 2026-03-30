#!/usr/local/bin/php
<?php

//date_default_timezone_set('Europe/Amsterdam');
chdir(__DIR__);

$config = json_decode(file_get_contents('config.json'), true);
if (!$config) {
	die("!! Can't open/read config.json, maybe wrong syntax?\n");
}

// Remove old files:
$files = glob('*.{jpg,pdf}',GLOB_BRACE);
foreach($files as $file) {
	$mtime = @filemtime($file);
	if (false === $mtime) continue;
	if (time() - $mtime > 5 * 86400) {
		unlink($file);
	}
}

// fetch a paper and cache in as a JPG. Return the path to the JPG if we found it.
// We can pass in an offset in days to get yesterday or two days ago
function fetchPaper($prefix, $offset=0) {
	$urlToPdf = "https://cdn.freedomforum.org/dfp/pdf" . date('j',strtotime("-" . $offset . " days")) . "/" . $prefix . ".pdf";
	$pdfFile = $prefix . "_" . date('Ymd',strtotime("-" . $offset . " days")) . ".pdf";
	$pngFile = $prefix . "_" . date('Ymd',strtotime("-" . $offset . " days")) . ".jpg";
	// check if a jpg is already downloaded
	// if not we start checking for the PDF and converting
	if (file_exists($pngFile)) {
		return $pngFile;
	}
	if (!file_exists($pngFile)) {
		print "-> Fetching {$urlToPdf}\n";

		$pdf = @file_get_contents($urlToPdf);
		if (false !== $pdf) {
			file_put_contents($pdfFile, $pdf);
			$cmd = 'convert -density 300 -background white -alpha remove ' . escapeshellarg($pdfFile) . ' -colorspace Gray -resize 1600 ' . escapeshellarg($pngFile);
			print "-> {$cmd}\n";
			@shell_exec($cmd);
			return $pngFile;
		}
	}
	return false;
}

foreach($config as $paper) {
	if (array_key_exists('enabled', $paper) && !$paper['enabled']) {
		continue;
	}
	$result = fetchPaper($paper['prefix'],0);  // Fetch today
	if (!$result) {
		$result = fetchPaper($paper['prefix'],1);  // Fetch yesterday
		if (!$result) {
			$result = fetchPaper($paper['prefix'],2);  // Fetch twesterday
		}
	}
	if ($result) {
		@unlink($paper['prefix'] . '_latest.jpg');
		shell_exec('ln -s ' . escapeshellarg($result) . ' ' . escapeshellarg($paper['prefix'] . '_latest.jpg'));
	}
}
