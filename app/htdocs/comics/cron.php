#!/usr/local/bin/php
<?php

chdir(__DIR__);
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/image.php';

const COMICS_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
// BunnyCDN wants these on every GoComics request.
const COMICS_ACCEPT_HEADERS = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.5',
];
const FARSIDE_MAX_PANELS = 4;

$defaultStrips = [
    ['slug' => 'garfield', 'label' => 'Garfield', 'type' => 'gocomics', 'enabled' => true, 'order' => 1, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'pearlsbeforeswine', 'label' => 'Pearls Before Swine', 'type' => 'gocomics', 'enabled' => true, 'order' => 2, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'calvinandhobbes', 'label' => 'Calvin and Hobbes', 'type' => 'gocomics', 'enabled' => true, 'order' => 3, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'dilbert', 'label' => 'Dilbert', 'type' => 'dilbert', 'enabled' => true, 'order' => 4, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'farside', 'label' => 'Far Side', 'type' => 'hardcoded', 'enabled' => true, 'order' => 5, 'fetch_mode' => 'auto', 'image_url' => ''],
];

$comicsCfg = @json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$stripsConfig = $comicsCfg['strips'] ?? $defaultStrips;
usort($stripsConfig, fn($a, $b) => (($a['order'] ?? 999) <=> ($b['order'] ?? 999)));

$existingMetadata = @json_decode((string)@file_get_contents(__DIR__ . '/metadata.json'), true);
if (!is_array($existingMetadata)) {
    $existingMetadata = [];
}
$existingSources = is_array($existingMetadata['sources'] ?? null) ? $existingMetadata['sources'] : [];

function normalizeStrip(array $strip, int $index): array
{
    return [
        'slug' => trim((string)($strip['slug'] ?? '')),
        'label' => trim((string)($strip['label'] ?? '')) ?: ('Strip ' . ($index + 1)),
        'type' => trim((string)($strip['type'] ?? 'gocomics')),
        'enabled' => !array_key_exists('enabled', $strip) || (bool)$strip['enabled'],
        'order' => max(1, (int)($strip['order'] ?? ($index + 1))),
        'fetch_mode' => trim((string)($strip['fetch_mode'] ?? 'auto')) ?: 'auto',
        'image_url' => trim((string)($strip['image_url'] ?? '')),
    ];
}

/** GET with the Chrome UA + Accept headers GoComics/BunnyCDN expect. */
function comicsHttpGet(string $url, int $timeout = 20, array $curlOpts = []): array
{
    return visionect_http_get($url, $timeout, COMICS_ACCEPT_HEADERS, array_replace([CURLOPT_USERAGENT => COMICS_USER_AGENT], $curlOpts));
}

/**
 * Download an image and save it as a greyscale JPG at $outFile.
 * The download stays in memory and visionect_image_to_frame() writes a temp file and renames it,
 * so the existing (last good) $outFile is never touched unless the new image converted cleanly.
 */
function downloadImageToFile(string $url, string $outFile, int $timeout = 30, array $curlOpts = []): array
{
    $result = comicsHttpGet($url, $timeout, $curlOpts);
    if (!$result['ok']) {
        return [
            'ok' => false,
            'message' => ($result['error'] ?: 'HTTP ' . $result['status']) . ' while downloading image',
        ];
    }

    if (!visionect_is_image_blob($result['body'])) {
        return [
            'ok' => false,
            'message' => 'Response was not a valid image',
        ];
    }

    if (!visionect_image_to_frame($result['body'], $outFile, ['mode' => 'fit_width'])) {
        return [
            'ok' => false,
            'message' => 'Could not convert image to greyscale JPG: ' . (visionect_image_last_error() ?? 'unknown error'),
        ];
    }

    return ['ok' => true];
}

/**
 * Expiry (unix time) of the GoComics bunny_shield* cookie, or 0 if unknown.
 * The cookie is now named e.g. "bunny_shield_id_33498", so match the name by prefix. Older
 * "bunny_shield" cookies carried the expiry in the value (key#sig#<unix>).
 */
function goComicsCookieExpiry(array $auth): int
{
    $expiresAt = (int)($auth['expires_at'] ?? 0);
    if ($expiresAt > 0) {
        return $expiresAt;
    }
    if (preg_match_all('/(?:^|;\s*)bunny_shield[^=;]*=([^;]*)/', (string)($auth['cookies'] ?? ''), $matches)) {
        foreach ($matches[1] as $value) {
            $parts = explode('#', trim($value));
            if (isset($parts[2]) && ctype_digit(trim($parts[2]))) {
                return (int)trim($parts[2]);
            }
        }
    }
    return 0;
}

