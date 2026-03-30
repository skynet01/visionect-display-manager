#!/usr/local/bin/php
<?php

chdir(__DIR__);
require __DIR__ . '/../../lib/security.php';

// ── Load config ───────────────────────────────────────────────────────────────
$cfg = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$cfg) { fwrite(STDERR, "ERROR: could not read config.json\n"); exit(1); }
$cfg = visionect_decrypt_fields($cfg, ['groq_api_key', 'gemini_api_key', 'pollinations_api_key', 'huggingface_api_key', 'kie_api_key']);

$sources = $cfg['sources'] ?? [];
// ─────────────────────────────────────────────────────────────────────────────

// Load existing data for fallback on partial failure
$dataFile = __DIR__ . '/data.json';
$existing = ['stories' => array_fill(0, count($sources), null)];
if (file_exists($dataFile)) {
    $dec = json_decode(file_get_contents($dataFile), true);
    if ($dec) $existing = $dec;
}

$stories = [];

foreach ($sources as $i => $source) {
    $n        = $i + 1;
    $fallback = $existing['stories'][$i] ?? null;

    print "-> Story {$n}: {$source['label']}\n";

    // Fetch RSS feed via curl (handles HTTP + HTTPS + redirects)
    $rssData = curlGet($source['feed'], 15);
    if (!$rssData) {
        print "   Feed failed — keeping fallback\n";
        $stories[] = $fallback;
        continue;
    }

    $feed = @simplexml_load_string($rssData);
    if (!$feed || !isset($feed->channel->item[0])) {
        print "   Feed parse failed — keeping fallback\n";
        $stories[] = $fallback;
        continue;
    }

    $item  = $feed->channel->item[0];
    $title = trim((string)$item->title);
    $link  = trim((string)$item->link);
    $desc  = trim(strip_tags((string)$item->description));

    print "   Title: {$title}\n";

    // Try fetching full article body via curl (handles HTTPS)
    $articleText = $desc;
    $html = curlGet($link, 6);
    if ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $removeNodes = [];
        foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'iframe', 'form'] as $tag) {
            $nodeList = $doc->getElementsByTagName($tag);
            for ($j = $nodeList->length - 1; $j >= 0; $j--) {
                $removeNodes[] = $nodeList->item($j);
            }
        }
        foreach ($removeNodes as $node) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $text = preg_replace('/\s+/', ' ', strip_tags($doc->saveHTML($body)));
            if (strlen($text) > strlen($desc)) {
                $articleText = substr($text, 0, 2000);
            }
        }
    }

    // Summarise with Groq
    print "   Summarising...\n";
    $summary = groqSummarize($title, $articleText, $cfg['groq_api_key'], (int)($cfg['summary_words'] ?? 50), $cfg['summary_prompt'] ?? '');

    // Generate comic illustration
    print "   Generating illustration...\n";
    $imageFile = "story{$n}.jpg";
    $ok = generateComicImage($title, $summary, $imageFile, $cfg);
    if (!$ok) {
        print "   Image generation failed";
        if ($fallback && !empty($fallback['image']) && file_exists($fallback['image'])) {
            $imageFile = $fallback['image'];
            print " — keeping previous image";
        }
        print "\n";
    }

    $stories[] = [
        'title'   => $title,
        'summary' => $summary,
        'source'  => $source['label'],
        'url'     => $link,
        'image'   => $imageFile,
        'date'    => date('F j, Y'),
    ];
}

file_put_contents($dataFile, json_encode(['stories' => $stories], JSON_PRETTY_PRINT));
print "-> Wrote data.json\n";


// ── Shared curl helpers ───────────────────────────────────────────────────────
function curlGet(string $url, int $timeout = 30, array $headers = []): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    curl_close($ch);
    return ($res !== false && strlen($res) > 0) ? $res : null;
}

function curlPost(string $url, string $body, array $headers, int $timeout = 30): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return ($res !== false) ? $res : null;
}


