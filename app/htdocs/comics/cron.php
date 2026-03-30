#!/usr/local/bin/php
<?php

chdir(__DIR__);

$defaultStrips = [
    ['slug' => 'garfield', 'label' => 'Garfield', 'type' => 'gocomics', 'enabled' => true, 'order' => 1, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'pearlsbeforeswine', 'label' => 'Pearls Before Swine', 'type' => 'gocomics', 'enabled' => true, 'order' => 2, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'calvinandhobbes', 'label' => 'Calvin and Hobbes', 'type' => 'gocomics', 'enabled' => true, 'order' => 3, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'dilbert', 'label' => 'Dilbert', 'type' => 'dilbert', 'enabled' => true, 'order' => 4, 'fetch_mode' => 'auto', 'image_url' => ''],
    ['slug' => 'farside', 'label' => 'Far Side', 'type' => 'hardcoded', 'enabled' => true, 'order' => 5, 'fetch_mode' => 'auto', 'image_url' => ''],
];

$comicsCfg = @json_decode(file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$stripsConfig = $comicsCfg['strips'] ?? $defaultStrips;
usort($stripsConfig, fn($a, $b) => (($a['order'] ?? 999) <=> ($b['order'] ?? 999)));

$existingMetadata = @json_decode(@file_get_contents(__DIR__ . '/metadata.json'), true);
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

function curlResponse(string $url, int $timeout = 20, array $extraOptions = []): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$headers) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ], $extraOptions));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'body' => $body !== false ? $body : null,
        'status' => (int)$status,
        'error' => $error ?: null,
        'headers' => $headers,
        'content_type' => $contentType ?: '',
    ];
}

function looksLikeImageBody(string $body): bool
{
    if ($body === '') {
        return false;
    }
    $info = @getimagesizefromstring($body);
    return is_array($info) && !empty($info[0]) && !empty($info[1]);
}

function convertToJpg(string $file): bool
{
    ob_start();
    try {
        $img = new Imagick($file . '[0]');
        $img->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(92);
        $img->writeImage($file);
        $img->destroy();
    } catch (Exception $e) {
        ob_end_clean();
        print "   Imagick convert error: " . $e->getMessage() . "\n";
        return false;
    }
    ob_end_clean();
    return true;
}

function downloadImageToFile(string $url, string $outFile, int $timeout = 30): array
{
    $result = curlResponse($url, $timeout);
    if (!$result['body'] || $result['status'] >= 400) {
        return [
            'ok' => false,
            'message' => $result['error'] ?: ('HTTP ' . ($result['status'] ?: 0) . ' while downloading image'),
        ];
    }

    if (!looksLikeImageBody($result['body'])) {
        return [
            'ok' => false,
            'message' => 'Response was not a valid image',
        ];
    }

    file_put_contents($outFile, $result['body']);
    if (!convertToJpg($outFile)) {
        return [
            'ok' => false,
            'message' => 'Could not convert image to grayscale JPG',
        ];
    }

    return ['ok' => true];
}


function loadGoComicsCookieOptions(): array
{
    $authFile = '/app/config/gocomics_auth.json';

    // If cookies are missing or expired, wait up to 3 minutes for the
    // cookie-refresh service to write a fresh auth.json (it runs 10 min before cron).
    $deadline = time() + 180;
    do {
        $data = @json_decode(@file_get_contents($authFile), true);
        $hasCookies = is_array($data) && !empty($data['cookies']);
        $expired    = $hasCookies && !empty($data['expires_at']) && $data['expires_at'] < time();
        if ($hasCookies && !$expired) {
            return [CURLOPT_COOKIE => $data['cookies']];
        }
        if (time() < $deadline) {
            $reason = !$hasCookies ? 'missing' : 'expired';
            print "   [cookies] auth.json {$reason}, waiting for cookie-refresh...\n";
            sleep(15);
        }
    } while (time() < $deadline);

    print "   [warn] Could not get fresh GoComics cookies — proceeding without\n";
    return [];
}