function loadGoComicsCookieOptions(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $authFile = VISIONECT_CONFIG_DIR . '/gocomics_auth.json';

    // If cookies are missing or known to be expired, wait up to 3 minutes for the
    // cookie-refresh service (05:55, 10 min before the 06:05 cron) to write a fresh file.
    $deadline = time() + 180;
    while (true) {
        $data = @json_decode((string)@file_get_contents($authFile), true);
        $hasCookies = is_array($data) && !empty($data['cookies']);
        $expiresAt = $hasCookies ? goComicsCookieExpiry($data) : 0;
        $expired = $expiresAt > 0 && $expiresAt < time();
        if ($hasCookies && !$expired) {
            $refreshedAt = strtotime((string)($data['refreshed_at'] ?? '')) ?: 0;
            if ($expiresAt === 0 && $refreshedAt > 0 && time() - $refreshedAt > 36 * 3600) {
                print "   [cookies] warning: cookies are " . round((time() - $refreshedAt) / 3600) . "h old (expiry unknown); is cookie-refresh running?\n";
            }
            return $cached = [CURLOPT_COOKIE => (string)$data['cookies']];
        }
        if (time() >= $deadline) {
            break;
        }
        $reason = !$hasCookies ? 'missing' : 'expired';
        print "   [cookies] auth.json {$reason}, waiting for cookie-refresh...\n";
        sleep(15);
    }

    print "   [warn] Could not get fresh GoComics cookies — proceeding without\n";
    return $cached = [];
}

function isCdnChallenge(array $result): bool
{
    $html = (string)($result['body'] ?? '');
    return !empty($result['headers']['cdn-challenge'])
        || !empty($result['headers']['errorcode'])
        || stripos($html, 'bunny_shield') !== false
        || stripos($html, 'Establishing a secure connection') !== false
        || stripos($html, 'cdn-challenge') !== false;
}

function fetchGoComics(string $slug, string $outFile): array
{
    $cookieOpts = loadGoComicsCookieOptions();
    $dates = [
        date('Y/m/d'),
        date('Y/m/d', time() - 86400),
        date('Y/m/d', time() - 86400 * 2),
    ];

    $lastError = null;
    foreach ($dates as $date) {
        $result = comicsHttpGet("https://www.gocomics.com/{$slug}/{$date}", 20, $cookieOpts);
        $html = (string)($result['body'] ?? '');
        if ($html === '') {
            continue;
        }

        if (isCdnChallenge($result)) {
            return [
                'ok' => false,
                'reason' => 'Blocked by GoComics anti-bot challenge',
            ];
        }
        if (!$result['ok']) {
            continue;
        }

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//meta[@property="og:image"]') as $node) {
            $url = $node->getAttribute('content');
            if (empty($url)) {
                continue;
            }
            $download = downloadImageToFile($url, $outFile, 30);
            if ($download['ok']) {
                print "   Saved {$outFile} from {$date}\n";
                return [
                    'ok' => true,
                    'source' => 'gocomics',
                    'date' => $date,
                ];
            }
            $lastError = $download['message'];
        }
    }

    return [
        'ok' => false,
        'reason' => $lastError ?? 'GoComics page did not expose a comic image',
    ];
}

function fetchDilbert(string $outFile): array
{
    $start = mktime(0, 0, 0, 4, 16, 1989);
    comicsHttpGet('https://dilbert-viewer.herokuapp.com/', 15);
    $end = mktime(0, 0, 0, 3, 12, 2023);
    $date = date('Y-m-d', rand($start, $end));
    $page = comicsHttpGet("https://dilbert-viewer.herokuapp.com/{$date}");
    $html = $page['ok'] ? (string)$page['body'] : '';

    if ($html && preg_match('/<img[^>]+alt="Comic for [0-9-]+"[^>]*src=([^\s>]+)/i', $html, $m)) {
        $imgUrl = trim($m[1], '"\'');
        $download = downloadImageToFile($imgUrl, $outFile, 30);
        if ($download['ok']) {
            print "   Saved dilbert.jpg ({$date})\n";
            return ['ok' => true, 'source' => 'dilbert-viewer', 'date' => $date];
        }
        return ['ok' => false, 'reason' => $download['message']];
    }

    return ['ok' => false, 'reason' => "Could not fetch Dilbert page for {$date}" . ($page['ok'] ? '' : ' (' . $page['error'] . ')')];
}

function ensureSourceEntry(array $existing, array $strip): array
{
    $slug = $strip['slug'];
    $entry = is_array($existing[$slug] ?? null) ? $existing[$slug] : [];
    $entry['slug'] = $slug;
    $entry['label'] = $strip['label'];
    $entry['type'] = $strip['type'];
    $entry['fetch_mode'] = $strip['fetch_mode'];
    $entry['image_url'] = $strip['image_url'];
    $entry['file'] = $slug . '.jpg';
    return $entry;
}

