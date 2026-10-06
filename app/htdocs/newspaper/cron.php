#!/usr/local/bin/php
<?php

chdir(__DIR__);
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../lib/http.php';

const NEWSPAPER_KEEP_DAYS = 5;
const NEWSPAPER_WIDTH = 1440;

$config = json_decode((string)@file_get_contents('config.json'), true);
if (!$config) {
	die("!! Can't open/read config.json, maybe wrong syntax?\n");
}

/** True if $file is a readable JPEG/PNG with real dimensions. */
function newspaperValidImage(string $file): bool
{
	if (!is_file($file)) {
		return false;
	}
	$info = @getimagesize($file);
	return is_array($info) && $info[0] > 0 && $info[1] > 0;
}

/** Newest valid PREFIX_YYYYMMDD.jpg on disk, or null. */
function newspaperNewestFile(string $prefix): ?string
{
	$files = glob($prefix . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].jpg') ?: [];
	rsort($files, SORT_STRING);
	foreach ($files as $file) {
		if (newspaperValidImage($file)) {
			return $file;
		}
	}
	return null;
}

/** Point PREFIX_latest.jpg at $target atomically (new symlink, then rename over the old one). */
function newspaperRelink(string $prefix, string $target): bool
{
	$link = $prefix . '_latest.jpg';
	if (is_link($link) && readlink($link) === $target) {
		return true;
	}
	$tmp = '.' . $link . '.' . getmypid() . '.tmp';
	@unlink($tmp);
	if (!@symlink($target, $tmp) || !@rename($tmp, $link)) {
		@unlink($tmp);
		print "!! Could not relink {$link} -> {$target}\n";
		return false;
	}
	print "-> {$link} -> {$target}\n";
	return true;
}

// Fetch a paper and cache it as a greyscale JPG. Returns the JPG name, or false.
// $offset is in days (1 = yesterday).
function fetchPaper(string $prefix, int $offset = 0)
{
	$day = strtotime("-{$offset} days");
	$urlToPdf = 'https://cdn.freedomforum.org/dfp/pdf' . date('j', $day) . '/' . rawurlencode($prefix) . '.pdf';
	$stem = $prefix . '_' . date('Ymd', $day);
	$jpgFile = $stem . '.jpg';

	if (newspaperValidImage($jpgFile)) {
		return $jpgFile;
	}

	print "-> Fetching {$urlToPdf}\n";
	$res = visionect_http_get($urlToPdf, 60);
	if (!$res['ok']) {
		print "   not available ({$res['error']})\n";
		return false;
	}
	if (strncmp($res['body'], '%PDF', 4) !== 0) {
		print "   response is not a PDF (" . ($res['content_type'] ?: 'unknown type') . ") — skipped\n";
		return false;
	}

	$token = getmypid() . '_' . bin2hex(random_bytes(3));
	$tmpPdf = ".{$stem}.{$token}.pdf";
	$tmpJpg = ".{$stem}.{$token}.jpg";
	if (file_put_contents($tmpPdf, $res['body']) !== strlen($res['body'])) {
		@unlink($tmpPdf);
		print "   could not write PDF\n";
		return false;
	}

	$cmd = 'timeout 180 convert -density 150 ' . escapeshellarg($tmpPdf . '[0]')
		. ' -background white -alpha remove -colorspace Gray -resize ' . NEWSPAPER_WIDTH
		. ' -quality 80 ' . escapeshellarg('jpg:' . $tmpJpg) . ' 2>&1';
	print "-> convert {$stem}.pdf (density 150, " . NEWSPAPER_WIDTH . "px, q80)\n";
	exec($cmd, $output, $code);
	@unlink($tmpPdf);

	if ($code !== 0 || !newspaperValidImage($tmpJpg)) {
		@unlink($tmpJpg);
		print "   conversion failed (exit {$code}): " . substr(implode(' ', $output), 0, 300) . "\n";
		return false;
	}
	if (!@rename($tmpJpg, $jpgFile)) {
		@unlink($tmpJpg);
		print "   could not move {$jpgFile} into place\n";
		return false;
	}
	@chmod($jpgFile, 0644);
	return $jpgFile;
}

foreach ($config as $paper) {
	if (array_key_exists('enabled', $paper) && !$paper['enabled']) {
		continue;
	}
	$prefix = (string)($paper['prefix'] ?? '');
	if (!preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
		print "!! Skipping paper with invalid prefix\n";
		continue;
	}

	$result = false;
	for ($offset = 0; $offset <= 2 && !$result; $offset++) {
		$result = fetchPaper($prefix, $offset); // today, yesterday, the day before
	}
	if ($result) {
		newspaperRelink($prefix, $result);
	} else {
		print "!! No new {$prefix} front page — keeping the current one\n";
	}
}

// Repair every PREFIX_latest.jpg (including disabled papers): a link whose target is gone is
// pointed at the newest existing file for that prefix, or removed if there is none.
foreach (glob('*_latest.jpg') ?: [] as $link) {
	if (!is_link($link) || newspaperValidImage($link)) {
		continue;
	}
	$prefix = substr($link, 0, -strlen('_latest.jpg'));
	$newest = newspaperNewestFile($prefix);
	if ($newest !== null) {
		newspaperRelink($prefix, $newest);
	} else {
		@unlink($link);
		print "-> Removed broken {$link} (no {$prefix} front page on disk)\n";
	}
}

// Remove old files, but never a current _latest target (or the links themselves).
$protected = [];
foreach (glob('*_latest.jpg') ?: [] as $link) {
	if (is_link($link)) {
		$protected[basename((string)readlink($link))] = true;
	}
}
foreach (glob('*.{jpg,pdf}', GLOB_BRACE) ?: [] as $file) {
	if (is_link($file) || isset($protected[$file])) {
		continue;
	}
	$mtime = @filemtime($file);
	if ($mtime !== false && time() - $mtime > NEWSPAPER_KEEP_DAYS * 86400) {
		@unlink($file);
		print "-> Pruned {$file}\n";
	}
}
// PDFs are only needed during conversion; drop any left over from older versions of this script.
foreach (glob('*.pdf') ?: [] as $pdf) {
	$jpg = substr($pdf, 0, -4) . '.jpg';
	if (newspaperValidImage($jpg)) {
		@unlink($pdf);
	}
}
// Stale temp files from an interrupted run.
foreach (glob('.*.tmp') ?: [] as $tmp) {
	if (time() - (int)@filemtime($tmp) > 3600) {
		@unlink($tmp);
	}
}
foreach (glob('.*_[0-9]*.{pdf,jpg}', GLOB_BRACE) ?: [] as $tmp) {
	if (time() - (int)@filemtime($tmp) > 3600) {
		@unlink($tmp);
	}
}
