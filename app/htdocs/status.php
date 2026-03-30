<?php
$securityHelper = file_exists('/app/lib/security.php') ? '/app/lib/security.php' : __DIR__ . '/lib/security.php';
require_once $securityHelper;
visionect_send_no_cache_headers();

if (isset($_GET['ws_token'])) {
	header('Content-Type: application/json');
	echo json_encode(array(
		'token' => visionect_issue_websocket_token('display', 86400),
	), JSON_UNESCAPED_SLASHES);
	return;
}

$defaults = array(
	'enabled' => false,
	'base_url' => 'http://homeassistant.local:8123',
	'entity_id' => 'device_tracker.someone_phone',
	'home_state' => 'home',
	'access_token' => '',
	'timeout' => 10,
);
$generalDefaults = array(
	'sleep_enabled' => false,
	'wake_time' => '08:00',
	'sleep_time' => '23:00',
);

$configPath = VISIONECT_CONFIG_DIR . '/ha_integration.json';
$generalConfigPath = VISIONECT_CONFIG_DIR . '/general_settings.json';
$config = $defaults;
if (file_exists($configPath)) {
	$json = json_decode(file_get_contents($configPath), true);
	if (is_array($json)) {
		$config = visionect_decrypt_fields(array_merge($defaults, $json), array('access_token'));
	}
}
$generalRaw = array();
$generalConfig = $generalDefaults;
if (file_exists($generalConfigPath)) {
	$json = json_decode(file_get_contents($generalConfigPath), true);
	if (is_array($json)) {
		$generalRaw = $json;
		$generalConfig = array_merge($generalDefaults, $json);
	}
}
if (array_key_exists('sleep_enabled', $config) && !array_key_exists('sleep_enabled', $generalRaw)) {
	$generalConfig['sleep_enabled'] = (bool)$config['sleep_enabled'];
}
if (!array_key_exists('wake_time', $generalRaw) && !empty($config['wake_time'])) {
	$generalConfig['wake_time'] = (string)$config['wake_time'];
}
if (!array_key_exists('sleep_time', $generalRaw) && !empty($config['sleep_time'])) {
	$generalConfig['sleep_time'] = (string)$config['sleep_time'];
}

if (!empty($generalConfig['sleep_enabled'])) {
	$hm = strftime('%H:%M');
	$wake = (string)$generalConfig['wake_time'];
	$sleep = (string)$generalConfig['sleep_time'];
	$isSleeping = false;
	if ($wake === $sleep) {
		$isSleeping = false;
	} elseif ($sleep > $wake) {
		$isSleeping = ($hm >= $sleep || $hm < $wake);
	} else {
		$isSleeping = ($hm >= $sleep && $hm < $wake);
	}
	if ($isSleeping) {
		echo 'sleep';
		return 'sleep';
	}
}

if (empty($config['enabled'])) {
	echo 'home';
	return 'home';
}

$url = rtrim($config['base_url'], '/') . '/api/states/' . rawurlencode($config['entity_id']);
$headers = array('Content-Type: application/json');
if (!empty($config['access_token'])) {
	$headers[] = 'Authorization: Bearer ' . $config['access_token'];
}

$ch = curl_init($url);
curl_setopt_array($ch, array(
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_TIMEOUT => (int)$config['timeout'],
	CURLOPT_HTTPHEADER => $headers,
	CURLOPT_USERAGENT => 'Visionect Status/1.0',
));
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response !== false && $status < 400) {
	$json = json_decode($response, true);
	if (is_array($json) && array_key_exists('state', $json)) {
		if ((string)$json['state'] === (string)$config['home_state']) {
			echo 'home';
			return 'home';
		}
		echo 'away';
		return 'away';
	}
}

echo 'home';
return 'home';
