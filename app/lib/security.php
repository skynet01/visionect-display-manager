<?php

const VISIONECT_CONFIG_DIR = __DIR__ . '/../config';
const VISIONECT_ADMIN_ACCOUNT_FILE = VISIONECT_CONFIG_DIR . '/admin_account.json';
const VISIONECT_SECRET_KEY_FILE = VISIONECT_CONFIG_DIR . '/secret_key.b64';
const VISIONECT_RUNTIME_STATUS_FILE = VISIONECT_CONFIG_DIR . '/runtime_status.json';
const VISIONECT_REMOTE_CONTROL_FILE = VISIONECT_CONFIG_DIR . '/remote_control.json'; // legacy single slot
const VISIONECT_CONTROL_QUEUE_DIR = VISIONECT_CONFIG_DIR . '/control_queue';

function visionect_read_json_file(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $decoded = json_decode(file_get_contents($path), true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
}

/**
 * Write $data to $path atomically: temp file in the same directory, chmod to
 * match the existing target (or $defaultMode for new files), then rename()
 * over the target. Readers see either the old or the new file, never a torn one.
 * Returns false (and leaves the target untouched) on any failure.
 */
function visionect_write_file_atomic(string $path, string $data, int $defaultMode = 0644): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('visionect_write_file_atomic: cannot create directory ' . $dir);
        return false;
    }

    clearstatcache(true, $path);
    $mode = file_exists($path) ? (fileperms($path) & 0777) : $defaultMode;

    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $fh = @fopen($tmp, 'xb');
    if ($fh === false) {
        error_log('visionect_write_file_atomic: cannot create temp file in ' . $dir);
        return false;
    }

    $length = strlen($data);
    $written = 0;
    while ($written < $length) {
        $n = fwrite($fh, $written === 0 ? $data : substr($data, $written));
        if ($n === false || $n === 0) {
            break;
        }
        $written += $n;
    }
    $flushed = fflush($fh);
    $closed = fclose($fh);

    if ($written !== $length || !$flushed || !$closed) {
        @unlink($tmp);
        error_log('visionect_write_file_atomic: short write for ' . $path);
        return false;
    }

    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        error_log('visionect_write_file_atomic: rename failed for ' . $path);
        return false;
    }

    return true;
}

/**
 * Encode $data as pretty JSON and write it atomically. Returns false WITHOUT
 * touching the target if encoding fails (e.g. INF/NAN, recursion depth).
 */
function visionect_write_json_atomic(string $path, $data, int $extraFlags = 0): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | $extraFlags);
    if ($json === false) {
        error_log('visionect_write_json_atomic: json_encode failed for ' . $path . ': ' . json_last_error_msg());
        return false;
    }

    return visionect_write_file_atomic($path, $json . "\n");
}

/**
 * Run $fn while holding an exclusive flock on the sidecar "<path>.lock".
 * Re-entrant within one process (nested calls on the same path don't deadlock).
 * If the lock file cannot be opened, $fn still runs (writes stay atomic).
 */
function visionect_with_file_lock(string $path, callable $fn)
{
    static $held = [];

    $lockPath = $path . '.lock';
    if (!empty($held[$lockPath])) {
        return $fn();
    }

    $fh = @fopen($lockPath, 'c');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if ($fh !== false) {
            fclose($fh);
        }
        error_log('visionect_with_file_lock: could not lock ' . $lockPath . '; continuing unlocked');
        return $fn();
    }

    $held[$lockPath] = true;
    try {
        return $fn();
    } finally {
        unset($held[$lockPath]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function visionect_write_json_file(string $path, array $data): void
{
    visionect_write_json_atomic($path, $data);
}

function visionect_send_no_cache_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

function visionect_is_private_network_request(): bool
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') {
        return false;
    }

    if ($remote === '127.0.0.1' || $remote === '::1') {
        return true;
    }

    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($remote);
        if ($long === false) {
            return false;
        }
        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['169.254.0.0', '169.254.255.255'],
        ];
        foreach ($ranges as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($startLong !== false && $endLong !== false && $long >= $startLong && $long <= $endLong) {
                return true;
            }
        }
        return false;
    }

    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $normalized = strtolower($remote);
        return strpos($normalized, 'fc') === 0
            || strpos($normalized, 'fd') === 0
            || strpos($normalized, 'fe80:') === 0;
    }

    return false;
}

