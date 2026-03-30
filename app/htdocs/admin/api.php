<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : __DIR__ . '/../lib/security.php';
require_once $securityHelper;
visionect_session_boot();

header('Content-Type: application/json');
header('Cache-Control: no-store');

const PREFS_FILE = '/app/config/PREFS.json';
const HA_CONFIG_FILE = '/app/config/ha_integration.json';
const GENERAL_CONFIG_FILE = '/app/config/general_settings.json';
const HTDOCS_DIR = '/app/htdocs';
const GALLERY_MODULES = ['art', 'haynesmann', 'quotes'];
const MODULES = ['clock', 'newspaper', 'art', 'haynesmann', 'comics', 'quotes', 'ainews'];
const CRON_MODULES = ['newspaper', 'comics', 'ainews'];
const CLOCK_STYLES = ['digital', 'analog', 'words', 'clocks', 'flip'];
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const AINEWS_SECRET_FIELDS = ['groq_api_key', 'gemini_api_key', 'pollinations_api_key', 'huggingface_api_key', 'kie_api_key'];
const HA_SECRET_FIELDS = ['access_token'];

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void
{
    respond(['error' => $message], $code);
}

function read_json(string $path): ?array
{
    return visionect_read_json_file($path);
}

function write_json(string $path, $data): void
{
    visionect_write_json_file($path, (array)$data);
}

function body_json(): ?array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') === false) {
        return null;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function comics_defaults(): array
{
    return [
        'gap_strip' => 32,
        'gap_min' => 6,
        'gap_max' => 48,
        'strips' => [
            ['slug' => 'garfield', 'label' => 'Garfield', 'type' => 'gocomics', 'enabled' => true, 'order' => 1, 'fetch_mode' => 'auto', 'image_url' => ''],
            ['slug' => 'pearlsbeforeswine', 'label' => 'Pearls Before Swine', 'type' => 'gocomics', 'enabled' => true, 'order' => 2, 'fetch_mode' => 'auto', 'image_url' => ''],
            ['slug' => 'calvinandhobbes', 'label' => 'Calvin and Hobbes', 'type' => 'gocomics', 'enabled' => true, 'order' => 3, 'fetch_mode' => 'auto', 'image_url' => ''],
            ['slug' => 'dilbert', 'label' => 'Dilbert', 'type' => 'dilbert', 'enabled' => true, 'order' => 4, 'fetch_mode' => 'auto', 'image_url' => ''],
            ['slug' => 'farside', 'label' => 'Far Side', 'type' => 'hardcoded', 'enabled' => true, 'order' => 5, 'fetch_mode' => 'auto', 'image_url' => ''],
        ],
    ];
}

function default_module_config(string $module): array
{
    if ($module === 'clock') {
        return ['enabled_styles' => CLOCK_STYLES];
    }
    if ($module === 'comics') {
        return comics_defaults();
    }
    return [];
}

function default_ha_config(): array
{
    return [
        'enabled' => false,
        'base_url' => 'http://172.16.3.2:8123',
        'entity_id' => 'device_tracker.alex_bayesian',
        'home_state' => 'home',
        'access_token' => '',
        'timeout' => 10,
    ];
}

function default_general_config(): array
{
    return [
        'frame_width' => 1440,
        'frame_height' => 2560,
        'sleep_enabled' => false,
        'wake_time' => '08:00',
        'sleep_time' => '23:00',
    ];
}

function ha_config_payload(): array
{
    $config = read_json(HA_CONFIG_FILE) ?? [];
    return visionect_decrypt_fields(array_merge(default_ha_config(), is_array($config) ? $config : []), HA_SECRET_FIELDS);
}

function general_config_payload(): array
{
    $config = read_json(GENERAL_CONFIG_FILE);
    $raw = is_array($config) ? $config : [];
    $general = array_merge(default_general_config(), $raw);

    $legacyHa = read_json(HA_CONFIG_FILE) ?? [];
    if (is_array($legacyHa)) {
        if (!array_key_exists('sleep_enabled', $raw) && array_key_exists('sleep_enabled', $legacyHa)) {
            $general['sleep_enabled'] = (bool)$legacyHa['sleep_enabled'];
        }
        if (!array_key_exists('wake_time', $raw) && !empty($legacyHa['wake_time'])) {
            $general['wake_time'] = (string)$legacyHa['wake_time'];
        }
        if (!array_key_exists('sleep_time', $raw) && !empty($legacyHa['sleep_time'])) {
            $general['sleep_time'] = (string)$legacyHa['sleep_time'];
        }
    }

    return $general;
}

function module_config_path(string $module): string
{
    return HTDOCS_DIR . '/' . $module . '/config.json';
}

function normalize_duration_to_minutes($value): int
{
    return (int)round(((int)$value) / 60);
}

function normalize_duration_to_seconds($value): int
{
    return max(1, ((int)$value) * 60);
}

