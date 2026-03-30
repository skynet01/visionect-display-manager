#!/usr/local/bin/php
<?php

chdir(__DIR__);

$defaultStrips = [
    ['slug' => 'garfield', 'label' => 'Garfield', 'type' => 'gocomics', 'enabled' => true, 'order' => 1],
    ['slug' => 'pearlsbeforeswine', 'label' => 'Pearls Before Swine', 'type' => 'gocomics', 'enabled' => true, 'order' => 2],
    ['slug' => 'calvinandhobbes', 'label' => 'Calvin and Hobbes', 'type' => 'gocomics', 'enabled' => true, 'order' => 3],
    ['slug' => 'dilbert', 'label' => 'Dilbert', 'type' => 'dilbert', 'enabled' => true, 'order' => 4],
    ['slug' => 'farside', 'label' => 'Far Side', 'type' => 'hardcoded', 'enabled' => true, 'order' => 5],
];

$comicsCfg = @json_decode(file_get_contents(__DIR__ . '/config.json'), true) ?? [];
$stripsConfig = $comicsCfg['strips'] ?? $defaultStrips;
usort($stripsConfig, fn($a, $b) => (($a['order'] ?? 999) <=> ($b['order'] ?? 999)));
$stripsConfig = array_values(array_filter($stripsConfig, fn($strip) => $strip['enabled'] ?? true));

$gocomicsStrips = array_values(array_filter($stripsConfig, fn($strip) => ($strip['type'] ?? '') === 'gocomics'));
$dilbertEnabled = count(array_filter($stripsConfig, fn($strip) => ($strip['type'] ?? '') === 'dilbert')) > 0;
$farsideEnabled = count(array_filter($stripsConfig, fn($strip) => ($strip['slug'] ?? '') === 'farside')) > 0;

// ── curl helper (HTTPS works, file_get_contents does not in this container) ───
function curlGet(string $url, int $timeout = 20): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return ($res !== false && strlen($res) > 100) ? $res : null;
}

// ── Convert any image file to greyscale JPG using Imagick ────────────────────
function convertToJpg(string $file): void
{
    ob_start();
    try {
        $img = new Imagick($file . '[0]'); // [0] handles animated GIFs (first frame)
        $img->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(92);
        $img->writeImage($file);
        $img->destroy();
    } catch (Exception $e) {
        ob_end_clean();
        print "   Imagick convert error: " . $e->getMessage() . "\n";
        return;
    }
    ob_end_clean();
}

// ── Fetch a GoComics strip by slug, save to $outFile ─────────────────────────
// Tries today, yesterday, and two days ago before giving up.
function fetchGoComics(string $slug, string $outFile): bool
{
    $dates = [
        date('Y/m/d'),
        date('Y/m/d', time() - 86400),
        date('Y/m/d', time() - 86400 * 2),
    ];

    foreach ($dates as $date) {
        $html = curlGet("https://www.gocomics.com/{$slug}/{$date}");
        if (!$html) continue;

        // Extract og:image — present as standard meta tag in the HTML
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//meta[@property="og:image"]') as $node) {
            $url = $node->getAttribute('content');
            if (empty($url)) continue;
            $img = curlGet($url, 30);
            if ($img) {
                file_put_contents($outFile, $img);
                convertToJpg($outFile);
                print "   Saved {$outFile} from {$date}\n";
                return true;
            }
        }
    }

    print "   FAILED: could not fetch {$slug}\n";
    return false;
}

// ── GoComics strips ───────────────────────────────────────────────────────────
foreach ($gocomicsStrips as $strip) {
    $slug = $strip['slug'];
    print "-> Fetching GoComics: {$slug}\n";
    fetchGoComics($slug, $slug . '.jpg');
}

// ── Dilbert (random strip from archive 1989-04-16 to 2023-03-12) ─────────────
if ($dilbertEnabled) {
    print "-> Fetching Dilbert\n";

    $start = mktime(0, 0, 0, 4, 16, 1989);
    curlGet("https://dilbert-viewer.herokuapp.com/", 15); // wake Heroku dyno before timed fetch
    $end   = mktime(0, 0, 0, 3, 12, 2023);
    $date  = date('Y-m-d', rand($start, $end));
    $html  = curlGet("https://dilbert-viewer.herokuapp.com/{$date}");

    if ($html && preg_match('/<img[^>]+alt="Comic for [0-9-]+"[^>]*src=([^\s>]+)/i', $html, $m)) {
        $imgUrl = $m[1];
        $img    = curlGet($imgUrl, 30);
        if ($img) {
            file_put_contents('dilbert.jpg', $img);
            convertToJpg('dilbert.jpg');
            print "   Saved dilbert.jpg ({$date})\n";
        } else {
            print "   FAILED: could not download Dilbert image\n";
        }
    } else {
        print "   FAILED: could not fetch Dilbert page for {$date}\n";
    }
}

// ── Far Side panels ───────────────────────────────────────────────────────────
if ($farsideEnabled) {
    print "-> Fetching Far Side panels\n";

    foreach (glob('farside_*.jpg') as $old) {
        unlink($old);
    }

    $farsideData = [];
    $html = curlGet('https://www.thefarside.com/');

    if ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $cards = $xpath->query("//div[contains(@class,'tfs-comic')]");
        $i = 1;
        foreach ($cards as $card) {
            $imgNodes = $xpath->query(".//div[contains(@class,'tfs-comic__image')]/img", $card);
            if ($imgNodes->length === 0) continue;

            $img    = $imgNodes->item(0);
            $url    = $img->getAttribute('data-src');
            if (empty($url)) continue;

            $width  = (int)$img->getAttribute('data-width');
            $height = (int)$img->getAttribute('data-height');

            $captionNodes = $xpath->query(".//figcaption", $card);
            $caption = '';
            if ($captionNodes->length > 0) {
                $caption = trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent));
            }

            $data = curlGet($url, 30);
            if ($data) {
                $filename = "farside_{$i}.jpg";
                file_put_contents($filename, $data);
                convertToJpg($filename);

                if ($width === 0 || $height === 0) {
                    $info = @getimagesize($filename);
                    if ($info) { $width = $info[0]; $height = $info[1]; }
                }

                $farsideData[] = ['file' => $filename, 'width' => $width, 'height' => $height, 'caption' => $caption];
                print "   Panel {$i}: {$width}x{$height} — {$caption}\n";
                $i++;
                if (count($farsideData) >= 4) break;
            }
        }
    }
} else {
    $farsideData = [];
}

// ── Write metadata.json ───────────────────────────────────────────────────────
$stripFiles = [];
foreach ($stripsConfig as $strip) {
    if (($strip['type'] ?? '') === 'hardcoded') {
        continue;
    }
    $file = $strip['slug'] . '.jpg';
    if (file_exists($file)) {
        $stripFiles[] = $file;
    }
}

$stripsData = [];
foreach ($stripFiles as $file) {
    if (!file_exists($file)) continue;
    $info = @getimagesize($file);
    if ($info) {
        $stripsData[] = ['file' => $file, 'width' => $info[0], 'height' => $info[1]];
    }
}

$metadata = ['farside' => $farsideData, 'strips' => $stripsData, 'updated' => date('Y-m-d')];
file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
print "-> Wrote metadata.json\n";