// ── Groq summarisation ────────────────────────────────────────────────────────
function groqSummarize(string $title, string $text, string $apiKey, int $words = 50, string $promptTemplate = ''): string
{
    $instruction = $promptTemplate
        ? str_replace('{words}', $words, $promptTemplate)
        : "Summarize this news story in exactly {$words} words. Write as flowing prose only — no bullet points, no headers, no markdown.";

    $prompt = $instruction . "\n\nHeadline: {$title}\n\nContent:\n{$text}";

    $payload = json_encode([
        'model'       => 'llama-3.3-70b-versatile',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => max(120, $words * 2),
        'temperature' => 0.4,
    ]);

    $res = curlPost(
        'https://api.groq.com/openai/v1/chat/completions',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        30
    );

    if ($res) {
        $json    = json_decode($res, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        if ($content) {
            print '   Summary: ~' . count(explode(' ', $content)) . " words\n";
            return $content;
        }
        print '   Groq error: ' . ($json['error']['message'] ?? 'unknown') . "\n";
    }

    print "   Groq API failed — using truncated RSS description\n";
    $wordList = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_slice($wordList, 0, $words));
}


// ── Image generation — cascades kie.ai → Gemini → Pollinations → HuggingFace ─
function generateComicImage(string $title, string $summary, string $outFile, array $cfg): bool
{
    // Full detailed prompt for instruction-following models (kie.ai, Gemini)
    $promptTemplate = $cfg['image_prompt'] ?? '';
    $detailedPrompt = str_replace(['{title}', '{summary}'], [$title, $summary], $promptTemplate);
    if (empty($detailedPrompt)) {
        $detailedPrompt = "Single-panel Far Side-style black and white ink comic. Topic: {$title}. {$summary}. No content in bottom 25%.";
    }

    // Concise prompt for FLUX-based providers (diffusion models work better with short prompts)
    $fluxPrompt = "Far Side-style black and white ink comic, crosshatching, bold outlines. {$title}. {$summary}. No content in bottom 25% or near edges.";
    if (strlen($fluxPrompt) > 400) {
        $fluxPrompt = substr($fluxPrompt, 0, 397) . '...';
    }

    $providerOrder = $cfg['provider_order'] ?? ['kie', 'gemini', 'pollinations', 'huggingface'];
    if (!is_array($providerOrder) || empty($providerOrder)) {
        $providerOrder = ['kie', 'gemini', 'pollinations', 'huggingface'];
    }

    foreach ($providerOrder as $provider) {
        switch ($provider) {
            case 'kie':
                if (empty($cfg['kie_api_key'])) {
                    break;
                }
                if (generateViaKieAi($detailedPrompt, $outFile, $cfg['kie_api_key'], $cfg['kie_model'] ?? 'google/nano-banana')) {
                    return true;
                }
                print "   Falling back after kie.ai...\n";
                break;

            case 'gemini':
                if (empty($cfg['gemini_api_key'])) {
                    break;
                }
                if (generateViaGemini($detailedPrompt, $outFile, $cfg['gemini_api_key'], $cfg['gemini_model'] ?? 'gemini-2.5-flash-image')) {
                    return true;
                }
                print "   Falling back after Gemini...\n";
                break;

            case 'pollinations':
                if (empty($cfg['pollinations_api_key'])) {
                    break;
                }
                if (generateViaPollinationsAi($fluxPrompt, $outFile, $cfg['pollinations_api_key'])) {
                    return true;
                }
                print "   Falling back after Pollinations...\n";
                break;

            case 'huggingface':
                if (empty($cfg['huggingface_api_key'])) {
                    break;
                }
                if (generateViaHuggingFace($fluxPrompt, $outFile, $cfg['huggingface_api_key'])) {
                    return true;
                }
                print "   Falling back after HuggingFace...\n";
                break;
        }
    }
    return false;
}