function prefs_to_ui(array $prefs): array
{
    foreach ($prefs['pages'] ?? [] as $key => $page) {
        $prefs['pages'][$key]['enabled'] = !array_key_exists('enabled', $page) || (bool)$page['enabled'];
        if (isset($page['duration'])) {
            $prefs['pages'][$key]['duration'] = normalize_duration_to_minutes($page['duration']);
        }
    }

    foreach ($prefs['timeslots'] ?? [] as $key => $slot) {
        if (isset($slot['duration'])) {
            $prefs['timeslots'][$key]['duration'] = normalize_duration_to_minutes($slot['duration']);
        }
    }

    return $prefs;
}

function validate_time(string $value): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function validate_prefs_payload(array $prefs): array
{
    if (!isset($prefs['pages']) || !is_array($prefs['pages']) || !isset($prefs['timeslots']) || !is_array($prefs['timeslots'])) {
        fail('Invalid PREFS structure');
    }

    foreach ($prefs['pages'] as $key => $page) {
        if (!is_array($page)) {
            fail("Invalid page payload for {$key}");
        }
        if (empty($page['url']) || !is_string($page['url'])) {
            fail("Missing url for {$key}");
        }
        if (!isset($page['chance']) || (int)$page['chance'] < 1 || (int)$page['chance'] > 10) {
            fail("Chance for {$key} must be between 1 and 10");
        }
        if (!array_key_exists('dynamic', $page)) {
            fail("Missing dynamic flag for {$key}");
        }
        if (!isset($page['duration']) || (int)$page['duration'] < 1) {
            fail("Duration for {$key} must be a positive number of minutes");
        }

        $prefs['pages'][$key]['chance'] = (int)$page['chance'];
        $prefs['pages'][$key]['dynamic'] = (bool)$page['dynamic'];
        $prefs['pages'][$key]['enabled'] = !array_key_exists('enabled', $page) || (bool)$page['enabled'];
        $prefs['pages'][$key]['duration'] = normalize_duration_to_seconds($page['duration']);
    }

    $enabledPages = array_filter($prefs['pages'], function ($page) {
        return !array_key_exists('enabled', $page) || (bool)$page['enabled'];
    });
    if (empty($enabledPages)) {
        fail('At least one module needs to stay enabled');
    }

    foreach ($prefs['timeslots'] as $key => $slot) {
        if (!is_array($slot)) {
            fail("Invalid timeslot payload for {$key}");
        }
        if (!isset($slot['day_time']) || !is_array($slot['day_time'])) {
            fail("Missing day_time for {$key}");
        }
        if (!isset($slot['duration']) || (int)$slot['duration'] < 1) {
            fail("Duration for {$key} must be a positive number of minutes");
        }
        if (!isset($slot['pages']) || !is_array($slot['pages'])) {
            fail("Missing pages for {$key}");
        }

        foreach ($slot['day_time'] as $day => $range) {
            if (!in_array($day, DAYS, true)) {
                fail("Invalid day key {$day} in {$key}");
            }
            if (!is_array($range) || !validate_time((string)($range['from'] ?? '')) || !validate_time((string)($range['till'] ?? ''))) {
                fail("Invalid time range for {$key}.{$day}");
            }
        }

        foreach ($slot['pages'] as $pageKey) {
            if (!array_key_exists($pageKey, $prefs['pages'])) {
                fail("Unknown page {$pageKey} in {$key}");
            }
        }

        $prefs['timeslots'][$key]['duration'] = normalize_duration_to_seconds($slot['duration']);
        $prefs['timeslots'][$key]['pages'] = array_values(array_unique(array_map('strval', $slot['pages'])));
    }

    return $prefs;
}

function validate_clock_config(array $config): array
{
    $enabled = $config['enabled_styles'] ?? CLOCK_STYLES;
    if (!is_array($enabled)) {
        fail('Clock styles must be an array');
    }
    $enabled = array_values(array_intersect(CLOCK_STYLES, array_map('strval', $enabled)));
    if (empty($enabled)) {
        $enabled = CLOCK_STYLES;
    }
    return ['enabled_styles' => $enabled];
}

function validate_comics_config(array $config): array
{
    $defaults = comics_defaults();
    $gapStrip = max(0, (int)($config['gap_strip'] ?? $defaults['gap_strip']));
    $gapMin = max(0, (int)($config['gap_min'] ?? $defaults['gap_min']));
    $gapMax = max($gapMin, (int)($config['gap_max'] ?? $defaults['gap_max']));
    $strips = $config['strips'] ?? $defaults['strips'];

    if (!is_array($strips)) {
        fail('Comics strips must be an array');
    }

    $clean = [];
    foreach ($strips as $idx => $strip) {
        if (!is_array($strip)) {
            fail("Invalid comics strip at index {$idx}");
        }
        $slug = trim((string)($strip['slug'] ?? ''));
        $type = trim((string)($strip['type'] ?? ''));
        if ($slug === '' || $type === '') {
            fail("Strip {$idx} is missing slug or type");
        }
        $clean[] = [
            'slug' => $slug,
            'label' => trim((string)($strip['label'] ?? ucwords(str_replace('-', ' ', $slug)))),
            'type' => $type,
            'enabled' => (bool)($strip['enabled'] ?? true),
            'order' => max(1, (int)($strip['order'] ?? ($idx + 1))),
            'fetch_mode' => in_array((string)($strip['fetch_mode'] ?? 'auto'), ['auto', 'upload', 'url'], true)
                ? (string)$strip['fetch_mode']
                : 'auto',
            'image_url' => trim((string)($strip['image_url'] ?? '')),
        ];
    }

    usort($clean, fn($a, $b) => $a['order'] <=> $b['order']);

    return [
        'gap_strip' => $gapStrip,
        'gap_min' => $gapMin,
        'gap_max' => $gapMax,
        'strips' => array_values($clean),
    ];
}

