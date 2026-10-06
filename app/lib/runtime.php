<?php
/**
 * Runtime helpers shared by cli/visionectd.php, htdocs/status.php and htdocs/control.php:
 * Home Assistant presence, general settings (with the legacy sleep-window fallback),
 * the frame activity (home / away / sleep) and the optional control.php token.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/http.php';

const VISIONECT_HA_CONFIG_FILE = VISIONECT_CONFIG_DIR . '/ha_integration.json';
const VISIONECT_GENERAL_CONFIG_FILE = VISIONECT_CONFIG_DIR . '/general_settings.json';
const VISIONECT_HA_CONNECT_TIMEOUT = 2;
const VISIONECT_HA_TOTAL_TIMEOUT = 3;

function visionect_ha_config_defaults(): array
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

/** HA config with the token decrypted. Server-side only. */
function visionect_ha_config(): array
{
    $config = visionect_read_json_file(VISIONECT_HA_CONFIG_FILE);
    $merged = array_merge(visionect_ha_config_defaults(), is_array($config) ? $config : []);
    return visionect_decrypt_fields($merged, ['access_token']);
}

function visionect_general_config_defaults(): array
{
    return [
        'frame_width' => 1440,
        'frame_height' => 2560,
        'sleep_enabled' => false,
        'wake_time' => '08:00',
        'sleep_time' => '23:00',
    ];
}

/**
 * general_settings.json over defaults. Older installs kept the sleep window in
 * ha_integration.json; those values apply only when general_settings.json lacks them.
 * Pass $haConfig to avoid re-reading the HA file.
 */
function visionect_general_config(?array $haConfig = null): array
{
    $config = visionect_read_json_file(VISIONECT_GENERAL_CONFIG_FILE);
    $raw = is_array($config) ? $config : [];
    $general = array_merge(visionect_general_config_defaults(), $raw);

    $legacy = $haConfig ?? (visionect_read_json_file(VISIONECT_HA_CONFIG_FILE) ?? []);
    if (!array_key_exists('sleep_enabled', $raw) && array_key_exists('sleep_enabled', $legacy)) {
        $general['sleep_enabled'] = (bool)$legacy['sleep_enabled'];
    }
    if (!array_key_exists('wake_time', $raw) && !empty($legacy['wake_time'])) {
        $general['wake_time'] = (string)$legacy['wake_time'];
    }
    if (!array_key_exists('sleep_time', $raw) && !empty($legacy['sleep_time'])) {
        $general['sleep_time'] = (string)$legacy['sleep_time'];
    }

    return $general;
}

/** True when $hm ("HH:MM", default now) is inside the mirrored frame sleep window. */
function visionect_is_sleep_window(array $general, ?string $hm = null): bool
{
    if (empty($general['sleep_enabled'])) {
        return false;
    }

    $hm = $hm ?? date('H:i');
    $wake = (string)($general['wake_time'] ?? '');
    $sleep = (string)($general['sleep_time'] ?? '');
    if ($wake === '' || $sleep === '' || $wake === $sleep) {
        return false;
    }
    if ($sleep > $wake) {
        return $hm >= $sleep || $hm < $wake;
    }
    return $hm >= $sleep && $hm < $wake;
}

/**
 * Presence from Home Assistant: 'home', 'away', or null when HA is disabled or unreachable.
 * Short timeouts (2s connect, 3s total) because visionectd calls this inside its event loop.
 */
function visionect_ha_presence(array $haConfig): ?string
{
    if (empty($haConfig['enabled'])) {
        return null;
    }

    $url = rtrim((string)$haConfig['base_url'], '/') . '/api/states/' . rawurlencode((string)$haConfig['entity_id']);
    $headers = ['Content-Type: application/json'];
    if ((string)($haConfig['access_token'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . $haConfig['access_token'];
    }

    $result = visionect_http_get($url, VISIONECT_HA_TOTAL_TIMEOUT, $headers, [
        CURLOPT_CONNECTTIMEOUT => VISIONECT_HA_CONNECT_TIMEOUT,
        CURLOPT_USERAGENT => 'Visionect Runtime/1.0',
    ]);
    if (!$result['ok']) {
        return null;
    }

    $json = json_decode((string)$result['body'], true);
    if (!is_array($json) || !array_key_exists('state', $json)) {
        return null;
    }

    return ((string)$json['state'] === (string)$haConfig['home_state']) ? 'home' : 'away';
}

/**
 * Frame activity: 'sleep' inside the sleep window, else 'away' when HA says so, else 'home'
 * (HA disabled or down falls back to home). Config is read once per call.
 */
function visionect_compute_activity(): string
{
    $ha = visionect_ha_config();
    if (visionect_is_sleep_window(visionect_general_config($ha))) {
        return 'sleep';
    }

    return visionect_ha_presence($ha) === 'away' ? 'away' : 'home';
}

/** True when general settings hold a control.php token (stored encrypted). */
function visionect_control_token_required(): bool
{
    $general = visionect_read_json_file(VISIONECT_GENERAL_CONFIG_FILE) ?? [];
    return trim((string)($general['control_token'] ?? '')) !== '';
}

/**
 * Check a caller-supplied control.php token. No stored token: always true (open, as before).
 * A stored token that no longer decrypts fails closed.
 */
function visionect_control_token_ok(?string $provided): bool
{
    $general = visionect_read_json_file(VISIONECT_GENERAL_CONFIG_FILE) ?? [];
    $stored = trim((string)($general['control_token'] ?? ''));
    if ($stored === '') {
        return true;
    }

    $expected = trim(visionect_decrypt_secret($stored));
    $provided = trim((string)$provided);
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}