function fetchGoComics(string $slug, string $outFile): array
{
    $cookieOpts = loadGoComicsCookieOptions();
    $dates = [
        date('Y/m/d'),
        date('Y/m/d', time() - 86400),
        date('Y/m/d', time() - 86400 * 2),
    ];

    foreach ($dates as $date) {
        $result = curlResponse("https://www.gocomics.com/{$slug}/{$date}", 20, $cookieOpts);
        $html = (string)($result['body'] ?? '');
        if ($html === '') {
            continue;
        }

        $challengeHeader = !empty($result['headers']['cdn-challenge']) || !empty($result['headers']['errorcode']);
        $challengeBody = stripos($html, 'bunny_shield') !== false
            || stripos($html, 'Establishing a secure connection') !== false
            || stripos($html, 'cdn-challenge') !== false;
        if ($challengeHeader || $challengeBody) {
            return [
                'ok' => false,
                'reason' => 'Blocked by GoComics anti-bot challenge',
            ];
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
        }
    }

    return [
        'ok' => false,
        'reason' => 'GoComics page did not expose a comic image',
    ];
}

function fetchDilbert(string $outFile): array
{
    $start = mktime(0, 0, 0, 4, 16, 1989);
    curlResponse('https://dilbert-viewer.herokuapp.com/', 15);
    $end = mktime(0, 0, 0, 3, 12, 2023);
    $date = date('Y-m-d', rand($start, $end));
    $html = (string)(curlResponse("https://dilbert-viewer.herokuapp.com/{$date}")['body'] ?? '');

    if ($html && preg_match('/<img[^>]+alt="Comic for [0-9-]+"[^>]*src=([^\s>]+)/i', $html, $m)) {
        $imgUrl = $m[1];
        $download = downloadImageToFile($imgUrl, $outFile, 30);
        if ($download['ok']) {
            print "   Saved dilbert.jpg ({$date})\n";
            return ['ok' => true, 'source' => 'dilbert-viewer', 'date' => $date];
        }
        return ['ok' => false, 'reason' => $download['message']];
    }

    return ['ok' => false, 'reason' => "Could not fetch Dilbert page for {$date}"];
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
    if ($hasExistingFile) {
        $entry['status'] = 'blocked';
        if (empty($entry['stale_since'])) {
            $entry['stale_since'] = gmdate('Y-m-d\TH:i:s\Z');
        }
    } else {
        $entry['status'] = 'missing';
        if (empty($entry['stale_since'])) {
            $entry['stale_since'] = gmdate('Y-m-d\TH:i:s\Z');
        }
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

// GoComics / Dilbert / manual-url strips
$normalizedStrips = [];
foreach ($stripsConfig as $index => $strip) {
    $normalized = normalizeStrip($strip, $index);
    if ($normalized['slug'] === '' || $normalized['type'] === '') {
        continue;
    }
    $normalizedStrips[] = $normalized;
}

$farsideEnabled = count(array_filter($normalizedStrips, fn($strip) => $strip['enabled'] && ($strip['slug'] ?? '') === 'farside')) > 0;
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
if ($farsideEnabled) {
    print "-> Fetching Far Side panels\n";

    foreach (glob('farside_*.jpg') as $old) {
        unlink($old);
    }

    $farsideData = [];
    $html = (string)(curlResponse('https://www.thefarside.com/')['body'] ?? '');

    if ($html !== '') {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $cards = $xpath->query("//div[contains(@class,'tfs-comic')]");
        $i = 1;
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

            $width = (int)$img->getAttribute('data-width');
            $height = (int)$img->getAttribute('data-height');
            $captionNodes = $xpath->query(".//figcaption", $card);
            $caption = '';
            if ($captionNodes->length > 0) {
                $caption = trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent));
            }

            $download = downloadImageToFile($url, "farside_{$i}.jpg", 30);
            if (!$download['ok']) {
                continue;
            }

            if ($width === 0 || $height === 0) {
                $info = @getimagesize("farside_{$i}.jpg");
                if ($info) {
                    $width = $info[0];
                    $height = $info[1];
                }
            }

            $farsideData[] = ['file' => "farside_{$i}.jpg", 'width' => $width, 'height' => $height, 'caption' => $caption];
            print "   Panel {$i}: {$width}x{$height} — {$caption}\n";
            $i++;
            if (count($farsideData) >= 4) {
                break;
            }
        }
    }
} else {
    $farsideData = [];
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

file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
print "-> Wrote metadata.json\n";