function markSourceSuccess(array $entry, string $message): array
{
    $entry['status'] = $entry['fetch_mode'] === 'auto' ? 'ok' : 'manual';
    $entry['message'] = $message;
    $entry['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['last_success_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['stale_since'] = null;
    return $entry;
}

function markSourceFailure(array $entry, string $message, bool $hasExistingFile): array
{
    $entry['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['message'] = $message;
    $entry['status'] = $hasExistingFile ? 'blocked' : 'missing';
    if (empty($entry['stale_since'])) {
        $entry['stale_since'] = gmdate('Y-m-d\TH:i:s\Z');
    }
    return $entry;
}

function stripInfo(string $path): ?array
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }
    return ['width' => $info[0], 'height' => $info[1]];
}

/**
 * Fetch up to FARSIDE_MAX_PANELS panels into temp files. Only if at least one panel
 * succeeds are the old farside_*.jpg files replaced; otherwise the old panels stay.
 * Returns ['ok' => bool, 'panels' => [...], 'message' => string].
 */
function fetchFarSide(): array
{
    $page = comicsHttpGet('https://www.thefarside.com/');
    if (!$page['ok']) {
        return ['ok' => false, 'panels' => [], 'message' => 'Could not load thefarside.com (' . $page['error'] . ')'];
    }

    $doc = new DOMDocument();
    @$doc->loadHTML((string)$page['body']);
    $xpath = new DOMXPath($doc);
    $cards = $xpath->query("//div[contains(@class,'tfs-comic')]");

    $tmpPrefix = '.farside_new_' . getmypid() . '_';
    $fetched = [];
    $lastError = 'No Far Side panels found on the page';
    foreach ($cards as $card) {
        $imgNodes = $xpath->query(".//div[contains(@class,'tfs-comic__image')]/img", $card);
        if ($imgNodes->length === 0) {
            continue;
        }

        $img = $imgNodes->item(0);
        $url = $img->getAttribute('data-src');
        if (empty($url)) {
            continue;
        }

        $captionNodes = $xpath->query(".//figcaption", $card);
        $caption = '';
        if ($captionNodes->length > 0) {
            $caption = trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent));
        }

        $n = count($fetched) + 1;
        $tmpFile = $tmpPrefix . $n . '.jpg';
        $download = downloadImageToFile($url, $tmpFile, 30);
        if (!$download['ok']) {
            $lastError = $download['message'];
            @unlink($tmpFile);
            continue;
        }

        $info = stripInfo($tmpFile);
        $fetched[] = [
            'tmp' => $tmpFile,
            'file' => "farside_{$n}.jpg",
            'width' => $info['width'] ?? (int)$img->getAttribute('data-width'),
            'height' => $info['height'] ?? (int)$img->getAttribute('data-height'),
            'caption' => $caption,
        ];
        print "   Panel {$n}: " . end($fetched)['width'] . 'x' . end($fetched)['height'] . " — {$caption}\n";
        if (count($fetched) >= FARSIDE_MAX_PANELS) {
            break;
        }
    }

    if (empty($fetched)) {
        return ['ok' => false, 'panels' => [], 'message' => $lastError];
    }

    // Swap: rename each new panel over its final name, then drop any old extra panels.
    $panels = [];
    foreach ($fetched as $panel) {
        if (!@rename($panel['tmp'], $panel['file'])) {
            @unlink($panel['tmp']);
            continue;
        }
        unset($panel['tmp']);
        $panels[] = $panel;
    }
    $keep = array_column($panels, 'file');
    foreach (glob('farside_*.jpg') ?: [] as $old) {
        if (!in_array($old, $keep, true)) {
            @unlink($old);
        }
    }
    foreach (glob('.farside_new_*') ?: [] as $stale) {
        @unlink($stale);
    }

    if (empty($panels)) {
        return ['ok' => false, 'panels' => [], 'message' => 'Could not move new Far Side panels into place'];
    }
    return ['ok' => true, 'panels' => $panels, 'message' => 'Fetched ' . count($panels) . ' panel(s) from thefarside.com'];
}

// GoComics / Dilbert / manual-url strips
$normalizedStrips = [];
foreach ($stripsConfig as $index => $strip) {
    $normalized = normalizeStrip($strip, $index);
    if ($normalized['slug'] === '' || $normalized['type'] === '') {
        continue;
    }
    $normalizedStrips[] = $normalized;
}

$farsideStrip = null;
foreach ($normalizedStrips as $strip) {
    if ($strip['slug'] === 'farside') {
        $farsideStrip = $strip;
        break;
    }
}
$farsideEnabled = $farsideStrip !== null && $farsideStrip['enabled'];
$sourceMeta = [];

