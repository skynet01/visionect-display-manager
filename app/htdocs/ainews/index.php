<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : dirname(__DIR__, 2) . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();
visionect_track_frame_request('ainews');

$dataFile = __DIR__ . '/data.json';
$data     = null;
$stories  = [];

if (file_exists($dataFile)) {
    $decoded = json_decode(file_get_contents($dataFile), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $stories = array_values(array_filter($decoded['stories'] ?? []));
        if (!empty($stories)) {
            $requestedImage = basename((string)($_GET['image'] ?? ''));
            foreach ($stories as $story) {
                $storyImage = basename((string)($story['image'] ?? ''));
                if ($requestedImage !== '' && $storyImage === $requestedImage) {
                    $data = $story;
                    break;
                }
            }

            if ($data === null) {
                $data = $stories[array_rand($stories)];
            }
        }
    }
}

$title   = $data['title']   ?? 'AI News';
$summary = $data['summary'] ?? 'Story not yet available. Run cron.php to fetch today\'s news.';
$source  = $data['source']  ?? '';
$date    = $data['date']    ?? date('F j, Y');
$image   = $data['image']   ?? '';
$imageFile = basename((string)$image);
if ($imageFile !== '') {
    visionect_record_frame_response('ainews', '/ainews/?image=' . rawurlencode($imageFile), 'page', [
        'asset_file' => $imageFile,
        'story_title' => $title,
    ]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>AI News</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            width: 1440px;
            height: 2560px;
            overflow: hidden;
            background: #000;
        }

        .frame {
            position: relative;
            width: 1440px;
            height: 2560px;
        }

        /* Full-frame illustration */
        .bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
        }

        /* Gradient overlay — bottom 20% of frame (512px), white at bottom fading to transparent */
        .overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 680px;
            background: linear-gradient(to top, #fff 0%, rgba(255,255,255,0.97) 55%, rgba(255,255,255,0.2) 85%, transparent 100%);
            padding: 44px 72px 52px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            overflow: hidden;
        }

        /* Source + date tag */
        .meta {
            font-family: 'Cabin', sans-serif;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 18px;
            flex-shrink: 0;
        }

        /* Story number badge */
        .meta .badge {
            display: inline-block;
            background: #000;
            color: #fff;
            font-size: 22px;
            padding: 3px 12px;
            margin-right: 14px;
            letter-spacing: 2px;
        }

        /* Headline */
        .title {
            font-family: 'Cabin', sans-serif;
            font-size: 58px;
            font-weight: 700;
            line-height: 1.1;
            color: #111;
            margin-bottom: 22px;
            flex-shrink: 0;
        }

        /* Summary */
        .summary {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 26px;
            line-height: 1.55;
            color: #333;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<div class="frame">

    <div class="bg" style="background-image: url('<?= htmlspecialchars($imageFile !== '' ? $imageFile : $image) ?>')"></div>

    <div class="overlay">
        <div class="meta">
            <span class="badge"><?= htmlspecialchars($source) ?></span>
            <?= htmlspecialchars($date) ?>
        </div>
        <div class="title"><?= htmlspecialchars($title) ?></div>
        <div class="summary"><?= nl2br(htmlspecialchars($summary)) ?></div>
    </div>

</div>
</body>
</html>
