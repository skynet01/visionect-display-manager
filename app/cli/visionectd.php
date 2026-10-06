<?php

if (isset($_SERVER["REMOTE_ADDR"]) || isset($_SERVER["HTTP_USER_AGENT"]) || !isset($_SERVER["argv"])) {
	exit('Please run this from the command line');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/runtime.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

/**
 * WebSocket scheduler for the frame.
 *
 * - Picks the active page from PREFS.json (timeslots, chance weights, durations) and sends its
 *   URL to every connected client (the frame shell and the admin).
 * - Commands arrive over the WebSocket (token role=admin) or through the file control queue
 *   (control.php / admin api). Both go through handleCommand().
 * - Rotation is frozen while paused, away or asleep, so curPage always matches what the frame shows.
 * - Exits cleanly on restartDaemon; the container command chain then ends and Docker's
 *   unless-stopped policy restarts the container.
 */
class DisplayServer implements MessageComponentInterface {
	private $debug = true;
	const PREFS_FILE = __DIR__ . '/../config/PREFS.json';
	const MANUAL_OVERRIDE_SECONDS = 1800;
	const COMMAND_MAX_AGE = 300;
	const RESTART_EXIT_DELAY = 1.5;
	// Tasks any client may send; everything in ADMIN_TASKS needs role=admin (or the local control queue).
	const PUBLIC_TASKS = array('getStatus');
	const ADMIN_TASKS = array('pause', 'unpause', 'setPage', 'reloadPrefs', 'resumeSchedule', 'reloadCurrent', 'refreshActivity', 'restartDaemon');

	protected $clients;
	protected $PREFS;
	protected $curPage = null;
	protected $curActivity = 'home';
	protected $curTimeslot = null;
	protected $paused = false;
	protected $nextPageKey = null;
	protected $manualOverride = null;
	protected $lastMinute = null;
	protected $lastWrittenDisplay = null;
	protected $startedAt;
	protected $loop = null;
	protected $exiting = false;

	public function __construct() {
		$this->clients = new \SplObjectStorage;
		$this->startedAt = microtime(true);
		$this->log('-> Display server started (pid ' . getmypid() . ').');

		$this->PREFS = $this->loadPrefs() ?? array('pages' => array(), 'timeslots' => array());
		$this->curActivity = visionect_compute_activity();
		$this->curTimeslot = $this->getTimeslot();
		$this->lastMinute = intdiv(time(), 60);
		$this->advance();

		visionect_update_runtime_status(array(
			'daemon' => array(
				'pid' => getmypid(),
				'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
			),
		));
		$this->writeRuntimeStatus();
	}

	public function setLoop($loop) {
		$this->loop = $loop;
	}

	private function log($line) {
		if ($this->debug) {
			print $line . "\n";
		}
	}

	private function stamp() {
		return date('m/d/Y h:i:s a', time());
	}

	/* ---------------------------------------------------------------- PREFS */

	/** Normalised PREFS, or null when the file is missing/invalid (callers keep the last good copy). */
	private function loadPrefs() {
		$prefs = visionect_read_json_file(self::PREFS_FILE);
		if (!is_array($prefs) || !isset($prefs['pages']) || !is_array($prefs['pages'])) {
			$this->log('!! ' . $this->stamp() . ' | PREFS.json missing or invalid; keeping the last good PREFS.');
			return null;
		}

		$pages = array();
		foreach ($prefs['pages'] as $key => $page) {
			if (!is_array($page) || trim((string)($page['url'] ?? '')) === '') {
				continue;
			}
			$pages[(string)$key] = array(
				'url' => trim((string)$page['url']),
				'chance' => max(0, (int)($page['chance'] ?? 1)),
				'dynamic' => !empty($page['dynamic']),
				'duration' => max(10, (int)($page['duration'] ?? 60)),
				'enabled' => !array_key_exists('enabled', $page) || (bool)$page['enabled'],
			);
		}
		if (empty($pages)) {
			$this->log('!! ' . $this->stamp() . ' | PREFS.json has no usable pages; keeping the last good PREFS.');
			return null;
		}

		$timeslots = array();
		foreach ((is_array($prefs['timeslots'] ?? null) ? $prefs['timeslots'] : array()) as $name => $slot) {
			if (!is_array($slot)) {
				continue;
			}
			$timeslots[(string)$name] = array(
				'day_time' => is_array($slot['day_time'] ?? null) ? $slot['day_time'] : array(),
				'pages' => array_values(array_map('strval', is_array($slot['pages'] ?? null) ? $slot['pages'] : array())),
				'duration' => isset($slot['duration']) ? max(10, (int)$slot['duration']) : null,
			);
		}

		return array('pages' => $pages, 'timeslots' => $timeslots);
	}

	private function isPageEnabled($pageKey) {
		return is_string($pageKey) && isset($this->PREFS['pages'][$pageKey]) && $this->PREFS['pages'][$pageKey]['enabled'];
	}

	private function slot() {
		return ($this->curTimeslot !== null && isset($this->PREFS['timeslots'][$this->curTimeslot]))
			? $this->PREFS['timeslots'][$this->curTimeslot]
			: null;
	}

	/** Timeslot duration when the page belongs to the current slot, else the page's own duration. */
	private function resolvePageDuration($pageKey) {
		$slot = $this->slot();
		if ($slot !== null && $slot['duration'] !== null && in_array($pageKey, $slot['pages'], true)) {
			return $slot['duration'];
		}
		return (int)$this->PREFS['pages'][$pageKey]['duration'];
	}

	/** Enabled pages of the current timeslot; all enabled pages outside a slot or when the slot has none. */
	private function eligiblePages() {
		$slot = $this->slot();
		if ($slot !== null) {
			$inSlot = array_values(array_filter($slot['pages'], array($this, 'isPageEnabled')));
			if (!empty($inSlot)) {
				return $inSlot;
			}
		}
		return array_values(array_filter(array_map('strval', array_keys($this->PREFS['pages'])), array($this, 'isPageEnabled')));
	}

	private function isPageAllowed($pageKey) {
		return in_array($pageKey, $this->eligiblePages(), true);
	}

	/**
	 * Weighted random pick from the eligible pages. A non-dynamic current page is not repeated
	 * unless it is the only option. All chances 0: uniform pick. Nothing eligible: null.
	 */
	private function pickPage($currentKey) {
		$candidates = $this->eligiblePages();
		if (empty($candidates)) {
			return null;
		}
		if ($currentKey !== null && isset($this->PREFS['pages'][$currentKey]) && !$this->PREFS['pages'][$currentKey]['dynamic']) {
			$others = array_values(array_filter($candidates, function ($key) use ($currentKey) { return $key !== $currentKey; }));
			if (!empty($others)) {
				$candidates = $others;
			}
		}

		$total = 0;
		foreach ($candidates as $key) {
			$total += $this->PREFS['pages'][$key]['chance'];
		}
		if ($total <= 0) {
			return $candidates[random_int(0, count($candidates) - 1)];
		}
		$roll = random_int(1, $total);
		foreach ($candidates as $key) {
			$roll -= $this->PREFS['pages'][$key]['chance'];
			if ($roll <= 0) {
				return $key;
			}
		}
		return $candidates[count($candidates) - 1];
	}

	private function setCurrentPage($pageKey) {
		$duration = $this->resolvePageDuration($pageKey);
		$this->curPage = array(
			'key' => $pageKey,
			'duration' => $duration,
			'endTime' => time() + $duration,
			'url' => $this->PREFS['pages'][$pageKey]['url'],
		);
	}

	/**
	 * Move to the next page: the precomputed nextPageKey when it is still allowed, otherwise a
	 * fresh pick, otherwise stay on the current page. Then precompute the following pick, so
	 * the "nextPage" shown in the admin is the page that will really come next.
	 */
	private function advance() {
		$this->clearManualOverride();
		$currentKey = $this->curPage['key'] ?? null;
		$key = ($this->nextPageKey !== null && $this->isPageAllowed($this->nextPageKey)) ? $this->nextPageKey : $this->pickPage($currentKey);
		if ($key === null && $this->isPageEnabled($currentKey)) {
			$key = $currentKey;
		}
		if ($key === null) {
			$this->log('!! ' . $this->stamp() . ' | No enabled pages are available for rotation.');
			// Keep whatever the frame shows; never hand out an empty URL.
			$this->curPage = array(
				'key' => $currentKey,
				'duration' => 60,
				'endTime' => time() + 60,
				'url' => (string)($this->curPage['url'] ?? ''),
			);
			$this->nextPageKey = null;
			return;
		}
		$this->setCurrentPage($key);
		$this->nextPageKey = $this->pickPage($key);
	}

	/** True while the frame should not rotate (paused, owner away, frame asleep). */
	private function isFrozen() {
		return $this->paused || $this->curActivity === 'away' || $this->curActivity === 'sleep';
	}

	/* ------------------------------------------------------------- timeslots */

	/**
	 * Current timeslot name or null. A range whose "till" is earlier than "from" crosses midnight:
	 * it covers from..24:00 on its day and 00:00..till on the following day.
	 */
	private function getTimeslot($now = null) {
		$now = $now ?? time();
		$day = strtolower(date('D', $now));
		$yesterday = strtolower(date('D', $now - 86400));
		$hm = date('H:i', $now);

		foreach ($this->PREFS['timeslots'] as $name => $slot) {
			foreach ($slot['day_time'] as $slotDay => $range) {
				if (!is_array($range)) {
					continue;
				}
				$slotDay = strtolower((string)$slotDay);
				$from = (string)($range['from'] ?? '');
				$till = (string)($range['till'] ?? '');
				if ($from === '' || $till === '' || $from === $till) {
					continue;
				}
				if ($from < $till) {
					if ($slotDay === $day && $hm >= $from && $hm < $till) {
						return (string)$name;
					}
				} elseif (($slotDay === $day && $hm >= $from) || ($slotDay === $yesterday && $hm < $till)) {
					return (string)$name;
				}
			}
		}
		return null;
	}

	/* --------------------------------------------------------- state output */

	protected function getStatus() {
		return array('status' => array(
			'paused' => $this->paused,
			'curPage' => $this->curPage['key'],
			'endTime' => $this->curPage['endTime'],
			'timeslot' => $this->curTimeslot,
			'activity' => $this->curActivity,
			'nextPage' => $this->nextPageKey,
			'manualOverride' => $this->manualOverride,
		));
	}

	/** Write the display state to runtime_status.json, only when it changed. */
	private function writeRuntimeStatus() {
		$display = array(
			'paused' => $this->paused,
			'curPage' => $this->curPage['key'] ?? null,
			'nextPage' => $this->nextPageKey,
			'endTime' => $this->curPage['endTime'] ?? time(),
			'timeslot' => $this->curTimeslot,
			'activity' => $this->curActivity,
			'manual_override' => $this->manualOverride,
		);
		if ($display === $this->lastWrittenDisplay) {
			return;
		}
		$this->lastWrittenDisplay = $display;
		$display['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
		visionect_mutate_runtime_status(function (array $status) use ($display) {
			// Replace the whole section so a cleared manual_override really clears.
			$status['display'] = $display;
			return $status;
		});
	}

	private function sendUrl(ConnectionInterface $client) {
		$url = (string)($this->curPage['url'] ?? '');
		if ($url !== '') {
			$client->send(json_encode(array('url' => $url)));
		}
	}

	private function broadcastState($sendUrl = true) {
		$this->writeRuntimeStatus();
		$status = json_encode($this->getStatus());
		foreach ($this->clients as $client) {
			if ($sendUrl) {
				$this->sendUrl($client);
			}
			$client->send($status);
		}
	}

	/* ------------------------------------------------------------- overrides */

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

	private function revertToSchedule($label) {
		$this->curTimeslot = $this->getTimeslot();
		$this->advance();
		$this->log("!! {$this->stamp()} | {$label} -> " . ($this->curPage['key'] ?? 'none'));
		$this->broadcastState(true);
	}

	/* -------------------------------------------------------------- commands */

	/**
	 * The one command implementation for WebSocket messages and the control queue.
	 * $role is 'admin' for the control queue and admin tokens, 'display' otherwise.
	 */
	private function handleCommand(array $command, $role, $source) {
		$task = (string)($command['task'] ?? '');
		$stamp = $this->stamp();
		if ($task === '') {
			return false;
		}
		if (in_array($task, self::PUBLIC_TASKS, true)) {
			return true;
		}
		if (!in_array($task, self::ADMIN_TASKS, true)) {
			$this->log("!! {$stamp} | Unknown task {$task} from {$source}");
			return false;
		}
		if ($role !== 'admin') {
			$this->log("!! {$stamp} | Ignored {$task} from {$source} (role {$role})");
			return false;
		}

		switch ($task) {
			case 'pause':
				$this->paused = true;
				$this->log("!! {$stamp} | Paused ({$source})");
				$this->broadcastState(false);
				return true;

			case 'unpause':
				$this->paused = false;
				$this->log("!! {$stamp} | Unpaused ({$source})");
				// Rotation was frozen, so the frame still shows curPage; an expired page advances on the next tick.
				$this->broadcastState(false);
				return true;

			case 'reloadPrefs':
				$prefs = $this->loadPrefs();
				if ($prefs === null) {
					return false;
				}
				$this->PREFS = $prefs;
				$this->syncPrefsState();
				$this->log("!! {$stamp} | PREFS reloaded ({$source})");
				$this->broadcastState(true);
				return true;

			case 'resumeSchedule':
				$this->revertToSchedule("Returned to schedule ({$source})");
				return true;

			case 'reloadCurrent':
				$key = $this->curPage['key'] ?? null;
				if (!$this->isPageEnabled($key)) {
					return false;
				}
				$override = $this->manualOverride;
				$this->setCurrentPage($key);
				if ($override) {
					$this->curPage['endTime'] = (int)$override['expires_at'];
				}
				$this->log("!! {$stamp} | Reloaded {$key} ({$source})");
				$this->broadcastState(true);
				return true;

			case 'setPage':
				$page = (string)($command['page'] ?? '');
				if (!$this->isPageEnabled($page)) {
					$this->log("!! {$stamp} | setPage ignored: '{$page}' is not an enabled page ({$source})");
					return false;
				}
				$this->setCurrentPage($page);
				$this->startManualOverride($page);
				$this->nextPageKey = $this->pickPage($page);
				$this->log("!! {$stamp} | Jumped to {$page} ({$source})");
				$this->broadcastState(true);
				return true;

			case 'refreshActivity':
				$this->refreshActivity($source);
				return true;

			case 'restartDaemon':
				$queuedTs = (float)($command['queued_ts'] ?? 0);
				if ($queuedTs > 0 && $queuedTs < $this->startedAt) {
					$this->log("!! {$stamp} | Ignored restartDaemon queued before this start ({$source})");
					return false;
				}
				$this->requestExit("restartDaemon ({$source})");
				return true;
		}

		return false;
	}

	private function syncPrefsState() {
		$previousTimeslot = $this->curTimeslot;
		$this->curTimeslot = $this->getTimeslot();
		$currentKey = $this->curPage['key'] ?? null;

		if (!$this->isPageEnabled($currentKey)
			|| (!$this->manualOverride && ($previousTimeslot !== $this->curTimeslot || !$this->isPageAllowed($currentKey)))) {
			$this->nextPageKey = null;
			$this->advance();
			return;
		}

		// Keep the current page and its remaining time; refresh its URL and the next pick.
		$endTime = $this->curPage['endTime'];
		$this->setCurrentPage($currentKey);
		$this->curPage['endTime'] = $endTime;
		if ($this->nextPageKey === null || !$this->isPageAllowed($this->nextPageKey)) {
			$this->nextPageKey = $this->pickPage($currentKey);
		}
	}

	private function refreshActivity($source) {
		$previous = $this->curActivity;
		$this->curActivity = visionect_compute_activity();
		if ($previous !== $this->curActivity) {
			$this->log("!! {$this->stamp()} | Activity {$previous} -> {$this->curActivity} ({$source})");
			if (!$this->manualOverride) {
				$this->curPage['endTime'] = 0;
			}
		}
		$this->broadcastState(false);
	}

	private function pollControlQueue() {
		foreach (visionect_take_remote_controls() as $command) {
			$queuedTs = (float)($command['queued_ts'] ?? 0);
			if ($queuedTs > 0 && $queuedTs < microtime(true) - self::COMMAND_MAX_AGE) {
				$this->log('!! ' . $this->stamp() . ' | Dropped stale queued task ' . ($command['task'] ?? '?'));
				continue;
			}
			$this->handleCommand($command, 'admin', 'queue:' . (string)($command['source'] ?? 'local'));
		}
	}

	private function requestExit($reason) {
		if ($this->exiting) {
			return;
		}
		$this->exiting = true;
		$this->log("!! {$this->stamp()} | Exiting so the container restarts: {$reason}");
		$stop = function () {
			foreach ($this->clients as $client) {
				$client->close();
			}
			if ($this->loop) {
				$this->loop->stop();
			} else {
				exit(0);
			}
		};
		if ($this->loop) {
			// Short delay so the HTTP request that asked for the restart still gets its answer.
			$this->loop->addTimer(self::RESTART_EXIT_DELAY, $stop);
		} else {
			$stop();
		}
	}

	/* ------------------------------------------------------------ websocket */

	public function onOpen(ConnectionInterface $conn) {
		$query = array();
		parse_str((string)$conn->httpRequest->getUri()->getQuery(), $query);
		$claims = visionect_validate_websocket_token((string)($query['token'] ?? ''));
		$ok = is_array($claims);
		$role = visionect_websocket_token_role($claims);
		$this->clients->attach($conn, array('ok' => $ok, 'role' => $role));
		$this->log("-> Client connected: {$conn->resourceId} (role {$role}" . ($ok ? '' : ', no valid token') . ')');

		$conn->send(json_encode(array('type' => 'auth', 'ok' => $ok, 'role' => $role)));
		$this->sendUrl($conn);
		$conn->send(json_encode($this->getStatus()));
	}

	public function onClose(ConnectionInterface $conn) {
		$this->clients->detach($conn);
		$this->log("-> Client disconnected: {$conn->resourceId}");
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		$this->log("-> Client error: {$conn->resourceId} | {$e->getMessage()}");
		$conn->close();
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$this->log("-> {$this->stamp()} | Received message from {$from->resourceId}: " . substr((string)$msg, 0, 300));
		if ($msg === 'status') {
			$from->send(json_encode($this->getStatus()));
			return;
		}
		$json = json_decode((string)$msg, true);
		if (!is_array($json) || !array_key_exists('task', $json)) {
			return;
		}
		$meta = $this->clients->contains($from) ? $this->clients[$from] : array();
		$role = is_array($meta) ? (string)($meta['role'] ?? 'display') : 'display';
		$this->handleCommand($json, $role, 'ws:' . $from->resourceId);
		$from->send(json_encode($this->getStatus()));
	}

	/* ----------------------------------------------------------------- clock */

	// Called every second by the loop.
	public function onSecond() {
		if ($this->exiting) {
			return;
		}
		try {
			$this->pollControlQueue();

			$minute = intdiv(time(), 60);
			if ($minute !== $this->lastMinute) {
				$this->lastMinute = $minute;
				$this->onMinute();
			}

			if ($this->exiting || $this->isFrozen()) {
				return;
			}

			if ($this->manualOverride) {
				$expiresAt = (int)($this->manualOverride['expires_at'] ?? 0);
				if ($expiresAt > 0 && time() >= $expiresAt) {
					$this->revertToSchedule('Manual override expired, returning to schedule');
				}
				return;
			}

			if (time() >= $this->curPage['endTime']) {
				$this->advance();
				$this->log("-> {$this->stamp()} | Navigating to " . ($this->curPage['key'] ?? 'none') . " ({$this->curPage['url']}), {$this->curPage['duration']} seconds. Time slot is {$this->curTimeslot}");
				$this->broadcastState(true);
			}
		} catch (\Throwable $e) {
			$this->log('!! ' . $this->stamp() . ' | onSecond error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
		}
	}

	// Called once per wall-clock minute. Activity config is read once per call.
	public function onMinute() {
		$previousTimeslot = $this->curTimeslot;
		$this->curTimeslot = $this->getTimeslot();
		if ($previousTimeslot !== $this->curTimeslot) {
			$this->log("-> {$this->stamp()} | Timeslot changed from {$previousTimeslot} to {$this->curTimeslot}");
			if (!$this->manualOverride) {
				$this->curPage['endTime'] = 0;
			}
			if ($this->nextPageKey === null || !$this->isPageAllowed($this->nextPageKey)) {
				$this->nextPageKey = $this->pickPage($this->curPage['key'] ?? null);
			}
		}

		$previousActivity = $this->curActivity;
		$this->curActivity = visionect_compute_activity();
		if ($previousActivity !== $this->curActivity) {
			$this->log("-> {$this->stamp()} | Activity changed from {$previousActivity} to {$this->curActivity}");
			if (!$this->manualOverride) {
				$this->curPage['endTime'] = 0;
			}
		}

		if ($previousTimeslot !== $this->curTimeslot || $previousActivity !== $this->curActivity) {
			$this->broadcastState(false);
		}
	}
}

$handler = new DisplayServer();

$server = IoServer::factory(new HttpServer(new WsServer($handler)), 12345 /* port */);
$handler->setLoop($server->loop);
$server->loop->addPeriodicTimer(1, function() use ($handler) {
	$handler->onSecond();
});

$server->run();
print "-> Display server stopped.\n";
exit(0);