function visionect_session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = visionect_is_secure_request();
    session_name('visionect_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function visionect_is_secure_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        return in_array($forwardedProto, ['https', 'wss'], true);
    }

    $forwardedSsl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    return $forwardedSsl === 'on';
}

function visionect_is_authenticated(): bool
{
    return !empty($_SESSION['visionect_auth']) && !empty($_SESSION['visionect_username']);
}

function visionect_current_username(): ?string
{
    return visionect_is_authenticated() ? (string)$_SESSION['visionect_username'] : null;
}

function visionect_csrf_token(): string
{
    if (empty($_SESSION['visionect_csrf'])) {
        $_SESSION['visionect_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['visionect_csrf'];
}

function visionect_validate_csrf(?string $token): bool
{
    $expected = $_SESSION['visionect_csrf'] ?? '';
    return is_string($token) && is_string($expected) && $token !== '' && hash_equals($expected, $token);
}

function visionect_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function visionect_account_record(): ?array
{
    return visionect_read_json_file(VISIONECT_ADMIN_ACCOUNT_FILE);
}

function visionect_has_admin_account(): bool
{
    $account = visionect_account_record();
    return is_array($account)
        && trim((string)($account['username'] ?? '')) !== ''
        && trim((string)($account['password_hash'] ?? '')) !== '';
}

function visionect_create_admin_account(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '') {
        throw new InvalidArgumentException('Username is required.');
    }
    if (strlen($password) < 12) {
        throw new InvalidArgumentException('Password must be at least 12 characters.');
    }
    return visionect_with_file_lock(VISIONECT_ADMIN_ACCOUNT_FILE, function () use ($username, $password) {
        if (visionect_has_admin_account()) {
            throw new RuntimeException('An admin account already exists.');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $account = [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (!visionect_write_json_atomic(VISIONECT_ADMIN_ACCOUNT_FILE, $account)) {
            throw new RuntimeException('Could not save the admin account.');
        }
        return $account;
    });
}

function visionect_verify_login(string $username, string $password): bool
{
    $account = visionect_account_record();
    if (!$account) {
        return false;
    }

    $storedUsername = (string)($account['username'] ?? '');
    $storedHash = (string)($account['password_hash'] ?? '');
    if ($storedUsername === '' || $storedHash === '') {
        return false;
    }

    return hash_equals($storedUsername, $username) && password_verify($password, $storedHash);
}

function visionect_login(string $username): void
{
    session_regenerate_id(true);
    $_SESSION['visionect_auth'] = true;
    $_SESSION['visionect_username'] = $username;
    $_SESSION['visionect_csrf'] = bin2hex(random_bytes(32));
}

function visionect_require_auth_json(): void
{
    if (!visionect_is_authenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function visionect_require_csrf_json(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!visionect_validate_csrf($token)) {
        http_response_code(419);
        echo json_encode(['error' => 'Invalid CSRF token'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function visionect_secret_key(): string
{
    if (!file_exists(VISIONECT_SECRET_KEY_FILE)) {
        visionect_with_file_lock(VISIONECT_SECRET_KEY_FILE, function () {
            clearstatcache(true, VISIONECT_SECRET_KEY_FILE);
            if (!file_exists(VISIONECT_SECRET_KEY_FILE)) {
                visionect_write_file_atomic(VISIONECT_SECRET_KEY_FILE, base64_encode(random_bytes(32)) . "\n", 0600);
            }
        });
    }

    $raw = trim((string)file_get_contents(VISIONECT_SECRET_KEY_FILE));
    $decoded = base64_decode($raw, true);
    if ($decoded === false || strlen($decoded) < 32) {
        $decoded = hash('sha256', $raw, true);
    }

    return substr($decoded, 0, 32);
}

function visionect_encrypt_secret(string $plainText): string
{
    if ($plainText === '') {
        return '';
    }

    if (strpos($plainText, 'enc:') === 0) {
        return $plainText;
    }

    $key = visionect_secret_key();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return $plainText;
    }

    $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
    return 'enc:' . base64_encode($iv . $mac . $cipher);
}

function visionect_decrypt_secret(string $value): string
{
    if ($value === '' || strpos($value, 'enc:') !== 0) {
        return $value;
    }

    $decoded = base64_decode(substr($value, 4), true);
    if ($decoded === false || strlen($decoded) < 49) {
        return '';
    }

    $key = visionect_secret_key();
    $iv = substr($decoded, 0, 16);
    $mac = substr($decoded, 16, 32);
    $cipher = substr($decoded, 48);
    $expected = hash_hmac('sha256', $iv . $cipher, $key, true);
    if (!hash_equals($expected, $mac)) {
        return '';
    }

    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function visionect_encrypt_fields(array $data, array $fields): array
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = visionect_encrypt_secret((string)$data[$field]);
        }
    }

    return $data;
}

function visionect_decrypt_fields(array $data, array $fields): array
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = visionect_decrypt_secret((string)$data[$field]);
        }
    }

    return $data;
}

function visionect_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function visionect_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

/**
 * Signed WebSocket token. $role is 'admin' (session-authenticated admin UI) or 'display'
 * (the public frame shell); visionectd only runs control tasks for role=admin.
 */
function visionect_issue_websocket_token(?string $username, int $ttl = 3600, string $role = 'display'): string
{
    $claims = [
        'sub' => (string)($username ?? ''),
        'role' => $role === 'admin' ? 'admin' : 'display',
        'exp' => time() + max(60, $ttl),
    ];
    $payload = json_encode($claims, JSON_UNESCAPED_SLASHES);
    $encodedPayload = visionect_base64url_encode($payload);
    $signature = hash_hmac('sha256', $encodedPayload, visionect_secret_key(), true);
    return $encodedPayload . '.' . visionect_base64url_encode($signature);
}

function visionect_validate_websocket_token(string $token): ?array
{
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }

    [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
    $payloadJson = visionect_base64url_decode($encodedPayload);
    $signature = visionect_base64url_decode($encodedSignature);
    if ($payloadJson === '' || $signature === '') {
        return null;
    }

    $expected = hash_hmac('sha256', $encodedPayload, visionect_secret_key(), true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $claims = json_decode($payloadJson, true);
    if (!is_array($claims)) {
        return null;
    }

    if ((int)($claims['exp'] ?? 0) < time()) {
        return null;
    }

    return $claims;
}

/** Role carried by validated token claims. Tokens without a role claim (pre-role) are 'display'. */
function visionect_websocket_token_role(?array $claims): string
{
    if (!is_array($claims) || trim((string)($claims['sub'] ?? '')) === '') {
        return 'display';
    }

    return ($claims['role'] ?? '') === 'admin' ? 'admin' : 'display';
}

function visionect_runtime_status_defaults(): array
{
    return [
        'display' => [
            'paused' => false,
            'curPage' => null,
            'endTime' => time(),
            'timeslot' => null,
            'activity' => 'home',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ],
        'cron' => [],
    ];
}

function visionect_read_runtime_status(): array
{
    $data = visionect_read_json_file(VISIONECT_RUNTIME_STATUS_FILE);
    return array_replace_recursive(visionect_runtime_status_defaults(), is_array($data) ? $data : []);
}

function visionect_write_runtime_status(array $status): void
{
    visionect_with_file_lock(VISIONECT_RUNTIME_STATUS_FILE, function () use ($status) {
        visionect_write_json_atomic(VISIONECT_RUNTIME_STATUS_FILE, array_replace_recursive(visionect_runtime_status_defaults(), $status));
    });
}

/**
 * Locked read-modify-write of runtime_status.json. $fn receives the current
 * status and returns the new full status. Use this when the new value depends
 * on the old one (e.g. newspaper next_index) so concurrent writers can't lose updates.
 */
function visionect_mutate_runtime_status(callable $fn): array
{
    return visionect_with_file_lock(VISIONECT_RUNTIME_STATUS_FILE, function () use ($fn) {
        $status = $fn(visionect_read_runtime_status());
        if (!is_array($status)) {
            return visionect_read_runtime_status();
        }
        visionect_write_runtime_status($status);
        return $status;
    });
}

function visionect_update_runtime_status(array $patch): array
{
    return visionect_mutate_runtime_status(function (array $status) use ($patch) {
        return array_replace_recursive($status, $patch);
    });
}

function visionect_track_frame_request(string $module): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (isset($_GET['preview'])) {
        return;
    }

    visionect_update_runtime_status([
        'frame' => [
            'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'last_module' => $module,
            'last_path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        ],
    ]);
}

function visionect_record_frame_response(string $module, string $exactUrl, string $kind = 'page', array $extra = []): void
{
    if (PHP_SAPI === 'cli' || isset($_GET['preview'])) {
        return;
    }

    $frameDefaults = [
        'asset_file' => null,
        'style' => null,
        'paper_prefix' => null,
        'paper_name' => null,
        'story_title' => null,
    ];

    visionect_update_runtime_status([
        'frame' => array_merge($frameDefaults, [
            'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'last_module' => $module,
            'last_path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'exact_url' => $exactUrl,
            'exact_kind' => $kind,
        ], $extra),
    ]);
}

/**
 * Control queue: one JSON file per command in config/control_queue/, named
 * "<microtime>-<rand>.json" so a lexical sort is FIFO. Writers (control.php, the admin)
 * write atomically; visionectd takes the files in order and deletes each one.
 */
function visionect_control_queue_dir(): string
{
    return VISIONECT_CONTROL_QUEUE_DIR;
}

function visionect_queue_remote_control(array $command): array
{
    $queued = array_merge([
        'id' => bin2hex(random_bytes(8)),
        'task' => '',
        'page' => null,
        'queued_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], $command);
    $queued['queued_ts'] = microtime(true);

    $dir = visionect_control_queue_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $name = sprintf('%017.6f', $queued['queued_ts']) . '-' . bin2hex(random_bytes(4)) . '.json';
    $queued['queue_file'] = $name;
    $queued['queued'] = visionect_write_json_atomic($dir . '/' . $name, $queued);
    return $queued;
}

/**
 * Take every queued command, oldest first. Each file is deleted as it is taken; unreadable
 * files are deleted and skipped. Also drains the legacy single-slot remote_control.json.
 */
function visionect_take_remote_controls(): array
{
    $commands = [];

    $legacy = visionect_read_json_file(VISIONECT_REMOTE_CONTROL_FILE);
    if (file_exists(VISIONECT_REMOTE_CONTROL_FILE)) {
        @unlink(VISIONECT_REMOTE_CONTROL_FILE);
    }
    if (is_array($legacy)) {
        $commands[] = $legacy;
    }

    $files = glob(visionect_control_queue_dir() . '/*.json');
    if (!is_array($files) || empty($files)) {
        return $commands;
    }
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $command = visionect_read_json_file($file);
        if (!@unlink($file) && file_exists($file)) {
            error_log('visionect_take_remote_controls: could not delete ' . $file);
            continue;
        }
        if (is_array($command)) {
            $commands[] = $command;
        }
    }

    return $commands;
}

/** Oldest queued command, or null. Kept for callers that want one at a time. */
function visionect_take_remote_control(): ?array
{
    $files = glob(visionect_control_queue_dir() . '/*.json');
    if (is_array($files) && !empty($files)) {
        sort($files, SORT_STRING);
        $command = visionect_read_json_file($files[0]);
        @unlink($files[0]);
        return is_array($command) ? $command : null;
    }

    $legacy = visionect_read_json_file(VISIONECT_REMOTE_CONTROL_FILE);
    @unlink(VISIONECT_REMOTE_CONTROL_FILE);
    return $legacy;
}

/**
 * Ask visionectd to exit cleanly (task restartDaemon). The container command chain then ends
 * and Docker's unless-stopped policy restarts the container. Waits up to $waitSeconds for the
 * daemon to take the command; if it doesn't (daemon hung), sends SIGTERM to the daemon PID
 * recorded in runtime_status, which ends the chain the same way. Returns true when either worked.
 */
function visionect_request_daemon_restart(float $waitSeconds = 4.0): bool
{
    $queued = visionect_queue_remote_control([
        'task' => 'restartDaemon',
        'source' => PHP_SAPI === 'cli' ? 'cli' : 'admin',
    ]);
    if (empty($queued['queued'])) {
        return false;
    }

    $path = visionect_control_queue_dir() . '/' . $queued['queue_file'];
    $deadline = microtime(true) + max(0.5, $waitSeconds);
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return true;
        }
        usleep(200000);
    }

    // Not taken: never leave a restart queued to fire later.
    @unlink($path);

    $pid = (int)(visionect_read_runtime_status()['daemon']['pid'] ?? 0);
    if ($pid > 1 && function_exists('posix_kill')) {
        $cmdline = (string)@file_get_contents('/proc/' . $pid . '/cmdline');
        if (strpos($cmdline, 'visionectd.php') !== false) {
            return posix_kill($pid, 15);
        }
    }

    return false;
}
