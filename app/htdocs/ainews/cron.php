#!/usr/local/bin/php
<?php

chdir(__DIR__);
$visionectLib = is_dir('/app/lib') ? '/app/lib' : __DIR__ . '/../../lib';
require_once $visionectLib . '/security.php';
require_once $visionectLib . '/http.php';
require_once $visionectLib . '/image.php';

// Image generation for one story may take at most this long; remaining providers are skipped after it.
const AINEWS_IMAGE_BUDGET_SECONDS = 150;
// A provider is not started with less than this many seconds of budget left.
const AINEWS_MIN_PROVIDER_SECONDS = 20;
// Article text shorter than this (chars) is "thin": summarise briefly, from the given text only.
const AINEWS_THIN_TEXT_CHARS = 400;
// Article text shorter than this (chars) is treated as no text at all: summarise the headline only.
const AINEWS_EMPTY_TEXT_CHARS = 40;
const AINEWS_IMAGE_OPTS = ['mode' => 'contain', 'background' => 'black', 'quality' => 80];

// ── Load config ───────────────────────────────────────────────────────────────
$cfg = json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true);
if (!$cfg) { fwrite(STDERR, "ERROR: could not read config.json\n"); exit(1); }
$cfg = visionect_decrypt_fields($cfg, ['groq_api_key', 'gemini_api_key', 'pollinations_api_key', 'huggingface_api_key', 'kie_api_key']);

$sources = $cfg['sources'] ?? [];
// ─────────────────────────────────────────────────────────────────────────────

// Load existing data for fallback on partial failure
$dataFile = __DIR__ . '/data.json';
$existing = ['stories' => array_fill(0, count($sources), null)];
if (file_exists($dataFile)) {
    $dec = json_decode((string)file_get_contents($dataFile), true);
    if (is_array($dec)) $existing = $dec;
}

$stories = [];