function validate_newspaper_config(array $config): array
{
    $clean = [];
    foreach ($config as $name => $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $prefix = trim((string)($paper['prefix'] ?? ''));
        if ($prefix === '') {
            continue;
        }
        $clean[(string)$name] = [
            'prefix' => $prefix,
            'style' => trim((string)($paper['style'] ?? 'width:99%;margin:-4.6rem 0 0 0')),
            'enabled' => !array_key_exists('enabled', $paper) || (bool)$paper['enabled'],
        ];
    }
    return $clean;
}

function fallback_newspapers(): array
{
    $existing = read_json(module_config_path('newspaper')) ?? [
        'NewYorkTimes' => ['prefix' => 'NY_NYT', 'style' => 'width:100%;margin:-70px 0 0 0'],
        'WallStreetJournal' => ['prefix' => 'WSJ', 'style' => 'width:99%;margin:-4.6rem 0 0 0'],
        'USAToday' => ['prefix' => 'USAT', 'style' => 'width:99%;margin:-4.6rem 0 0 0'],
    ];

    $papers = [];
    foreach ($existing as $name => $paper) {
        $papers[] = [
            'name' => preg_replace('/(?<!^)([A-Z])/', ' $1', $name),
            'prefix' => $paper['prefix'] ?? '',
            'style' => $paper['style'] ?? 'width:99%;margin:-4.6rem 0 0 0',
            'enabled' => !array_key_exists('enabled', $paper) || (bool)$paper['enabled'],
        ];
    }
    return $papers;
}

function validate_ainews_config(array $config): array
{
    $clean = $config;
    $clean['summary_words'] = max(20, (int)($config['summary_words'] ?? 60));
    $clean['kie_model'] = trim((string)($config['kie_model'] ?? 'google/nano-banana'));
    $providers = $config['provider_order'] ?? ['kie', 'gemini', 'pollinations', 'huggingface'];
    if (!is_array($providers)) {
        fail('AiNews provider order must be an array');
    }
    $allowedProviders = ['kie', 'gemini', 'pollinations', 'huggingface'];
    $providers = array_values(array_intersect($allowedProviders, array_map('strval', $providers)));
    if (empty($providers)) {
        $providers = $allowedProviders;
    }
    $clean['provider_order'] = $providers;

    $sources = $config['sources'] ?? [];
    if (!is_array($sources)) {
        fail('AiNews sources must be an array');
    }

    $cleanSources = [];
    foreach ($sources as $idx => $source) {
        if (!is_array($source)) {
            fail("Invalid AiNews source at index {$idx}");
        }
        $label = trim((string)($source['label'] ?? ''));
        $feed = trim((string)($source['feed'] ?? ''));
        if ($label === '' || $feed === '') {
            fail("AiNews source {$idx} needs label and feed");
        }
        $cleanSources[] = ['label' => $label, 'feed' => $feed];
    }
    $clean['sources'] = $cleanSources;

    return $clean;
}

function validate_ha_config(array $config): array
{
    $defaults = default_ha_config();
    $baseUrl = rtrim(trim((string)($config['base_url'] ?? $defaults['base_url'])), '/');
    $entityId = trim((string)($config['entity_id'] ?? $defaults['entity_id']));
    $homeState = trim((string)($config['home_state'] ?? $defaults['home_state']));
    $token = trim((string)($config['access_token'] ?? ''));
    $timeout = max(2, min(30, (int)($config['timeout'] ?? $defaults['timeout'])));

    if ($baseUrl === '' || !preg_match('/^https?:\/\//i', $baseUrl)) {
        fail('Home Assistant base URL must start with http:// or https://');
    }
    if ($entityId === '') {
        fail('Home Assistant entity ID is required');
    }
    if ($homeState === '') {
        fail('Home Assistant home state is required');
    }

    return [
        'enabled' => (bool)($config['enabled'] ?? false),
        'base_url' => $baseUrl,
        'entity_id' => $entityId,
        'home_state' => $homeState,
        'access_token' => $token,
        'timeout' => $timeout,
    ];
}

