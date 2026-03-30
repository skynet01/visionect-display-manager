<?php

const VISIONECT_CONFIG_DIR = __DIR__ . '/../config';
const VISIONECT_ADMIN_ACCOUNT_FILE = VISIONECT_CONFIG_DIR . '/admin_account.json';
const VISIONECT_SECRET_KEY_FILE = VISIONECT_CONFIG_DIR . '/secret_key.b64';
const VISIONECT_RUNTIME_STATUS_FILE = VISIONECT_CONFIG_DIR . '/runtime_status.json';
const VISIONECT_REMOTE_CONTROL_FILE = VISIONECT_CONFIG_DIR . '/remote_control.json';

function visionect_read_json_file(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $decoded = json_decode(file_get_contents($path), true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
}

function visionect_write_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
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
        return str_starts_with($normalized, 'fc')
            || str_starts_with($normalized, 'fd')
            || str_starts_with($normalized, 'fe80:');
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
    visionect_write_json_file(VISIONECT_ADMIN_ACCOUNT_FILE, $account);
    return $account;
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
        $dir = dirname(VISIONECT_SECRET_KEY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(VISIONECT_SECRET_KEY_FILE, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
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

function visionect_issue_websocket_token(?string $username, int $ttl = 3600): string
{
    $claims = [
        'sub' => (string)($username ?? ''),
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
    visionect_write_json_file(VISIONECT_RUNTIME_STATUS_FILE, array_replace_recursive(visionect_runtime_status_defaults(), $status));
}

function visionect_update_runtime_status(array $patch): array
{
    $status = array_replace_recursive(visionect_read_runtime_status(), $patch);
    visionect_write_runtime_status($status);
    return $status;
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

function visionect_queue_remote_control(array $command): array
{
    $queued = array_merge([
        'id' => bin2hex(random_bytes(8)),
        'task' => '',
        'page' => null,
        'queued_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], $command);
    visionect_write_json_file(VISIONECT_REMOTE_CONTROL_FILE, $queued);
    return $queued;
}

function visionect_take_remote_control(): ?array
{
    $command = visionect_read_json_file(VISIONECT_REMOTE_CONTROL_FILE);
    if (!is_array($command)) {
        return null;
    }

    @unlink(VISIONECT_REMOTE_CONTROL_FILE);
    return $command;
}
