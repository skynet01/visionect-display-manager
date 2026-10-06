<?php
/**
 * Shared helpers for the admin (index.php + api.php). Functions only; no output.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : __DIR__ . '/../../lib/security.php';
require_once $securityHelper;

const HA_CONFIG_FILE = '/app/config/ha_integration.json';
const GENERAL_CONFIG_FILE = '/app/config/general_settings.json';

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

/**
 * General settings merged over defaults. Older installs kept the sleep window in
 * ha_integration.json; those values are used only when general_settings.json lacks them.
 */
function general_config_payload(): array
{
    $config = visionect_read_json_file(GENERAL_CONFIG_FILE);
    $raw = is_array($config) ? $config : [];
    $general = array_merge(default_general_config(), $raw);

    $legacyHa = visionect_read_json_file(HA_CONFIG_FILE) ?? [];
    if (!array_key_exists('sleep_enabled', $raw) && array_key_exists('sleep_enabled', $legacyHa)) {
        $general['sleep_enabled'] = (bool)$legacyHa['sleep_enabled'];
    }
    if (!array_key_exists('wake_time', $raw) && !empty($legacyHa['wake_time'])) {
        $general['wake_time'] = (string)$legacyHa['wake_time'];
    }
    if (!array_key_exists('sleep_time', $raw) && !empty($legacyHa['sleep_time'])) {
        $general['sleep_time'] = (string)$legacyHa['sleep_time'];
    }

    return $general;
}