function validate_general_config(array $config): array
{
    $defaults = default_general_config();
    $width = (int)($config['frame_width'] ?? $defaults['frame_width']);
    $height = (int)($config['frame_height'] ?? $defaults['frame_height']);
    $sleepEnabled = (bool)($config['sleep_enabled'] ?? $defaults['sleep_enabled']);
    $wakeTime = trim((string)($config['wake_time'] ?? $defaults['wake_time']));
    $sleepTime = trim((string)($config['sleep_time'] ?? $defaults['sleep_time']));

    if ($width < 600 || $width > 4000) {
        fail('Frame width must be between 600 and 4000 pixels');
    }
    if ($height < 800 || $height > 5000) {
        fail('Frame height must be between 800 and 5000 pixels');
    }
    if (!validate_time($wakeTime)) {
        fail('Wake time must use HH:MM format');
    }
    if (!validate_time($sleepTime)) {
        fail('Sleep time must use HH:MM format');
    }

    return [
        'frame_width' => $width,
        'frame_height' => $height,
        'sleep_enabled' => $sleepEnabled,
        'wake_time' => $wakeTime,
        'sleep_time' => $sleepTime,
    ];
}

function decrypt_ainews_config(array $config): array
{
    return visionect_decrypt_fields($config, AINEWS_SECRET_FIELDS);
}

function encrypt_ainews_config(array $config): array
{
    return visionect_encrypt_fields($config, AINEWS_SECRET_FIELDS);
}

function encrypt_ha_config(array $config): array
{
    return visionect_encrypt_fields($config, HA_SECRET_FIELDS);
}

function gallery_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function starts_with(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function image_output_name(string $module): string
{
    return 'bw-' . $module . '-' . time() . '.jpg';
}

function list_gallery_files(string $module): array
{
    $files = [];
    foreach (gallery_extensions() as $ext) {
        $matches = glob(HTDOCS_DIR . '/' . $module . '/*.' . $ext) ?: [];
        foreach ($matches as $file) {
            $files[] = basename($file);
        }
    }
    natcasesort($files);
    return array_values(array_unique($files));
}

function process_uploaded_image(string $tmpFile, string $outFile): void
{
    $general = general_config_payload();
    $targetWidth = max(1, (int)($general['frame_width'] ?? 1440));
    $targetHeight = max(1, (int)($general['frame_height'] ?? 2560));
    ob_start();
    try {
        $img = new Imagick($tmpFile . '[0]');
        $img->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $img->thumbnailImage($targetWidth, $targetHeight, true, true);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(92);
        $img->writeImage($outFile);
        $img->destroy();
    } catch (Throwable $e) {
        ob_end_clean();
        fail('Image processing failed: ' . $e->getMessage(), 500);
    }
    ob_end_clean();
}

function comics_metadata_path(): string
{
    return HTDOCS_DIR . '/comics/metadata.json';
}

function comics_image_path(string $slug): string
{
    return HTDOCS_DIR . '/comics/' . $slug . '.jpg';
}

function comics_sanitize_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
        fail('Invalid comic slug');
    }
    return $slug;
}

function comics_config_payload(): array
{
    return read_json(module_config_path('comics')) ?? comics_defaults();
}

function comics_metadata_payload(): array
{
    return read_json(comics_metadata_path()) ?? ['farside' => [], 'strips' => [], 'sources' => []];
}

function comics_source_entry(array $metadata, array $strip): array
{
    $slug = (string)$strip['slug'];
    $existing = $metadata['sources'][$slug] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }

    return array_merge($existing, [
        'slug' => $slug,
        'label' => (string)($strip['label'] ?? $slug),
        'type' => (string)($strip['type'] ?? 'gocomics'),
        'fetch_mode' => (string)($strip['fetch_mode'] ?? 'auto'),
        'image_url' => (string)($strip['image_url'] ?? ''),
        'file' => $slug . '.jpg',
    ]);
}

