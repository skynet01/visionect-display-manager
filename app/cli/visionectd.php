<?php

if (isset($_SERVER["REMOTE_ADDR"]) || isset($_SERVER["HTTP_USER_AGENT"]) || !isset($_SERVER["argv"])) {
	exit('Please run this from the command line');
}

require '../vendor/autoload.php';
require '../lib/security.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class DisplayServer implements MessageComponentInterface {
	private $debug = true; // Debug mode
	const HA_CONFIG_FILE = '../config/ha_integration.json';
	protected $clients;
	protected $PREFS;
	protected $curPage = null;
	protected $curActivity = 'away';
	protected $curTimeslot = null;
	protected $paused = false;
	protected $nextPageKey = null;
	protected $manualOverride = null;
	const MANUAL_OVERRIDE_SECONDS = 1800;

	public function __construct() {
		$this->clients = new \SplObjectStorage;
		if ($this->debug) { print "-> Display server started.\n"; }

		$this->PREFS = $this->loadPrefs();
		$this->curActivity = $this->getActivity();
		$this->curTimeslot = $this->getTimeslot();
		$this->curPage = $this->getNextPage();
		$this->nextPageKey = $this->previewNextPageKey($this->curPage['key'] ?? '_');
		$this->writeRuntimeStatus();
	}

	protected function getStatus() {
			return array('status'=>array(
				'paused'=>$this->paused,
				'curPage'=>$this->curPage['key'],
				'endTime'=>$this->curPage['endTime'],
				'timeslot'=>$this->curTimeslot,
				'activity'=>$this->curActivity,
				'nextPage'=>$this->nextPageKey,
				'manualOverride'=>$this->manualOverride,
			));
	}

	private function isPageEnabled($pageKey) {
		return array_key_exists($pageKey, $this->PREFS['pages']) && (!array_key_exists('enabled', $this->PREFS['pages'][$pageKey]) || $this->PREFS['pages'][$pageKey]['enabled']);
	}

	private function resolvePageDuration($pageKey) {
		if ($this->curTimeslot !== null && array_key_exists($this->curTimeslot, $this->PREFS['timeslots'])) {
			$slot = $this->PREFS['timeslots'][$this->curTimeslot];
			if (in_array($pageKey, $slot['pages'], true) && array_key_exists('duration', $slot)) {
				return (int)$slot['duration'];
			}
		}

		return (int)$this->PREFS['pages'][$pageKey]['duration'];
	}

	private function isPageAllowed($pageKey) {
		if (!$this->isPageEnabled($pageKey)) {
			return false;
		}

		if ($this->curTimeslot === null || !array_key_exists($this->curTimeslot, $this->PREFS['timeslots'])) {
			return true;
		}

		return in_array($pageKey, $this->PREFS['timeslots'][$this->curTimeslot]['pages'], true);
	}

	private function setCurrentPage($pageKey) {
		$page = $this->PREFS['pages'][$pageKey];
		$duration = $this->resolvePageDuration($pageKey);
		$this->curPage = array(
			'key' => $pageKey,
			'duration' => $duration,
			'endTime' => time() + $duration,
			'url' => $page['url'],
		);
	}

	private function fallbackPage() {
		if ($this->curPage !== null && array_key_exists($this->curPage['key'], $this->PREFS['pages']) && $this->isPageAllowed($this->curPage['key'])) {
			$this->setCurrentPage($this->curPage['key']);
			return $this->curPage;
		}

		$pageKeys = array_values(array_filter(array_keys($this->PREFS['pages']), function($pageKey) {
			return $this->isPageEnabled($pageKey);
		}));
		if (!empty($pageKeys)) {
			$this->setCurrentPage($pageKeys[0]);
			return $this->curPage;
		}

		return array(
			'key' => 'none',
			'duration' => 60,
			'endTime' => time() + 60,
			'url' => ''
		);
	}

	private function loadPrefs() {
		$prefs = visionect_read_json_file('../config/PREFS.json');
		if (!is_array($prefs)) {
			if ($this->debug) { print "!! PREFS.json missing or invalid, using empty defaults.\n"; }
			return array('pages' => array(), 'timeslots' => array());
		}

		if (!isset($prefs['pages']) || !is_array($prefs['pages'])) {
			$prefs['pages'] = array();
		}
		if (!isset($prefs['timeslots']) || !is_array($prefs['timeslots'])) {
			$prefs['timeslots'] = array();
		}

		return $prefs;
	}

	private function writeRuntimeStatus() {
		visionect_update_runtime_status(array(
			'display' => array(
				'paused' => $this->paused,
				'curPage' => $this->curPage['key'] ?? null,
				'nextPage' => $this->nextPageKey,
				'endTime' => $this->curPage['endTime'] ?? time(),
				'timeslot' => $this->curTimeslot,
				'activity' => $this->curActivity,
				'manual_override' => $this->manualOverride,
				'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			),
		));
	}

	private function startManualOverride($pageKey) {
		$expiresAt = time() + self::MANUAL_OVERRIDE_SECONDS;
		$this->manualOverride = array(
			'page' => $pageKey,
			'expires_at' => $expiresAt,
		);
		$this->curPage['endTime'] = $expiresAt;
	}

	private function clearManualOverride() {
		$this->manualOverride = null;
	}

	private function revertToScheduleNow($timestamp, $sourceLabel = 'Returned to scheduled rotation') {
		$this->clearManualOverride();
		$this->curTimeslot = $this->getTimeslot();
		$this->curPage = $this->getNextPage('_');
		$this->nextPageKey = $this->previewNextPageKey($this->curPage['key'] ?? '_');
		print "!! {$timestamp} | {$sourceLabel}\n";
		$this->broadcastState(true);
	}

	private function broadcastState($sendUrl = true) {
		$this->writeRuntimeStatus();
		foreach ($this->clients as $client) {
			if ($sendUrl) {
				$client->send(json_encode(array('url'=>$this->curPage['url'])));
			}
			$client->send(json_encode($this->getStatus()));
		}
	}

	private function applyRemoteCommand(array $command) {
		$timestamp=date('m/d/Y h:i:s a', time());
		$task = (string)($command['task'] ?? '');
		if ($task === '') {
			return false;
		}

		switch ($task) {
			case 'pause':
				$this->paused = true;
				print "!! {$timestamp} | Remote control paused display\n";
				$this->writeRuntimeStatus();
				return true;

			case 'unpause':
				$this->paused = false;
				print "!! {$timestamp} | Remote control resumed display\n";
				$this->writeRuntimeStatus();
				return true;

			case 'reloadPrefs':
				$this->PREFS = $this->loadPrefs();
				$this->syncPrefsState();
				print "!! {$timestamp} | Remote control reloaded PREFS\n";
				$this->broadcastState(true);
				return true;

			case 'resumeSchedule':
				$this->revertToScheduleNow($timestamp, 'Remote control returned to schedule');
				return true;

			case 'reloadCurrent':
				if (!empty($this->curPage['key']) && array_key_exists($this->curPage['key'], $this->PREFS['pages']) && $this->isPageEnabled($this->curPage['key'])) {
					$this->setCurrentPage($this->curPage['key']);
					$this->nextPageKey = $this->previewNextPageKey($this->curPage['key']);
					print "!! {$timestamp} | Remote control reloaded {$this->curPage['key']}\n";
					$this->broadcastState(true);
					return true;
				}
				return false;

			case 'setPage':
				$page = (string)($command['page'] ?? '');
				if ($page !== '' && array_key_exists($page, $this->PREFS['pages']) && $this->isPageEnabled($page)) {
					$this->setCurrentPage($page);
					$this->startManualOverride($page);
					$this->nextPageKey = $this->previewNextPageKey($page);
					print "!! {$timestamp} | Remote control jumped to {$page}\n";
					$this->broadcastState(true);
					return true;
				}
				return false;
		}

		return false;
	}

	private function pollRemoteControl() {
		$command = visionect_take_remote_control();
		if (!is_array($command)) {
			return;
		}

		$this->applyRemoteCommand($command);
	}

	private function syncPrefsState() {
		$previousTimeslot = $this->curTimeslot;
		$this->curTimeslot = $this->getTimeslot();
		$currentKey = $this->curPage['key'];

		if (!$currentKey || !$this->isPageAllowed($currentKey) || $previousTimeslot !== $this->curTimeslot) {
			$this->clearManualOverride();
			$this->curPage = $this->getNextPage('_');
			$this->nextPageKey = $this->previewNextPageKey($this->curPage['key'] ?? '_');
			return true;
		}

		$this->setCurrentPage($currentKey);
		$this->nextPageKey = $this->previewNextPageKey($currentKey);
		return true;
	}

	private function isAuthorizedClient(ConnectionInterface $conn) {
		$meta = $this->clients->contains($conn) ? $this->clients[$conn] : null;
		return is_array($meta) && !empty($meta['auth']);
	}

	public function onOpen(ConnectionInterface $conn) {
		$query = array();
		parse_str($conn->httpRequest->getUri()->getQuery(), $query);
		$claims = visionect_validate_websocket_token((string)($query['token'] ?? ''));
		$auth = is_array($claims) && !empty($claims['sub']);
		$this->clients->attach($conn, array(
			'auth' => $auth,
			'sub' => $auth ? (string)$claims['sub'] : 'display',
		));
		if ($this->debug) {
			$clientType = $auth ? 'authenticated' : 'display';
			print "-> Client connected: {$conn->resourceId} ({$clientType})\n";
		}
		$conn->send(json_encode(array('url'=>$this->curPage['url'])));
		$conn->send(json_encode($this->getStatus()));
	}

	public function onClose(ConnectionInterface $conn) {
		$this->clients->detach($conn);
		if ($this->debug) { print "-> Client disconnected: {$conn->resourceId}\n";  }
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		$conn->close();
		if ($this->debug) { print "-> Client error: {$conn->resourceId} | {$e->getMessage()}\n";  }
	}

	//do things if admin requests page switch or pause
	public function onMessage(ConnectionInterface $from, $msg) {
		$timestamp=date('m/d/Y h:i:s a', time());
		if ($this->debug) { print "-> {$timestamp} | Received message from {$from->resourceId}: {$msg}\n"; }
		if ($msg == 'status') {
			$from->send('STATUS UPDATE!');
			return;
		}
		$json = @json_decode($msg, true);
		if ($json) {
			if (array_key_exists('task', $json)) {
				$requiresAuth = in_array($json['task'], array('pause', 'unpause', 'setPage', 'reloadPrefs', 'resumeSchedule', 'refreshActivity'), true);
				if ($requiresAuth && !$this->isAuthorizedClient($from)) {
					if ($this->debug) { print "!! {$timestamp} | Ignored unauthenticated task {$json['task']} from {$from->resourceId}\n"; }
					return;
				}
				switch($json['task']) {
					case 'getStatus':
						// Return status to requesting client only
						//$from->send(json_encode($this->getStatus()));
						break;

					case 'pause':
						print "!! {$timestamp} | PAUSED\n";
						$this->paused = true;
						$this->writeRuntimeStatus();
						break;

					case 'unpause':
						print "!! {$timestamp} | UNPAUSED\n";
						$this->paused = false;
						$this->writeRuntimeStatus();
						break;

					case 'setPage':
						if (array_key_exists('page', $json) && array_key_exists($json['page'], $this->PREFS['pages']) && $this->isPageEnabled($json['page'])) {
							$this->setCurrentPage($json['page']);
							$this->startManualOverride($json['page']);
							$this->nextPageKey = $this->previewNextPageKey($json['page']);
							$this->broadcastState(true);
						}
						break;

					case 'reloadPrefs':
						$this->PREFS = $this->loadPrefs();
						$this->syncPrefsState();
						print "!! {$timestamp} | PREFS reloaded\n";
						$this->broadcastState(true);
						break;

					case 'resumeSchedule':
						$this->revertToScheduleNow($timestamp);
						break;

					case 'refreshActivity':
						$prevActivity = $this->curActivity;
						$this->curActivity = $this->getActivity();
						if ($prevActivity != $this->curActivity) {
							print "!! {$timestamp} | Activity refreshed from {$prevActivity} to {$this->curActivity}\n";
							if (!$this->manualOverride) {
								$this->curPage['endTime'] = 0;
							}
						}
						$this->writeRuntimeStatus();
						$this->broadcastState(false);
						break;
				}
				// Return status to requesting client:
				$from->send(json_encode($this->getStatus()));
			}
		}
	}

	//get status update from Home Assistant presense sensor, pause if away
	private function getHaConfig() {
		$defaults = array(
			'enabled' => false,
			'base_url' => 'http://homeassistant.local:8123',
			'entity_id' => 'device_tracker.someone_phone',
			'home_state' => 'home',
			'access_token' => '',
			'timeout' => 10,
		);

		if (!file_exists(self::HA_CONFIG_FILE)) {
			return $defaults;
		}

		$config = visionect_read_json_file(self::HA_CONFIG_FILE);
		if (!is_array($config)) {
			return $defaults;
		}

		return visionect_decrypt_fields(array_merge($defaults, $config), array('access_token'));
	}

	private function getGeneralConfig() {
		$defaults = array(
			'frame_width' => 1440,
			'frame_height' => 2560,
			'sleep_enabled' => false,
			'wake_time' => '08:00',
			'sleep_time' => '23:00',
		);

		$path = '/app/config/general_settings.json';
		if (!file_exists($path)) {
			return $defaults;
		}

		$config = visionect_read_json_file($path);
		$general = $defaults;
		$raw = array();
		if (is_array($config)) {
			$raw = $config;
			$general = array_merge($defaults, $config);
		}

		$legacy = $this->getHaConfig();
		if (array_key_exists('sleep_enabled', $legacy) && !array_key_exists('sleep_enabled', $raw)) {
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

	private function getActivity() {
		$general = $this->getGeneralConfig();
		if (!empty($general['sleep_enabled'])) {
			$hm = strftime('%H:%M');
			$wake = (string)$general['wake_time'];
			$sleep = (string)$general['sleep_time'];
			$isSleeping = false;
			if ($wake === $sleep) {
				$isSleeping = false;
			} elseif ($sleep > $wake) {
				$isSleeping = ($hm >= $sleep || $hm < $wake);
			} else {
				$isSleeping = ($hm >= $sleep && $hm < $wake);
			}
			if ($isSleeping) {
				return 'sleep';
			}
		}

		$config = $this->getHaConfig();
		if (empty($config['enabled'])) {
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
			CURLOPT_USERAGENT => 'Visionect DisplayServer/1.0',
		));
		$response = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response !== false && $status < 400) {
			$json = json_decode($response, true);
			if (is_array($json) && array_key_exists('state', $json)) {
				return ((string)$json['state'] === (string)$config['home_state']) ? 'home' : 'away';
			}
		}
		return 'home';  //falls back to home if HA is down
	}

	/**
	 * Return name of current timeslot (ex: 'breakfast', 'dinner').
	 * Returns NULL when no timeslot is defined for now.
	 */
	private function getTimeslot() {
		$timeslots = $this->PREFS['timeslots'] ?? array();
		$day = strtolower(date('D'));
		$hm = strftime('%H:%M');

		foreach($timeslots as $timeslotName=>$timeslot) {
			foreach($timeslot['day_time'] as $_day=>$day_time) {
				if ($_day == $day && $hm >= $day_time['from'] && $hm < $day_time['till']) {
					return $timeslotName;

				}
			}
		}

		return null;
	}

	private function getNextPage($currentPageKey = '_') {
		$timeslots = $this->PREFS['timeslots'];
		$pages = $this->PREFS['pages'];

		$hm = strftime('%H:%M');
		//$hm = '08:00';

		$pagesToChooseFrom = array_values(array_filter(array_keys($pages), function($pageKey) {
			return $this->isPageEnabled($pageKey);
		}));
		$duration = ($currentPageKey == '_') ? 1 : null;

		if (null !== $this->curTimeslot) {
			$pagesToChooseFrom = array_values(array_filter($timeslots[$this->curTimeslot]['pages'], function($pageKey) {
				return $this->isPageEnabled($pageKey);
			}));
			$duration = array_key_exists('duration', $timeslots[$this->curTimeslot]) ? $timeslots[$this->curTimeslot]['duration'] : $duration;
		}

		if (empty($pagesToChooseFrom)) {
			$pagesToChooseFrom = array_values(array_filter(array_keys($pages), function($pageKey) {
				return $this->isPageEnabled($pageKey);
			}));
		}

		if (empty($pagesToChooseFrom)) {
			if ($this->debug) {
				print "!! No enabled pages are available for rotation, falling back to current page.\n";
			}
			return $this->fallbackPage();
		}

		if ($currentPageKey != '_' && !$pages[$currentPageKey]['dynamic']) {
			$pagesToChooseFrom = array_filter($pagesToChooseFrom, fn($m) => $m != $currentPageKey);
			if (empty($pagesToChooseFrom)) {
				$pagesToChooseFrom = array($currentPageKey);
			}
		}

		$pagesChanced = array();
		foreach ($pagesToChooseFrom as $page) {
			for ($i = 0; $i < $pages[$page]['chance']; $i++) {
				$pagesChanced[] = $page;
			}
		}

		$rnd = array_rand($pagesChanced, 1);
		$currentPageKey = $pagesChanced[$rnd];

		$page = $pages[$currentPageKey];
		if (null === $duration) {
			$duration = $page['duration'];
		}

		//$duration = 5; // TEMP

		return array(
			'key' => $currentPageKey,
			'duration' => $duration,
			'endTime' => time() + $duration,
			'url' => $page['url']
		);
	}

	private function previewNextPageKey($currentPageKey = '_') {
		$preview = $this->getNextPage($currentPageKey);
		return $preview['key'] ?? null;
	}

	// Called every second
	public function onSecond() {
		$this->pollRemoteControl();
		if ($this->manualOverride && $this->paused) {
			if (date('s') == '00') {
				$this->onMinute();
			}
			return;
		}
		if ($this->manualOverride && !$this->paused) {
			$expiresAt = (int)($this->manualOverride['expires_at'] ?? 0);
			if ($expiresAt > 0 && time() >= $expiresAt) {
				$timestamp=date('m/d/Y h:i:s a', time());
				$this->revertToScheduleNow($timestamp, 'Manual override expired, returning to schedule');
				return;
			}
		}
		if (time() >= $this->curPage['endTime']) {
			// Get next page:
			$this->clearManualOverride();
			$this->curPage = $this->getNextPage($this->curPage['key']);
			$this->nextPageKey = $this->previewNextPageKey($this->curPage['key']);
			$this->writeRuntimeStatus();

			if ($this->curActivity != 'sleep' && $this->curActivity != 'away' && !$this->paused) {
				$timestamp=date('m/d/Y h:i:s a', time());
				if ($this->debug) {
					print "-> {$timestamp} | Navigating to {$this->curPage['key']} ({$this->curPage['url']}), {$this->curPage['duration']} seconds. Time slot is {$this->curTimeslot}\n";
				}
				foreach ($this->clients as $client) {
					if ($this->curPage['url'] !== '') {
						$client->send(json_encode(array('url'=>$this->curPage['url'])));
					}
				}
			}
		}

		if (date('s') == '00') {
			$this->onMinute();
		}
	}

	// Called every minute
	public function onMinute() {

		$prevTimeslot = $this->curTimeslot;
		//if ($prevTimeslot == null){ $prevTimeslot = "NONE";} //added 4/22/23 so log looks nicer
		$this->curTimeslot = $this->getTimeslot();
		//if ($this->curTimeslot == null){ $this->curTimeslot = "NONE";} //added 4/22/23 so log looks nicer

		if ($prevTimeslot != $this->curTimeslot) {
			// Timeslot changed.
			$timestamp=date('m/d/Y h:i:s a', time());
			if ($this->debug) { print "-> {$timestamp} | Timeslot changed from {$prevTimeslot} to {$this->curTimeslot}\n"; }
			if (!$this->manualOverride) {
				$this->curPage['endTime'] = 0; // Expire current page
			}
		}

		$prevActivity = $this->curActivity;
		$this->curActivity = $this->getActivity();
			if ($prevActivity != $this->curActivity) {
			// Activity changed.
			$timestamp=date('m/d/Y h:i:s a', time());
			if ($this->debug) { print "-> {$timestamp} | Activity changed from {$prevActivity} to {$this->curActivity}\n"; }
			if (!$this->manualOverride) {
				$this->curPage['endTime'] = 0; // Expire current page
			}
			}
			$this->writeRuntimeStatus();
		}
	}

$handler = new DisplayServer();

$server = IoServer::factory(new HttpServer(new WsServer($handler)), 12345 /* port */);
$server->loop->addPeriodicTimer(1, function() use ($handler) {
	$handler->onSecond();
});

$server->run();