foreach ($sources as $i => $source) {
    $n        = $i + 1;
    $fallback = $existing['stories'][$i] ?? null;

    print "-> Story {$n}: {$source['label']}\n";

    // Fetch RSS feed; hnrss in particular fails intermittently, so retry once.
    $feed = null;
    for ($attempt = 1; $attempt <= 2 && $feed === null; $attempt++) {
        $rss = visionect_http_get($source['feed'], 15);
        if (!$rss['ok']) {
            print "   Feed attempt {$attempt} failed: {$rss['error']}\n";
        } else {
            $parsed = @simplexml_load_string($rss['body']);
            if ($parsed && isset($parsed->channel->item[0])) {
                $feed = $parsed;
            } else {
                print "   Feed attempt {$attempt}: could not parse RSS\n";
            }
        }
        if ($feed === null && $attempt === 1) {
            sleep(5);
        }
    }
    if ($feed === null) {
        print "   Feed failed — keeping previous story\n";
        $stories[] = $fallback;
        continue;
    }

    $item  = $feed->channel->item[0];
    $title = ainewsNormalizeText(trim((string)$item->title));
    $link  = trim((string)$item->link);
    $desc  = ainewsCleanDescription((string)$item->description);

    print "   Title: {$title}\n";

    // Try fetching the full article body
    $articleText = $desc;
    $page = visionect_http_get($link, 8);
    if ($page['ok'] && stripos($page['content_type'], 'html') !== false) {
        $doc = new DOMDocument();
        @$doc->loadHTML($page['body']);
        $removeNodes = [];
        foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'iframe', 'form', 'svg'] as $tag) {
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
            $text = trim(preg_replace('/\s+/u', ' ', $body->textContent));
            // Paywalls and bot checks (e.g. FT) return a challenge page instead of the article.
            // Match on the page title, or on a short body — real articles can mention "enable JavaScript" for embedded video.
            $titleNode = $doc->getElementsByTagName('title')->item(0);
            $pageTitle = $titleNode ? $titleNode->textContent : '';
            if (preg_match('/security verification|just a moment|access denied|attention required|are you a robot|captcha/i', $pageTitle)
                || (strlen($text) < 1500 && preg_match('/enable javascript|enable cookies|verify you are (a )?human/i', $text))) {
                print "   Article page blocked — using RSS description\n";
            } elseif (strlen($text) > strlen($desc)) {
                $articleText = ainewsTruncateUtf8($text, 2000);
            }
        }
    } elseif (!$page['ok']) {
        print "   Article fetch failed ({$page['error']}) — using RSS description\n";
    }

    // Summarise with Groq
    print '   Summarising (' . strlen($articleText) . " chars of source text)...\n";
    $summary = ainewsNormalizeText(groqSummarize($title, $articleText, (string)($cfg['groq_api_key'] ?? ''), (int)($cfg['summary_words'] ?? 50), (string)($cfg['summary_prompt'] ?? '')));

    // Generate comic illustration. story{n}.jpg is only replaced (atomically) on success.
    print "   Generating illustration...\n";
    $imageFile = "story{$n}.jpg";
    $ok = generateComicImage($title, $summary, $imageFile, $cfg, time() + AINEWS_IMAGE_BUDGET_SECONDS);
    if (!$ok) {
        if (is_array($fallback) && !empty($fallback['title'])) {
            // Never pair the new headline with an unrelated old image: keep the whole previous story.
            print "   Image generation failed — keeping previous story\n";
            $stories[] = $fallback;
            continue;
        }
        print "   Image generation failed — no previous story, saving text only\n";
        $imageFile = '';
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

// Normalise every story, including kept fallbacks written by older versions.
foreach ($stories as &$story) {
    if (is_array($story)) {
        $story['title'] = ainewsNormalizeText((string)($story['title'] ?? ''));
        $story['summary'] = ainewsNormalizeText((string)($story['summary'] ?? ''));
    }
}
unset($story);

if (visionect_write_json_atomic($dataFile, ['stories' => $stories])) {
    print "-> Wrote data.json\n";
} else {
    print "-> ERROR: could not write data.json\n";
    exit(1);
}


// ── Text helpers ──────────────────────────────────────────────────────────────

/**
 * Replace characters the frame renders badly with plain equivalents.
 * Non-breaking/figure hyphens -> "-", horizontal bar -> em dash (en/em dashes render fine in the
 * frame's DejaVu/Noto fonts), narrow/no-break/thin spaces -> " ", zero-width characters removed.
 */
function ainewsNormalizeText(string $text): string
{
    $text = strtr($text, [
        "\u{2010}" => '-',        // hyphen
        "\u{2011}" => '-',        // non-breaking hyphen
        "\u{2012}" => '-',        // figure dash
        "\u{2015}" => "\u{2014}", // horizontal bar -> em dash
        "\u{2212}" => '-',        // minus sign
        "\u{00A0}" => ' ',        // no-break space
        "\u{202F}" => ' ',        // narrow no-break space
        "\u{2007}" => ' ',        // figure space
        "\u{2009}" => ' ',        // thin space
        "\u{200A}" => ' ',        // hair space
        "\u{200B}" => '',         // zero-width space
        "\u{2060}" => '',         // word joiner
        "\u{FEFF}" => '',         // BOM / zero-width no-break space
    ]);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    return trim($text);
}

/** Plain text of an RSS description, minus hnrss boilerplate (Article URL / Comments URL / Points). */
function ainewsCleanDescription(string $html): string
{
    // Tags become spaces so adjacent paragraphs ("...</p><p>Comments URL") don't glue together.
    $text = html_entity_decode(strip_tags(preg_replace('/</', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace([
        '/\b(?:Article|Comments) URL:\s*\S+/i',
        '/\bPoints:\s*\d+/i',
        '/#\s*Comments:\s*\d+/i',
    ], ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function ainewsTruncateUtf8(string $text, int $bytes): string
{
    if (strlen($text) <= $bytes) {
        return $text;
    }
    // Cut on a byte boundary, then drop any partial trailing UTF-8 sequence.
    return (string)preg_replace('/[\x80-\xBF]*[\xC0-\xFF]?$/', '', substr($text, 0, $bytes));
}

/** Seconds left before $deadline, clamped to [$min, $max]. */
function ainewsTimeout(int $deadline, int $max, int $min = 5): int
{
    return max($min, min($max, $deadline - time()));
}


// ── Groq summarisation ────────────────────────────────────────────────────────
function groqSummarize(string $title, string $text, string $apiKey, int $words = 50, string $promptTemplate = ''): string
{
    $text = trim($text);
    $chars = strlen($text);
    $noText = $chars < AINEWS_EMPTY_TEXT_CHARS;
    $thin = !$noText && $chars < AINEWS_THIN_TEXT_CHARS;

    $instruction = $promptTemplate
        ? str_replace('{words}', (string)$words, $promptTemplate)
        : "Summarize this news story in exactly {$words} words. Write as flowing prose only — no bullet points, no headers, no markdown.";
    $grounding = 'Use ONLY facts stated in the headline and content below. Never invent or guess details, numbers, names, quotes, causes or outcomes that are not in the text. Do not mention that the text is short or missing.';

    if ($noText) {
        $shortWords = min($words, 25);
        $prompt = "Write one or two plain-language sentences (at most {$shortWords} words) explaining this news headline. "
            . "Only the headline is available: restate what it says without adding any facts, numbers, names or context that are not in the headline itself. "
            . "No bullet points, no markdown.\n\nHeadline: {$title}";
        print "   No article text — summarising from the headline only\n";
    } elseif ($thin) {
        $shortWords = min($words, 40);
        $prompt = $instruction . "\n\n" . $grounding
            . " The content is short, so keep the summary short (at most {$shortWords} words) instead of padding it out."
            . "\n\nHeadline: {$title}\n\nContent:\n{$text}";
        print "   Thin article text — short, grounded summary\n";
    } else {
        $prompt = $instruction . "\n\n" . $grounding . "\n\nHeadline: {$title}\n\nContent:\n{$text}";
    }

    if ($apiKey !== '') {
        $payload = json_encode([
            'model'             => 'openai/gpt-oss-120b',
            'messages'          => [['role' => 'user', 'content' => $prompt]],
            // gpt-oss is a reasoning model: reasoning tokens count toward max_tokens
            'max_tokens'        => 1024,
            'reasoning_effort'  => 'low',
            'include_reasoning' => false,
            'temperature'       => 0.4,
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        $res = visionect_http_post(
            'https://api.groq.com/openai/v1/chat/completions',
            (string)$payload,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            30
        );

        $json = json_decode((string)($res['body'] ?? ''), true);
        $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        if ($res['status'] === 200 && $content !== '') {
            print '   Summary: ~' . count(preg_split('/\s+/', $content)) . " words\n";
            return $content;
        }
        print '   Groq error: ' . ($json['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status'])) . "\n";
    }

    print "   Groq API failed — using truncated source text\n";
    if ($noText) {
        return $title;
    }
    $wordList = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_slice($wordList, 0, $words));
}


// ── Image generation — cascades kie.ai → Gemini → Pollinations → HuggingFace ─
function generateComicImage(string $title, string $summary, string $outFile, array $cfg, int $deadline): bool
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
        $fluxPrompt = ainewsTruncateUtf8($fluxPrompt, 397) . '...';
    }

    $providerOrder = $cfg['provider_order'] ?? ['kie', 'gemini', 'pollinations', 'huggingface'];
    if (!is_array($providerOrder) || empty($providerOrder)) {
        $providerOrder = ['kie', 'gemini', 'pollinations', 'huggingface'];
    }

    foreach ($providerOrder as $provider) {
        $left = $deadline - time();
        if ($left < AINEWS_MIN_PROVIDER_SECONDS) {
            print "   Image time budget used up ({$left}s left) — skipping remaining providers\n";
            return false;
        }

        switch ($provider) {
            case 'kie':
                if (empty($cfg['kie_api_key'])) {
                    break;
                }
                if (generateViaKieAi($detailedPrompt, $outFile, $cfg['kie_api_key'], $cfg['kie_model'] ?? 'google/nano-banana', $deadline)) {
                    return true;
                }
                print "   Falling back after kie.ai...\n";
                break;

            case 'gemini':
                if (empty($cfg['gemini_api_key'])) {
                    break;
                }
                if (generateViaGemini($detailedPrompt, $outFile, $cfg['gemini_api_key'], $cfg['gemini_model'] ?? 'gemini-2.5-flash-image', $deadline)) {
                    return true;
                }
                print "   Falling back after Gemini...\n";
                break;

            case 'pollinations':
                if (empty($cfg['pollinations_api_key'])) {
                    break;
                }
                if (generateViaPollinationsAi($fluxPrompt, $outFile, $cfg['pollinations_api_key'], $deadline)) {
                    return true;
                }
                print "   Falling back after Pollinations...\n";
                break;

            case 'huggingface':
                if (empty($cfg['huggingface_api_key'])) {
                    break;
                }
                if (generateViaHuggingFace($fluxPrompt, $outFile, $cfg['huggingface_api_key'], $deadline)) {
                    return true;
                }
                print "   Falling back after HuggingFace...\n";
                break;
        }
    }
    return false;
}

/**
 * Validate raw provider output and save it to $outFile as a 1440x2560 greyscale JPG.
 * Rejects non-200 responses and anything that is not a decodable image (HTML error pages,
 * JSON errors, truncated downloads). $outFile is replaced atomically, only on success.
 */
function saveProviderImage(string $provider, int $status, ?string $data, string $outFile): bool
{
    if ($status !== 200) {
        print "   {$provider}: HTTP {$status} — rejected\n";
        return false;
    }
    if ($data === null || !visionect_is_image_blob($data)) {
        print "   {$provider}: response is not an image (" . strlen((string)$data) . " bytes) — rejected\n";
        return false;
    }

    $rawInfo = @getimagesizefromstring($data);
    if ($rawInfo) print "   {$provider} raw: {$rawInfo[0]}x{$rawInfo[1]}\n";

    if (!visionect_image_to_frame($data, $outFile, AINEWS_IMAGE_OPTS)) {
        print "   {$provider}: could not convert image — " . (visionect_image_last_error() ?? 'unknown error') . "\n";
        return false;
    }

    clearstatcache(true, $outFile);
    print '   Saved ' . $outFile . ' (' . round(filesize($outFile) / 1024) . " KB, 1440x2560)\n";
    return true;
}

// ── kie.ai image generation (primary) ────────────────────────────────────────
function generateViaKieAi(string $prompt, string $outFile, string $apiKey, string $model, int $deadline): bool
{
    print "   Provider: kie.ai ({$model})\n";

    // Submit generation task
    $payload = json_encode([
        'model' => $model,
        'input' => [
            'prompt'     => $prompt,
            'image_size' => '9:16',
        ],
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    $res = visionect_http_post(
        'https://api.kie.ai/api/v1/jobs/createTask',
        (string)$payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        ainewsTimeout($deadline, 30)
    );

    if (!$res['ok']) {
        print "   kie.ai: task creation failed ({$res['error']})\n";
        return false;
    }

    $json = json_decode($res['body'], true);
    $taskId = $json['data']['taskId'] ?? null;
    if (!$taskId) {
        print '   kie.ai: no taskId in response: ' . substr($res['body'], 0, 200) . "\n";
        return false;
    }

    print "   kie.ai: taskId={$taskId}, polling...\n";

    // Poll for completion (up to 120s or the story budget, checking every 5s).
    // Leave ~15s of the budget for the download.
    $pollDeadline = min(time() + 120, $deadline - 15);
    $imageUrl = null;
    while (time() < $pollDeadline) {
        sleep(5);

        $poll = visionect_http_get(
            'https://api.kie.ai/api/v1/jobs/recordInfo?taskId=' . urlencode($taskId),
            ainewsTimeout($deadline, 15),
            ['Authorization: Bearer ' . $apiKey]
        );

        if (!$poll['ok']) continue;

        $pollJson = json_decode($poll['body'], true);
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
    $download = visionect_http_get($imageUrl, ainewsTimeout($deadline, 60, 15));
    return saveProviderImage('kie.ai', $download['status'], $download['body'], $outFile);
}

// ── Pollinations.ai ───────────────────────────────────────────────────────────
function generateViaPollinationsAi(string $prompt, string $outFile, string $apiKey, int $deadline): bool
{
    // FLUX works best with concise prompts; also keeps URL under Cloudflare's limit
    if (strlen($prompt) > 500) {
        $prompt = ainewsTruncateUtf8($prompt, 497) . '...';
    }

    // 768×1440 = exact 8:15 portrait ratio; enhance=false keeps our prompt as-is
    $url = 'https://gen.pollinations.ai/image/' . rawurlencode($prompt)
         . '?width=768&height=1440&model=flux&nologo=true&enhance=false'
         . ($apiKey ? '&key=' . urlencode($apiKey) : '');

    print "   Provider: Pollinations.ai (FLUX)\n";
    $res = visionect_http_get($url, ainewsTimeout($deadline, 90));
    if ($res['status'] === 0) {
        print "   Pollinations: request failed ({$res['error']})\n";
        return false;
    }
    return saveProviderImage('Pollinations', $res['status'], $res['body'], $outFile);
}

// ── Gemini image generation ───────────────────────────────────────────────────
function generateViaGemini(string $prompt, string $outFile, string $apiKey, string $model, int $deadline): bool
{
    $payload = json_encode([
        'contents' => [[
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'imageConfig' => ['aspectRatio' => '9:16', 'imageSize' => '2K'],
        ],
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . $model . ':generateContent?key=' . $apiKey;

    print "   Provider: Gemini ({$model})\n";
    $res = visionect_http_post($url, (string)$payload, ['Content-Type: application/json'], ainewsTimeout($deadline, 90));

    $json = json_decode((string)($res['body'] ?? ''), true);
    if (isset($json['error'])) {
        print '   Gemini error: ' . ($json['error']['message'] ?? 'unknown') . "\n";
        return false;
    }
    if (!$res['ok']) {
        print "   Gemini API call failed ({$res['error']})\n";
        return false;
    }

    $parts = $json['candidates'][0]['content']['parts'] ?? [];
    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            $imageData = base64_decode((string)$part['inlineData']['data'], true);
            return saveProviderImage('Gemini', $res['status'], $imageData === false ? null : $imageData, $outFile);
        }
    }

    print "   Gemini returned no image data\n";
    return false;
}

// ── HuggingFace Inference (fallback) ─────────────────────────────────────────
function generateViaHuggingFace(string $prompt, string $outFile, string $apiKey, int $deadline): bool
{
    $payload = json_encode([
        'inputs'     => $prompt,
        'parameters' => ['width' => 768, 'height' => 1440],
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    print "   Provider: HuggingFace (FLUX.1-schnell)\n";
    $res = visionect_http_post(
        'https://router.huggingface.co/hf-inference/models/black-forest-labs/FLUX.1-schnell',
        (string)$payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        ainewsTimeout($deadline, 90)
    );
    if ($res['status'] === 0) {
        print "   HuggingFace: request failed ({$res['error']})\n";
        return false;
    }
    return saveProviderImage('HuggingFace', $res['status'], $res['body'], $outFile);
}