function comics_refresh_metadata(?array $metadata = null): array
{
    $metadata = is_array($metadata) ? $metadata : comics_metadata_payload();
    $config = comics_config_payload();
    $strips = is_array($config['strips'] ?? null) ? $config['strips'] : [];

    $sourceMap = [];
    $stripRows = [];
    foreach ($strips as $strip) {
        if (!is_array($strip) || empty($strip['slug']) || empty($strip['type'])) {
            continue;
        }

        $entry = comics_source_entry($metadata, $strip);
        $path = comics_image_path((string)$strip['slug']);
        if (!file_exists($path) && empty($entry['status'])) {
            $entry['status'] = ($entry['fetch_mode'] ?? 'auto') === 'upload' ? 'missing' : 'missing';
            $entry['message'] = ($entry['fetch_mode'] ?? 'auto') === 'upload'
                ? 'Waiting for a manual strip upload'
                : 'No strip image saved yet';
        }

        $sourceMap[(string)$strip['slug']] = $entry;

        if (!file_exists($path)) {
            continue;
        }
        $info = @getimagesize($path);
        if (!$info) {
            continue;
        }
        $stripRows[] = [
            'slug' => (string)$strip['slug'],
            'label' => (string)($strip['label'] ?? $strip['slug']),
            'type' => (string)($strip['type'] ?? 'gocomics'),
            'file' => basename($path),
            'width' => $info[0],
            'height' => $info[1],
        ];
    }

    $metadata['sources'] = $sourceMap;
    $metadata['strips'] = $stripRows;
    $metadata['farside'] = is_array($metadata['farside'] ?? null) ? $metadata['farside'] : [];
    $metadata['updated'] = date('Y-m-d');
    $metadata['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    write_json(comics_metadata_path(), $metadata);
    return $metadata;
}

function comics_mark_manual_success(string $slug, string $message): void
{
    $metadata = comics_metadata_payload();
    $config = comics_config_payload();
    $strip = null;
    foreach (($config['strips'] ?? []) as $candidate) {
        if (is_array($candidate) && (string)($candidate['slug'] ?? '') === $slug) {
            $strip = $candidate;
            break;
        }
    }
    if (!$strip) {
        fail('Unknown comic strip');
    }

    $entry = comics_source_entry($metadata, $strip);
    $entry['status'] = ($entry['fetch_mode'] ?? 'auto') === 'auto' ? 'ok' : 'manual';
    $entry['message'] = $message;
    $entry['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['last_success_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['stale_since'] = null;
    $metadata['sources'][$slug] = $entry;
    comics_refresh_metadata($metadata);
}

function process_uploaded_image_blob(string $blob, string $outFile): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'visionect_img_');
    if ($tmp === false) {
        fail('Could not create temp file', 500);
    }
    file_put_contents($tmp, $blob);
    try {
        process_uploaded_image($tmp, $outFile);
    } finally {
        @unlink($tmp);
    }
}

function comics_preview_payload(): array
{
    $metadataPath = HTDOCS_DIR . '/comics/metadata.json';
    $metadata = comics_metadata_payload();
    $config = comics_config_payload();
    $warnings = [];
    $enabledMap = [];
    foreach (($config['strips'] ?? []) as $strip) {
        if (is_array($strip) && !empty($strip['slug'])) {
            $enabledMap[(string)$strip['slug']] = !array_key_exists('enabled', $strip) || (bool)$strip['enabled'];
        }
    }
    foreach (($metadata['sources'] ?? []) as $source) {
        if (!is_array($source) || empty($source['slug'])) {
            continue;
        }
        if (array_key_exists((string)$source['slug'], $enabledMap) && !$enabledMap[(string)$source['slug']]) {
            continue;
        }
        $status = (string)($source['status'] ?? '');
        if (!in_array($status, ['blocked', 'missing'], true)) {
            continue;
        }
        $warnings[] = [
            'slug' => (string)$source['slug'],
            'label' => (string)($source['label'] ?? $source['slug']),
            'message' => (string)($source['message'] ?? 'Source needs attention'),
            'stale_since' => $source['stale_since'] ?? null,
        ];
    }
    return [
        'farside' => $metadata['farside'] ?? [],
        'strips' => $metadata['strips'] ?? [],
        'sources' => $metadata['sources'] ?? [],
        'warnings' => $warnings,
        'config' => $config,
        'updated' => $metadata['updated'] ?? null,
        'updated_at' => file_exists($metadataPath) ? gmdate('Y-m-d\TH:i:s\Z', (int)filemtime($metadataPath)) : null,
    ];
}

function ainews_preview_payload(): array
{
    $dataPath = HTDOCS_DIR . '/ainews/data.json';
    $data = read_json($dataPath) ?? ['stories' => []];
    return [
        'stories' => $data['stories'] ?? [],
        'updated_at' => file_exists($dataPath) ? gmdate('Y-m-d\TH:i:s\Z', (int)filemtime($dataPath)) : null,
    ];
}

function newspaper_preview_payload(): array
{
    $config = read_json(module_config_path('newspaper')) ?? [];
    $papers = [];
    $latestMtime = 0;
    foreach ($config as $name => $paper) {
        $enabled = !array_key_exists('enabled', $paper) || (bool)$paper['enabled'];
        $prefix = trim((string)($paper['prefix'] ?? ''));
        if ($prefix === '') {
            continue;
        }
        $file = $prefix . '_latest.jpg';
        $path = HTDOCS_DIR . '/newspaper/' . $file;
        $papers[] = [
            'name' => preg_replace('/(?<!^)([A-Z])/', ' $1', (string)$name),
            'prefix' => $prefix,
            'style' => (string)($paper['style'] ?? ''),
            'enabled' => $enabled,
            'file' => file_exists($path) ? $file : null,
        ];
        if (file_exists($path)) {
            $latestMtime = max($latestMtime, (int)filemtime($path));
        }
    }
    return [
        'papers' => $papers,
        'updated_at' => $latestMtime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $latestMtime) : null,
    ];
}

function curl_fetch(string $url, int $timeout = 15): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'body' => $body !== false ? $body : null,
        'error' => $error ?: null,
        'status' => $status,
    ];
}