foreach ($normalizedStrips as $strip) {
    if (($strip['type'] ?? '') === 'hardcoded') {
        continue;
    }

    $slug = $strip['slug'];
    $file = $slug . '.jpg';
    $entry = ensureSourceEntry($existingSources, $strip);
    $fileExists = file_exists($file);

    if (!$strip['enabled']) {
        if (!$fileExists && empty($entry['status'])) {
            $entry['status'] = 'missing';
            $entry['message'] = 'Strip is disabled';
        }
        $sourceMeta[$slug] = $entry;
        continue;
    }

    print "-> Fetching {$strip['label']}\n";

    if (($strip['fetch_mode'] ?? 'auto') === 'upload') {
        if ($fileExists) {
            $entry = markSourceSuccess($entry, 'Using manually uploaded strip');
        } else {
            $entry = markSourceFailure($entry, 'Waiting for a manual strip upload', false);
        }
        $sourceMeta[$slug] = $entry;
        continue;
    }

    if (($strip['fetch_mode'] ?? 'auto') === 'url') {
        if ($strip['image_url'] === '') {
            $entry = markSourceFailure($entry, 'Add an image URL to use URL import mode', $fileExists);
            $sourceMeta[$slug] = $entry;
            continue;
        }

        $download = downloadImageToFile($strip['image_url'], $file, 30);
        if ($download['ok']) {
            print "   Saved {$file} from custom image URL\n";
            $entry = markSourceSuccess($entry, 'Imported from custom image URL');
        } else {
            print "   FAILED: {$download['message']}\n";
            $entry = markSourceFailure($entry, $download['message'], $fileExists);
        }
        $sourceMeta[$slug] = $entry;
        continue;
    }

    if (($strip['type'] ?? '') === 'gocomics') {
        $fetch = fetchGoComics($slug, $file);
        if ($fetch['ok']) {
            $entry = markSourceSuccess($entry, 'Fetched automatically from GoComics');
        } else {
            print "   FAILED: {$fetch['reason']}\n";
            $entry = markSourceFailure($entry, $fetch['reason'], $fileExists);
        }
        $sourceMeta[$slug] = $entry;
        continue;
    }

    if (($strip['type'] ?? '') === 'dilbert') {
        $fetch = fetchDilbert($file);
        if ($fetch['ok']) {
            $entry = markSourceSuccess($entry, 'Fetched automatically from Dilbert archive');
        } else {
            print "   FAILED: {$fetch['reason']}\n";
            $entry = markSourceFailure($entry, $fetch['reason'], $fileExists);
        }
        $sourceMeta[$slug] = $entry;
        continue;
    }

    $sourceMeta[$slug] = $entry;
}

// Far Side panels
$farsideData = [];
if ($farsideStrip !== null) {
    $entry = ensureSourceEntry($existingSources, $farsideStrip);
    $entry['file'] = 'farside_1.jpg';
    $previousPanels = array_values(array_filter(
        is_array($existingMetadata['farside'] ?? null) ? $existingMetadata['farside'] : [],
        fn($panel) => is_array($panel) && !empty($panel['file']) && is_file(basename((string)$panel['file']))
    ));

    if ($farsideEnabled) {
        print "-> Fetching Far Side panels\n";
        $fetch = fetchFarSide();
        if ($fetch['ok']) {
            $farsideData = $fetch['panels'];
            $entry = markSourceSuccess($entry, $fetch['message']);
        } else {
            print "   FAILED: {$fetch['message']}" . (!empty($previousPanels) ? ' — keeping previous panels' : '') . "\n";
            $farsideData = $previousPanels;
            $entry = markSourceFailure($entry, $fetch['message'], !empty($previousPanels));
        }
        $entry['panels'] = count($farsideData);
    } elseif (empty($entry['status'])) {
        $entry['status'] = 'missing';
        $entry['message'] = 'Strip is disabled';
    }
    $sourceMeta['farside'] = $entry;
}

// Metadata
$stripsData = [];
foreach ($normalizedStrips as $strip) {
    if (($strip['type'] ?? '') === 'hardcoded') {
        continue;
    }
    $file = $strip['slug'] . '.jpg';
    if (!file_exists($file)) {
        continue;
    }
    $info = stripInfo($file);
    if (!$info) {
        continue;
    }
    $stripsData[] = [
        'slug' => $strip['slug'],
        'label' => $strip['label'],
        'file' => $file,
        'width' => $info['width'],
        'height' => $info['height'],
        'type' => $strip['type'],
    ];
}

$metadata = [
    'farside' => $farsideData,
    'strips' => $stripsData,
    'sources' => $sourceMeta,
    'updated' => date('Y-m-d'),
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
];

if (visionect_write_json_atomic(__DIR__ . '/metadata.json', $metadata)) {
    print "-> Wrote metadata.json\n";
} else {
    print "-> ERROR: could not write metadata.json\n";
    exit(1);
}