// ── kie.ai image generation (primary) ────────────────────────────────────────
function generateViaKieAi(string $prompt, string $outFile, string $apiKey, string $model): bool
{
    print "   Provider: kie.ai ({$model})\n";

    // Submit generation task
    $payload = json_encode([
        'model' => $model,
        'input' => [
            'prompt'     => $prompt,
            'image_size' => '9:16',
        ],
    ]);

    $res = curlPost(
        'https://api.kie.ai/api/v1/jobs/createTask',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        30
    );

    if (!$res) {
        print "   kie.ai: task creation failed\n";
        return false;
    }

    $json = json_decode($res, true);
    $taskId = $json['data']['taskId'] ?? null;
    if (!$taskId) {
        print '   kie.ai: no taskId in response: ' . substr($res, 0, 200) . "\n";
        return false;
    }

    print "   kie.ai: taskId={$taskId}, polling...\n";

    // Poll for completion (up to 120s, checking every 5s)
    $deadline = time() + 120;
    $imageUrl = null;
    while (time() < $deadline) {
        sleep(5);

        $poll = curlGet(
            'https://api.kie.ai/api/v1/jobs/recordInfo?taskId=' . urlencode($taskId),
            15,
            ['Authorization: Bearer ' . $apiKey]
        );

        if (!$poll) continue;

        $pollJson = json_decode($poll, true);
        $state    = $pollJson['data']['state'] ?? 'unknown';
        print "   kie.ai: state={$state}\n";

        if ($state === 'success') {
            $resultJson = $pollJson['data']['resultJson'] ?? '';
            $result     = is_string($resultJson) ? json_decode($resultJson, true) : $resultJson;
            $imageUrl   = $result['resultUrls'][0] ?? null;
            break;
        }
        if ($state === 'fail') {
            print "   kie.ai: generation failed\n";
            return false;
        }
        // waiting / queuing / generating — keep polling
    }

    if (!$imageUrl) {
        print "   kie.ai: timed out or no image URL\n";
        return false;
    }

    print "   kie.ai: downloading image...\n";
    $imageData = curlGet($imageUrl, 60);

    if (!$imageData || strlen($imageData) < 5000) {
        print "   kie.ai: image download failed\n";
        return false;
    }

    $tmp = $outFile . '.tmp';
    file_put_contents($tmp, $imageData);

    $rawInfo = @getimagesize($tmp);
    if ($rawInfo) print '   kie.ai raw: ' . $rawInfo[0] . 'x' . $rawInfo[1] . "\n";

    if (resizeToFrame($tmp, $outFile, 1440, 2560)) {
        unlink($tmp);
        print '   Saved ' . $outFile . ' (' . round(filesize($outFile) / 1024) . " KB, 1440x2560)\n";
    } else {
        rename($tmp, $outFile);
        print '   Saved ' . $outFile . " (raw, resize failed)\n";
    }
    return true;
}

// ── Pollinations.ai ───────────────────────────────────────────────────────────
function generateViaPollinationsAi(string $prompt, string $outFile, string $apiKey = ''): bool
{
    // FLUX works best with concise prompts; also keeps URL under Cloudflare's limit
    if (strlen($prompt) > 500) {
        $prompt = substr($prompt, 0, 497) . '...';
    }

    // 768×1440 = exact 8:15 portrait ratio; enhance=false keeps our prompt as-is
    $url = 'https://gen.pollinations.ai/image/' . rawurlencode($prompt)
         . '?width=768&height=1440&model=flux&nologo=true&enhance=false'
         . ($apiKey ? '&key=' . urlencode($apiKey) : '');

    print "   Provider: Pollinations.ai (FLUX)\n";
    $imageData = curlGet($url, 90);

    if (!$imageData || strlen($imageData) < 5000) {
        print "   Pollinations returned no image data\n";
        return false;
    }

    $tmp = $outFile . '.tmp';
    file_put_contents($tmp, $imageData);

    if (resizeToFrame($tmp, $outFile, 1440, 2560)) {
        unlink($tmp);
        print '   Saved ' . $outFile . ' (' . round(filesize($outFile) / 1024) . " KB, 1440x2560)\n";
    } else {
        rename($tmp, $outFile);
        print '   Saved ' . $outFile . " (raw, resize failed)\n";
    }
    return true;
}