function ha_fetch_state(array $config): array
{
    $config = array_merge(default_ha_config(), $config);
    $url = $config['base_url'] . '/api/states/' . rawurlencode($config['entity_id']);
    $headers = ['Content-Type: application/json'];
    if ($config['access_token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $config['access_token'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => (int)$config['timeout'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Visionect Admin/1.0',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'message' => $error ?: 'Could not reach Home Assistant'];
    }
    if ($status >= 400) {
        return ['ok' => false, 'message' => 'Home Assistant returned HTTP ' . $status];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => 'Home Assistant returned invalid JSON'];
    }

    $stateValue = (string)($json['state'] ?? '');
    return [
        'ok' => true,
        'entity_id' => $config['entity_id'],
        'state' => $stateValue,
        'home_state' => (string)$config['home_state'],
        'is_home' => $stateValue === (string)$config['home_state'],
    ];
}

function get_status_snapshot(): array
{
    return visionect_read_runtime_status();
}

function module_cron_path(string $module): string
{
    return HTDOCS_DIR . '/' . $module . '/cron.php';
}

function php_cli_binary(): string
{
    $candidates = array_filter([
        defined('PHP_BINARY') ? PHP_BINARY : null,
        '/usr/local/bin/php',
        '/usr/bin/php',
        'php',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate === 'php') {
            return $candidate;
        }
        if (@is_file($candidate) && @is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function cron_lock_path(string $module): string
{
    return '/app/config/cron_' . preg_replace('/[^a-z0-9_\-]/i', '_', $module) . '.lock';
}

function run_module_cron(string $module): array
{
    if (!in_array($module, CRON_MODULES, true)) {
        fail('That module does not support manual cron runs');
    }

    $cronPath = module_cron_path($module);
    if (!file_exists($cronPath)) {
        fail('Cron script not found for module', 404);
    }

    $lockHandle = fopen(cron_lock_path($module), 'c+');
    if ($lockHandle === false) {
        fail('Could not create cron lock file', 500);
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        fail(ucfirst($module) . ' cron is already running', 409);
    }

    visionect_update_runtime_status([
        'cron' => [
            $module => [
                'running' => true,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_kind' => 'manual',
            ],
        ],
    ]);

    $command = 'cd ' . escapeshellarg(dirname($cronPath))
        . ' && ' . escapeshellarg(php_cli_binary()) . ' ' . escapeshellarg(basename($cronPath)) . ' 2>&1';
    $output = shell_exec($command);

    visionect_update_runtime_status([
        'cron' => [
            $module => [
                'running' => false,
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_kind' => 'manual',
                'last_output' => trim((string)$output),
            ],
        ],
    ]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    clearstatcache();

    return [
        'ok' => true,
        'module' => $module,
        'output' => trim((string)$output),
        'ran_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = body_json();

visionect_require_auth_json();
if ($method !== 'GET') {
    visionect_require_csrf_json();
}

switch ($action) {
    case 'status':
        respond(['ok' => true, 'status' => get_status_snapshot()]);

    case 'prefs':
        if ($method === 'GET') {
            $prefs = read_json(PREFS_FILE);
            if (!$prefs) {
                fail('Could not read PREFS.json', 500);
            }
            respond(prefs_to_ui($prefs));
        }

        if ($method === 'POST') {
            if (!is_array($body)) {
                fail('POST prefs expects JSON');
            }
            $prefs = validate_prefs_payload($body);
            write_json(PREFS_FILE, $prefs);
            respond(['ok' => true]);
        }
        break;

    case 'module_config':
        $module = (string)($_GET['module'] ?? '');
        if (!in_array($module, MODULES, true)) {
            fail('Unknown module');
        }

        $path = module_config_path($module);
        if ($method === 'GET') {
            $cfg = read_json($path);
            if ($cfg === null) {
                $cfg = default_module_config($module);
            }
            if ($module === 'ainews') {
                $cfg = decrypt_ainews_config($cfg);
            }
            respond($cfg);
        }

        if ($method === 'POST') {
            if (!is_array($body)) {
                fail('POST module_config expects JSON');
            }

            if ($module === 'clock') {
                $body = validate_clock_config($body);
            } elseif ($module === 'comics') {
                $body = validate_comics_config($body);
            } elseif ($module === 'newspaper') {
                $body = validate_newspaper_config($body);
            } elseif ($module === 'ainews') {
                $body = validate_ainews_config($body);
                $body = encrypt_ainews_config($body);
            }

            write_json($path, $body);
            respond(['ok' => true]);
        }
        break;

    case 'account':
        if ($method === 'GET') {
            respond(['username' => visionect_current_username()]);
        }

        if ($method === 'POST') {
            if (!is_array($body)) {
                fail('POST account expects JSON');
            }

            $account = visionect_account_record();
            if (!$account) {
                fail('Admin account is not configured', 500);
            }

            $currentPassword = (string)($body['current_password'] ?? '');
            $username = trim((string)($body['username'] ?? visionect_current_username()));
            $newPassword = (string)($body['new_password'] ?? '');

            if ($username === '') {
                fail('Username is required');
            }
            if ($currentPassword === '' || !password_verify($currentPassword, (string)($account['password_hash'] ?? ''))) {
                fail('Current password is incorrect', 403);
            }
            if ($newPassword !== '' && strlen($newPassword) < 12) {
                fail('New password must be at least 12 characters');
            }

            $next = $account;
            $next['username'] = $username;
            if ($newPassword !== '') {
                $next['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $next['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

            write_json(VISIONECT_ADMIN_ACCOUNT_FILE, $next);
            $_SESSION['visionect_username'] = $username;
            respond(['ok' => true, 'username' => $username]);
        }
        break;

    case 'ha_config':
        if ($method === 'GET') {
            respond(ha_config_payload());
        }

        if ($method === 'POST') {
            if (!is_array($body)) {
                fail('POST ha_config expects JSON');
            }
            $config = validate_ha_config($body);
            write_json(HA_CONFIG_FILE, encrypt_ha_config($config));
            respond(['ok' => true, 'config' => $config]);
        }
        break;

    case 'general_config':
        if ($method === 'GET') {
            respond(general_config_payload());
        }

        if ($method === 'POST') {
            if (!is_array($body)) {
                fail('POST general_config expects JSON');
            }
            $config = validate_general_config($body);
            write_json(GENERAL_CONFIG_FILE, $config);
            respond(['ok' => true, 'config' => $config]);
        }
        break;

    case 'ha_status':
        $config = ha_config_payload();
        if ($method === 'POST' && is_array($body)) {
            $config = validate_ha_config($body);
        }
        $result = ha_fetch_state($config);
        if (!$result['ok']) {
            fail($result['message'], 502);
        }
        respond($result);

    case 'gallery':
        $module = (string)($_GET['module'] ?? '');
        if (!in_array($module, GALLERY_MODULES, true)) {
            fail('Unknown gallery module');
        }
        respond(['files' => list_gallery_files($module)]);

    case 'comics_preview':
        respond(comics_preview_payload());

    case 'comics_upload_strip':
        if (!isset($_FILES['file'])) {
            fail('No file uploaded');
        }
        $slug = comics_sanitize_slug((string)($_POST['slug'] ?? $_GET['slug'] ?? ''));
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Upload failed with code ' . (string)$file['error']);
        }
        if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
            fail('File too large (max 15MB)');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            fail('Invalid image type');
        }

        process_uploaded_image($file['tmp_name'], comics_image_path($slug));
        comics_mark_manual_success($slug, 'Manual strip uploaded from admin');
        respond(['ok' => true, 'slug' => $slug, 'file' => basename(comics_image_path($slug))]);

    case 'comics_import_url':
        if ($method !== 'POST' || !is_array($body)) {
            fail('POST JSON required');
        }
        $slug = comics_sanitize_slug((string)($body['slug'] ?? ''));
        $url = trim((string)($body['url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $url)) {
            fail('Image URL must start with http:// or https://');
        }
        $result = curl_fetch($url, 30);
        if (!$result['body'] || $result['status'] >= 400) {
            fail('Could not fetch image URL', 502);
        }
        if (!@getimagesizefromstring($result['body'])) {
            fail('URL did not return a valid image');
        }

        process_uploaded_image_blob($result['body'], comics_image_path($slug));
        comics_mark_manual_success($slug, 'Imported strip from custom image URL');
        respond(['ok' => true, 'slug' => $slug, 'file' => basename(comics_image_path($slug))]);

    case 'comics_auth_status':
        $authFile = '/app/config/gocomics_auth.json';
        $auth = @json_decode(@file_get_contents($authFile), true);
        if (!is_array($auth) || empty($auth['cookies'])) {
            respond(['configured' => false, 'expired' => false, 'expiring_soon' => false,
                'expires_str' => '', 'refreshed_at' => '', 'source' => '']);
        }
        $exp = (int)($auth['expires_at'] ?? 0);
        $now = time();
        $expiresStr = $exp > 0 ? gmdate('Y-m-d H:i', $exp) . ' UTC' : 'unknown';
        respond([
            'configured'    => true,
            'expired'       => $exp > 0 && $exp < $now,
            'expiring_soon' => $exp > 0 && $exp > $now && $exp < $now + 259200,
            'expires_str'   => $expiresStr,
            'refreshed_at'  => (string)($auth['refreshed_at'] ?? ''),
            'source'        => (string)($auth['source'] ?? 'unknown'),
        ]);

    case 'comics_save_cookies':
        if ($method !== 'POST' || !is_array($body)) {
            fail('POST JSON required');
        }
        $raw = trim((string)($body['cookies'] ?? ''));
        if ($raw === '') {
            fail('No cookie data provided');
        }
        if (strpos($raw, 'bunny_shield') === false) {
            fail('Cookie data must contain bunny_shield');
        }
        // Parse Netscape format or plain name=value pairs into a cookie header string
        $lines = preg_split('/\r?\n/', $raw);
        $pairs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $cols = preg_split('/\t/', $line);
            if (count($cols) >= 7) {
                $name  = trim($cols[5]);
                $value = trim($cols[6]);
                if ($name !== '') {
                    $pairs[] = $name . '=' . $value;
                }
            } elseif (strpos($line, '=') !== false) {
                $pairs[] = $line;
            }
        }
        if (empty($pairs)) {
            fail('Could not parse cookie data. Use the Get cookies.txt Locally extension.');
        }
        $cookieStr = implode('; ', $pairs);
        // Extract bunny_shield expiry from cookie value
        $expiresAt = 0;
        if (preg_match('/bunny_shield=([^;]+)/', $cookieStr, $m)) {
            $parts = explode('#', $m[1]);
            if (isset($parts[2]) && ctype_digit(trim($parts[2]))) {
                $expiresAt = (int)trim($parts[2]);
            }
        }
        $auth = [
            'cookies'      => $cookieStr,
            'expires_at'   => $expiresAt,
            'refreshed_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'source'       => 'manual',
        ];
        $authFile = '/app/config/gocomics_auth.json';
        @mkdir(dirname($authFile), 0755, true);
        file_put_contents($authFile, json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $expiryMsg = $expiresAt > 0 ? ' Expires: ' . gmdate('Y-m-d H:i', $expiresAt) . ' UTC.' : '';
        respond(['ok' => true, 'message' => 'Cookies saved.' . $expiryMsg]);

    case 'ainews_preview':
        respond(ainews_preview_payload());

    case 'newspaper_preview':
        respond(newspaper_preview_payload());

    case 'run_module_cron':
        if ($method !== 'POST') {
            fail('POST required');
        }
        $module = (string)($_GET['module'] ?? ($body['module'] ?? ''));
        respond(run_module_cron($module));

    case 'upload':
        $module = (string)($_POST['module'] ?? $_GET['module'] ?? '');
        if (!in_array($module, GALLERY_MODULES, true)) {
            fail('Unknown gallery module');
        }
        if (!isset($_FILES['file'])) {
            fail('No file uploaded');
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Upload failed with code ' . (string)$file['error']);
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            fail('File too large (max 10MB)');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            fail('Invalid image type');
        }

        $outFile = HTDOCS_DIR . '/' . $module . '/' . image_output_name($module);
        process_uploaded_image($file['tmp_name'], $outFile);
        respond(['ok' => true, 'file' => basename($outFile)]);

    case 'delete_image':
        if ($method !== 'POST') {
            fail('POST required');
        }
        $module = (string)($body['module'] ?? $_GET['module'] ?? '');
        $file = (string)($body['file'] ?? $_GET['file'] ?? '');
        if (!in_array($module, GALLERY_MODULES, true)) {
            fail('Unknown gallery module');
        }
        if ($file === '' || strpos($file, '/') !== false || strpos($file, '..') !== false) {
            fail('Invalid filename');
        }
        $path = HTDOCS_DIR . '/' . $module . '/' . $file;
        if (!file_exists($path)) {
            fail('Image not found', 404);
        }
        unlink($path);
        respond(['ok' => true]);

    case 'validate_feed':
        if ($method !== 'POST' || !is_array($body)) {
            fail('POST JSON required');
        }
        $url = trim((string)($body['url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $url)) {
            fail('Feed URL must start with http or https');
        }

        $result = curl_fetch($url, 15);
        if (!$result['body'] || $result['status'] >= 400) {
            fail('Could not fetch feed', 502);
        }

        $xml = @simplexml_load_string($result['body']);
        if (!$xml || !isset($xml->channel->item[0])) {
            fail('Feed must be RSS with a channel/item structure');
        }

        respond(['ok' => true]);

    case 'newspapers':
        $result = curl_fetch('https://www.freedomforum.org/todaysfrontpages/', 20);
        if (!$result['body']) {
            fail('Could not reach freedomforum.org', 502);
        }

        preg_match_all('/cdn\.freedomforum\.org\/dfp\/pdf\d+\/([A-Z0-9_]+)\.pdf/i', $result['body'], $matches);
        $prefixes = array_values(array_unique($matches[1] ?? []));

        $papers = [];
        foreach ($prefixes as $prefix) {
            $name = $prefix;
            if (preg_match('/([A-Z][A-Za-z]+(?:[A-Z][A-Za-z]+)+)/', $prefix, $m)) {
                $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $m[1]);
            }
            $papers[] = [
                'name' => trim(str_replace('_', ' ', $name)),
                'prefix' => $prefix,
                'style' => starts_with($prefix, 'NY_') ? 'width:100%;margin:-70px 0 0 0' : 'width:99%;margin:-4.6rem 0 0 0',
            ];
        }

        if (empty($papers)) {
            $papers = fallback_newspapers();
        }

        usort($papers, fn($a, $b) => strcmp($a['name'], $b['name']));
        respond(['papers' => $papers]);

    case 'restart':
        shell_exec('(sleep 1 && kill 1) > /dev/null 2>&1 &');
        respond(['ok' => true]);
}

fail('Unknown action', 404);