// ── Gemini image generation ───────────────────────────────────────────────────
function generateViaGemini(string $prompt, string $outFile, string $apiKey, string $model): bool
{
    $payload = json_encode([
        'contents' => [[
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'imageConfig' => ['aspectRatio' => '9:16', 'imageSize' => '2K'],
        ],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . $model . ':generateContent?key=' . $apiKey;

    print "   Provider: Gemini ({$model})\n";
    $res = curlPost($url, $payload, ['Content-Type: application/json'], 90);

    if (!$res) {
        print "   Gemini API call failed\n";
        return false;
    }

    $json = json_decode($res, true);

    if (isset($json['error'])) {
        print '   Gemini error: ' . ($json['error']['message'] ?? 'unknown') . "\n";
        return false;
    }

    $parts = $json['candidates'][0]['content']['parts'] ?? [];
    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            $imageData = base64_decode($part['inlineData']['data']);
            if ($imageData && strlen($imageData) > 5000) {
                $tmp = $outFile . '.tmp';
                file_put_contents($tmp, $imageData);

                $rawInfo = @getimagesize($tmp);
                if ($rawInfo) print '   Gemini raw: ' . $rawInfo[0] . 'x' . $rawInfo[1] . "\n";

                if (resizeToFrame($tmp, $outFile, 1440, 2560)) {
                    unlink($tmp);
                    print '   Saved ' . $outFile . ' (' . round(filesize($outFile) / 1024) . " KB, 1440x2560)\n";
                } else {
                    rename($tmp, $outFile);
                    print '   Saved ' . $outFile . " (raw, resize failed)\n";
                }
                return true;
            }
        }
    }

    print "   Gemini returned no image data\n";
    return false;
}

// ── HuggingFace Inference (fallback) ─────────────────────────────────────────
function generateViaHuggingFace(string $prompt, string $outFile, string $apiKey): bool
{
    $payload = json_encode([
        'inputs'     => $prompt,
        'parameters' => ['width' => 768, 'height' => 1440],
    ]);

    print "   Provider: HuggingFace (FLUX.1-schnell)\n";
    $res = curlPost(
        'https://router.huggingface.co/hf-inference/models/black-forest-labs/FLUX.1-schnell',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        90
    );

    if (!$res || strlen($res) < 5000) {
        print "   HuggingFace returned no image data\n";
        return false;
    }

    $tmp = $outFile . '.tmp';
    file_put_contents($tmp, $res);

    if (resizeToFrame($tmp, $outFile, 1440, 2560)) {
        unlink($tmp);
        print '   Saved ' . $outFile . ' (' . round(filesize($outFile) / 1024) . " KB, 1440x2560)\n";
    } else {
        rename($tmp, $outFile);
        print '   Saved ' . $outFile . " (raw, resize failed)\n";
    }
    return true;
}

// ── Fit image into frame with black padding (no cropping) ─────────────────────
function resizeToFrame(string $src, string $dest, int $w, int $h): bool
{
    ob_start();
    try {
        $img = new Imagick($src);

        // Scale to fit entirely within frame — no cropping, aspect ratio preserved
        $img->thumbnailImage($w, $h, true);

        // Create black canvas at target size and center the scaled image on it
        $canvas = new Imagick();
        $canvas->newImage($w, $h, new ImagickPixel('black'));
        $canvas->setImageFormat('jpeg');

        $offsetX = (int)(($w - $img->getImageWidth())  / 2);
        $offsetY = (int)(($h - $img->getImageHeight()) / 2);
        $canvas->compositeImage($img, Imagick::COMPOSITE_OVER, $offsetX, $offsetY);

        $canvas->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $canvas->setImageCompressionQuality(92);
        $canvas->writeImage($dest);

        $img->destroy();
        $canvas->destroy();
        ob_end_clean();
        return true;
    } catch (Exception $e) {
        ob_end_clean();
        print '   Imagick error: ' . $e->getMessage() . "\n";
        return false;
    }
}
