<?php
require_once __DIR__ . '/admin_common.php';
visionect_session_boot();

$loginError = '';
$hasAdminAccount = visionect_has_admin_account();
// First-run setup only when the account file is genuinely absent. A file that exists but
// cannot be parsed must never reopen setup (anyone on the LAN could claim the admin).
if (!$hasAdminAccount && file_exists(VISIONECT_ADMIN_ACCOUNT_FILE)) {
  http_response_code(500);
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex"><title>Display Manager error</title></head>'
    . '<body style="font-family:system-ui,sans-serif;background:#09111a;color:#e2e8f0;padding:3rem;max-width:40rem;margin:auto">'
    . '<h1 style="font-size:1.25rem">Admin account file is unreadable</h1>'
    . '<p><code>config/admin_account.json</code> exists but is empty or not valid JSON, so sign-in is disabled. '
    . 'Restore it from a backup (or delete it to run first-time setup again) on the server.</p></body></html>';
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $authAction = (string)($_POST['auth_action'] ?? '');
  $csrf = (string)($_POST['_csrf'] ?? '');

  if ($authAction === 'logout' && visionect_validate_csrf($csrf)) {
    visionect_logout();
    header('Location: ./');
    exit;
  }

  if ($authAction === 'login') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!$hasAdminAccount) {
      $loginError = 'Create the first admin account before signing in.';
    } elseif (!visionect_validate_csrf($csrf)) {
      $loginError = 'The login form expired. Refresh and try again.';
    } elseif (visionect_verify_login($username, $password)) {
      visionect_login($username);
      header('Location: ./');
      exit;
    } else {
      $loginError = 'Invalid username or password.';
    }
  }

  if ($authAction === 'create_admin') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($hasAdminAccount || file_exists(VISIONECT_ADMIN_ACCOUNT_FILE)) {
      $loginError = 'An admin account already exists. Sign in instead.';
    } elseif (!visionect_validate_csrf($csrf)) {
      $loginError = 'The setup form expired. Refresh and try again.';
    } elseif ($password !== $confirmPassword) {
      $loginError = 'Password and confirmation do not match.';
    } else {
      try {
        $account = visionect_create_admin_account($username, $password);
        $hasAdminAccount = true;
        visionect_login($account['username']);
        header('Location: ./');
        exit;
      } catch (Throwable $error) {
        $loginError = $error->getMessage();
      }
    }
  }
}

$csrfToken = visionect_csrf_token();
function visionect_admin_relative_time(?string $value): string
{
  if (!$value) {
    return 'Unknown';
  }

  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return 'Unknown';
  }

  $delta = time() - $timestamp;
  if ($delta < 0) {
    $delta = 0;
  }

  if ($delta < 60) {
    return 'just now';
  }
  if ($delta < 3600) {
    return floor($delta / 60) . 'm ago';
  }
  if ($delta < 86400) {
    return floor($delta / 3600) . 'h ago';
  }
  return floor($delta / 86400) . 'd ago';
}

function visionect_admin_countdown(?int $timestamp): string
{
  if (!$timestamp || $timestamp <= 0) {
    return 'Unknown';
  }

  $delta = $timestamp - time();
  if ($delta <= 0) {
    return 'due now';
  }

  $hours = floor($delta / 3600);
  $minutes = floor(($delta % 3600) / 60);
  $seconds = $delta % 60;

  if ($hours > 0) {
    return sprintf('%dh %02dm', $hours, $minutes);
  }
  return sprintf('%dm %02ds', $minutes, $seconds);
}

function visionect_admin_next_wake_timestamp(array $config): ?int
{
  $sleepEnabled = !empty($config['sleep_enabled']);
  $wake = trim((string)($config['wake_time'] ?? ''));
  if (!$sleepEnabled || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $wake)) {
    return null;
  }

  [$hour, $minute] = array_map('intval', explode(':', $wake, 2));
  $now = time();
  $wakeToday = mktime($hour, $minute, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
  return $wakeToday > $now ? $wakeToday : strtotime('+1 day', $wakeToday);
}

$loginRuntimeStatus = visionect_read_runtime_status();
$loginGeneralConfig = general_config_payload();
$loginFrameWidth = max(1, (int)($loginGeneralConfig['frame_width'] ?? 1440));
$loginFrameHeight = max(1, (int)($loginGeneralConfig['frame_height'] ?? 2560));
$loginDisplay = is_array($loginRuntimeStatus['display'] ?? null) ? $loginRuntimeStatus['display'] : [];
$loginFrame = is_array($loginRuntimeStatus['frame'] ?? null) ? $loginRuntimeStatus['frame'] : [];
$loginCurrentModule = (string)($loginDisplay['curPage'] ?? 'Unknown');
$loginLastUpdated = visionect_admin_relative_time($loginDisplay['updated_at'] ?? null);
$loginActivity = (string)($loginDisplay['activity'] ?? 'home');
$loginNextUpdateTimestamp = isset($loginDisplay['endTime']) ? (int)$loginDisplay['endTime'] : null;
if ($loginActivity === 'sleep') {
  $loginNextUpdateTimestamp = visionect_admin_next_wake_timestamp($loginGeneralConfig);
}
$loginNextUpdate = visionect_admin_countdown($loginNextUpdateTimestamp);
$loginPaused = !empty($loginDisplay['paused']);
$loginPreviewUrl = (string)($loginFrame['exact_url'] ?? '');
if ($loginPreviewUrl === '' && $loginCurrentModule !== '' && $loginCurrentModule !== 'Unknown') {
  $loginPreviewUrl = '/' . trim($loginCurrentModule, '/') . '/';
}
$loginStateText = 'Active';
if ($loginPaused) {
  $loginStateText = 'Paused manually';
} elseif (in_array($loginActivity, ['away', 'sleep'], true)) {
  $loginStateText = 'Paused by Home Assistant (' . $loginActivity . ')';
} elseif ($loginActivity !== '') {
  $loginStateText = 'Active (' . $loginActivity . ')';
}

if (!visionect_is_authenticated()) {
$pageTitle = $hasAdminAccount ? 'Display Manager Login' : 'Display Manager Setup';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<script src="vendor/tailwindcss-play-3.4.17.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Space Grotesk"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    }
  }
};
</script>
<style>
  body {
    background:
      radial-gradient(circle at top left, rgba(34,197,94,0.16), transparent 28%),
      radial-gradient(circle at bottom right, rgba(14,165,233,0.14), transparent 32%),
      #09111a;
  }
</style>
</head>
<body class="min-h-screen font-sans text-slate-100">
  <div class="mx-auto flex min-h-screen max-w-6xl items-center px-6 py-10">
    <div class="grid w-full gap-8 lg:grid-cols-[1.15fr_420px]">
      <div class="hidden rounded-[2rem] border border-white/10 bg-slate-950/55 p-10 backdrop-blur lg:block">
        <?php if ($hasAdminAccount): ?>
          <p class="text-[11px] uppercase tracking-[0.35em] text-emerald-300/80">Visionect</p>
          <h1 class="mt-4 text-4xl font-bold tracking-tight">Display Manager</h1>
          <div class="mt-8 space-y-3 text-sm text-slate-300">
            <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4">
              <div class="text-[11px] uppercase tracking-[0.25em] text-slate-400">Display status</div>
              <div class="mt-4 grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                <div class="overflow-hidden rounded-[1.5rem] border border-white/10 bg-black/20">
                  <?php if ($loginPreviewUrl !== ''): ?>
                    <iframe src="<?php echo htmlspecialchars($loginPreviewUrl, ENT_QUOTES, 'UTF-8'); ?>" class="w-full border-0 bg-white" style="aspect-ratio: <?php echo (int)$loginFrameWidth; ?> / <?php echo (int)$loginFrameHeight; ?>;"></iframe>
                  <?php else: ?>
                    <div class="flex items-center justify-center px-4 text-center text-xs text-slate-400" style="aspect-ratio: <?php echo (int)$loginFrameWidth; ?> / <?php echo (int)$loginFrameHeight; ?>;">No live frame preview yet</div>
                  <?php endif; ?>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <div class="text-xs text-slate-400">Current module</div>
                  <div class="mt-1 text-base font-semibold text-slate-100"><?php echo htmlspecialchars($loginCurrentModule, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div>
                  <div class="text-xs text-slate-400">Last updated</div>
                  <div class="mt-1 text-base font-semibold text-slate-100"><?php echo htmlspecialchars($loginLastUpdated, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div>
                  <div class="text-xs text-slate-400">Next update</div>
                  <div class="mt-1 text-base font-semibold text-slate-100"><?php echo htmlspecialchars($loginNextUpdate, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div>
                  <div class="text-xs text-slate-400">Frame state</div>
                  <div class="mt-1 text-base font-semibold text-slate-100"><?php echo htmlspecialchars($loginStateText, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <p class="text-[11px] uppercase tracking-[0.35em] text-sky-300/80">First Run</p>
          <h1 class="mt-4 text-4xl font-bold tracking-tight">Set up Display Manager</h1>
          <p class="mt-5 max-w-xl text-base leading-relaxed text-slate-300">This looks like a brand-new install. Create the first admin account here, then use the admin to load content, review module settings, and verify the frame is rotating the way you want.</p>
          <div class="mt-8 grid gap-3 text-sm text-slate-300">
            <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4">Step 1: Create the single admin account for this display.</div>
            <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4">Step 2: Open modules like Newspaper, Comics, and AiNews and use the new manual cron buttons to fetch content right away.</div>
            <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4">Step 3: Review Schedule, Home Assistant pause settings, and live preview before leaving it unattended.</div>
          </div>
          <div class="mt-8 rounded-[1.75rem] border border-sky-500/20 bg-sky-500/10 p-5">
            <p class="text-[11px] uppercase tracking-[0.3em] text-sky-200">Fresh Install Checklist</p>
            <ul class="mt-4 space-y-3 text-sm text-slate-300">
              <li>The container can boot without an admin file now. You do not need to enter the Docker container just to create the first account.</li>
              <li>Provider secrets, like Home Assistant and AiNews API keys, can be added later from the admin and are stored encrypted at rest.</li>
              <li>If the frame looks empty on day one, save the module config and press the module's manual cron button so the latest content is downloaded immediately.</li>
              <li>After setup, this first-run screen disables itself automatically and the normal sign-in screen takes over.</li>
            </ul>
          </div>
        <?php endif; ?>
      </div>
      <div class="rounded-[2rem] border border-white/10 bg-slate-950/75 p-8 shadow-2xl backdrop-blur">
        <p class="text-[11px] uppercase tracking-[0.35em] <?php echo $hasAdminAccount ? 'text-emerald-300/80' : 'text-sky-300/80'; ?>"><?php echo $hasAdminAccount ? 'Sign In' : 'Create Admin'; ?></p>
        <h2 class="mt-3 text-2xl font-bold tracking-tight"><?php echo $hasAdminAccount ? 'Admin access required' : 'Create the first admin account'; ?></h2>
        <p class="mt-2 text-sm text-slate-400"><?php echo $hasAdminAccount ? 'Use the configured local admin account to manage the frame.' : 'Set the username and password that will control this display manager going forward.'; ?></p>
        <?php if ($loginError !== ''): ?>
          <div class="mt-5 rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" class="mt-6 space-y-4">
          <input type="hidden" name="auth_action" value="<?php echo $hasAdminAccount ? 'login' : 'create_admin'; ?>">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <label class="block text-sm text-slate-300">
            <span class="mb-2 block">Username</span>
            <input type="text" name="username" autocomplete="username" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-slate-100 outline-none" <?php echo !$hasAdminAccount ? 'value="Alex"' : ''; ?> required>
          </label>
          <label class="block text-sm text-slate-300">
            <span class="mb-2 block">Password</span>
            <input type="password" name="password" autocomplete="<?php echo $hasAdminAccount ? 'current-password' : 'new-password'; ?>" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-slate-100 outline-none" required>
          </label>
          <?php if (!$hasAdminAccount): ?>
            <label class="block text-sm text-slate-300">
              <span class="mb-2 block">Confirm password</span>
              <input type="password" name="confirm_password" autocomplete="new-password" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-slate-100 outline-none" required>
            </label>
            <p class="text-xs text-slate-400">Use at least 12 characters. The password is stored as a hash, not plain text.</p>
          <?php endif; ?>
          <button type="submit" class="w-full rounded-2xl <?php echo $hasAdminAccount ? 'bg-emerald-500 hover:bg-emerald-400' : 'bg-sky-400 hover:bg-sky-300'; ?> px-4 py-3 text-sm font-semibold text-slate-950 transition"><?php echo $hasAdminAccount ? 'Sign in' : 'Create admin account'; ?></button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
<?php
  exit;
}

$adminUsername = visionect_current_username() ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Display Manager</title>
<script src="vendor/tailwindcss-play-3.4.17.js"></script>
<script src="vendor/lucide-1.52.0.min.js"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Space Grotesk"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        panel: '#121722',
        border: '#283041',
        accent: '#ca8a04',
      }
    }
  }
};

const savedTheme = localStorage.getItem('visionect-theme') || 'dark';
document.documentElement.classList.toggle('dark', savedTheme !== 'light');
document.documentElement.classList.toggle('light', savedTheme === 'light');
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  body {
    background:
      radial-gradient(circle at top left, rgba(202,138,4,0.18), transparent 30%),
      radial-gradient(circle at bottom right, rgba(14,165,233,0.12), transparent 28%),
      #0b0f17;
  }
  .light body {
    background:
      radial-gradient(circle at top left, rgba(202,138,4,0.14), transparent 25%),
      radial-gradient(circle at bottom right, rgba(14,165,233,0.10), transparent 30%),
      #e9eef5;
  }
  .card {
    border: 1px solid rgb(40 48 65 / 1);
    background: rgba(18, 23, 34, 0.92);
    backdrop-filter: blur(14px);
  }
  .light .card {
    background: rgba(255,255,255,0.96);
    border-color: rgba(148, 163, 184, 0.65);
  }
  .light header {
    background: rgba(255, 255, 255, 0.9) !important;
    border-color: rgba(148, 163, 184, 0.5) !important;
  }
  .light .text-slate-400,
  .light .text-slate-500,
  .light .text-slate-300,
  .light .text-slate-600 {
    color: #475569 !important;
  }
  .light h2,
  .light h3,
  .light .font-semibold,
  .light .font-bold {
    color: #0f172a;
  }
  .light .text-amber-200,
  .light .text-amber-300,
  .light .text-amber-400\/80 {
    color: #92400e !important;
  }
  .light .text-emerald-300,
  .light .text-emerald-300\/80 {
    color: #166534 !important;
  }
  .light .text-rose-300 {
    color: #be123c !important;
  }
  .light .text-sky-200 {
    color: #075985 !important;
  }
  .light .bg-black\/20,
  .light .bg-black\/10,
  .light .bg-white\/5 {
    background: rgba(226, 232, 240, 0.7) !important;
  }
  .light .bg-amber-400\/10 {
    background: rgba(245, 158, 11, 0.14) !important;
  }
  .light .bg-emerald-500\/10 {
    background: rgba(34, 197, 94, 0.12) !important;
  }
  .light .bg-rose-500\/10 {
    background: rgba(244, 63, 94, 0.12) !important;
  }
  .light .border-white\/10,
  .light .border-white\/5 {
    border-color: rgba(148, 163, 184, 0.45) !important;
  }
  .light .border-amber-400\/60 {
    border-color: rgba(180, 83, 9, 0.34) !important;
  }
  .light .border-rose-500\/30 {
    border-color: rgba(225, 29, 72, 0.28) !important;
  }
  .light .border-emerald-500\/30 {
    border-color: rgba(22, 163, 74, 0.28) !important;
  }
  .light .border-sky-500\/30 {
    border-color: rgba(3, 105, 161, 0.28) !important;
  }
  .light .hover\:text-white:hover {
    color: #0f172a !important;
  }
  .light .hover\:bg-white\/5:hover {
    background: rgba(203, 213, 225, 0.58) !important;
  }
  .light .hover\:border-white\/20:hover {
    border-color: rgba(100, 116, 139, 0.42) !important;
  }
  .nav-btn.active {
    background: linear-gradient(135deg, rgba(202,138,4,0.25), rgba(202,138,4,0.08));
    color: #f8fafc;
    border-color: rgba(202,138,4,0.45);
  }
  .light .nav-btn.active {
    color: #111827;
  }
  .light header h1,
  .light #accountBtnLabel,
  .light #globalPauseLabel,
  .light #countdown {
    color: #0f172a !important;
  }
  .light #globalPauseBtn,
  .light #haSettingsBtn,
  .light #accountBtn,
  .light #themeBtn {
    color: #334155 !important;
    border-color: rgba(100, 116, 139, 0.34) !important;
    background: rgba(255, 255, 255, 0.58);
  }
  .light #globalPauseBtn:hover,
  .light #haSettingsBtn:hover,
  .light #accountBtn:hover,
  .light #themeBtn:hover {
    color: #0f172a !important;
    background: rgba(241, 245, 249, 0.96);
  }
  .light #restartBtn {
    color: #9f1239 !important;
    border-color: rgba(225, 29, 72, 0.28) !important;
    background: rgba(255, 255, 255, 0.58);
  }
  .light #countdown,
  .light #liveBadge,
  .light .card code {
    color: #1e293b;
  }
  .light button.border,
  .light a.border,
  .light label.border {
    color: #334155 !important;
    background: rgba(255, 255, 255, 0.72);
    border-color: rgba(100, 116, 139, 0.4) !important;
  }
  .light button.border:hover,
  .light a.border:hover,
  .light label.border:hover {
    color: #0f172a !important;
    background: rgba(248, 250, 252, 0.96) !important;
  }
  .light button.bg-amber-500,
  .light button.bg-emerald-500,
  .light button.bg-sky-400 {
    color: #0f172a !important;
  }
  .light input,
  .light select,
  .light textarea {
    color: #0f172a !important;
  }
  .light input::placeholder,
  .light textarea::placeholder {
    color: #64748b !important;
  }
  .preview-frame {
    aspect-ratio: var(--frame-width, 1440) / var(--frame-height, 2560);
    position: relative;
    overflow: hidden;
    background: #fff;
  }
  .preview-frame iframe {
    position: absolute;
    left: 0;
    top: 0;
    width: calc(var(--frame-width, 1440) * 1px);
    height: calc(var(--frame-height, 2560) * 1px);
    transform-origin: top left;
    border: 0;
  }
  .dragging {
    opacity: 0.45;
  }
  .comics-thumb img {
    image-rendering: -webkit-optimize-contrast;
  }
  .ui-toggle {
    position: relative;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
  }
  .ui-toggle input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }
  .ui-toggle-track {
    width: 2.35rem;
    height: 1.3rem;
    border-radius: 9999px;
    background: rgba(100, 116, 139, 0.45);
    border: 1px solid rgba(148, 163, 184, 0.35);
    transition: all 160ms ease;
    position: relative;
  }
  .ui-toggle-track::after {
    content: '';
    position: absolute;
    top: 0.08rem;
    left: 0.1rem;
    width: 0.95rem;
    height: 0.95rem;
    border-radius: 9999px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.35);
    transition: transform 160ms ease;
  }
  .ui-toggle input:checked + .ui-toggle-track {
    background: rgba(34, 197, 94, 0.9);
    border-color: rgba(34, 197, 94, 0.95);
  }
  .ui-toggle input:checked + .ui-toggle-track::after {
    transform: translateX(1rem);
  }
  .ui-toggle input:focus-visible + .ui-toggle-track {
    outline: 2px solid rgba(34, 197, 94, 0.45);
    outline-offset: 2px;
  }
  .sidebar-toggle .ui-toggle-track {
    background: rgba(71, 85, 105, 0.32);
    border-color: rgba(148, 163, 184, 0.22);
  }
  .sidebar-toggle .ui-toggle-track::after {
    background: rgba(248, 250, 252, 0.92);
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.28);
  }
  .sidebar-toggle input:checked + .ui-toggle-track {
    background: rgba(202, 138, 4, 0.26);
    border-color: rgba(202, 138, 4, 0.38);
  }
  .sidebar-toggle input:focus-visible + .ui-toggle-track {
    outline: 2px solid rgba(202, 138, 4, 0.28);
  }
  .light .sidebar-toggle .ui-toggle-track {
    background: rgba(148, 163, 184, 0.22);
    border-color: rgba(148, 163, 184, 0.34);
  }
  .light .sidebar-toggle input:checked + .ui-toggle-track {
    background: rgba(202, 138, 4, 0.22);
    border-color: rgba(202, 138, 4, 0.34);
  }
  .toast-enter {
    animation: toastIn 180ms ease-out;
  }
  .topbar-popover {
    position: absolute;
    right: 0;
    top: calc(100% + 0.75rem);
    width: min(42rem, calc(100vw - 2rem));
  }
  @keyframes toastIn {
    from { transform: translateY(10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }
</style>
</head>
<body class="min-h-screen text-slate-100 transition-colors duration-300 dark:text-slate-100 light:text-slate-900">
<div class="min-h-screen flex flex-col">
  <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/75 backdrop-blur dark:bg-slate-950/75 light:bg-white/70">
    <div class="mx-auto flex w-full max-w-[1800px] items-center justify-between gap-4 px-4 py-3 sm:px-6">
      <div>
        <p class="text-[11px] uppercase tracking-[0.35em] text-amber-400/80">Visionect</p>
        <h1 class="text-lg font-bold tracking-tight">Display Manager</h1>
      </div>
      <div class="flex items-center gap-3">
        <div class="hidden min-w-[420px] max-w-[900px] rounded-full border border-white/10 px-4 py-2.5 text-sm leading-tight tabular-nums text-amber-300 sm:block" id="countdown">Connecting…</div>
        <button id="globalPauseBtn" type="button" class="inline-flex items-center gap-2 rounded-full border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:border-amber-400/60 hover:text-white">
          <span id="globalPauseIconWrap"><i data-lucide="pause" class="h-4 w-4"></i></span>
          <span id="globalPauseLabel">Pause</span>
        </button>
        <button id="reloadCurrentBtn" type="button" onclick="reloadCurrentPage()" title="Reload current page" class="rounded-full border border-white/10 p-2 text-slate-300 transition hover:border-sky-400/60 hover:text-white">
          <i data-lucide="refresh-cw" class="h-4 w-4"></i>
        </button>
        <div class="relative">
          <button id="haSettingsBtn" type="button" class="rounded-full border border-white/10 p-2 text-slate-300 transition hover:border-amber-400/60 hover:text-white">
            <i data-lucide="settings-2" class="h-4 w-4"></i>
          </button>
          <div id="haSettingsPanel" class="topbar-popover card hidden rounded-[1.75rem] p-5 shadow-2xl">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-[11px] uppercase tracking-[0.3em] text-amber-400/80">Display Settings</p>
                <h2 class="mt-1 text-sm font-semibold">General and Home Assistant</h2>
                <p class="mt-1 text-xs text-slate-400">Set the frame resolution used for previews, plus the mirrored frame sleep window, and configure Home Assistant presence pause.</p>
              </div>
              <button type="button" onclick="closeHaPanel(event)" class="rounded-full border border-white/10 p-2 text-slate-300 transition hover:border-white/20">
                <i data-lucide="x" class="h-4 w-4"></i>
              </button>
            </div>
            <div id="haSettingsBody" class="mt-4 text-sm text-slate-300">Loading…</div>
          </div>
        </div>
        <div class="relative">
          <button id="accountBtn" type="button" class="inline-flex items-center gap-2 rounded-full border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:border-emerald-400/60 hover:text-white">
            <i data-lucide="shield-check" class="h-4 w-4"></i>
            <span id="accountBtnLabel"><?php echo htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8'); ?></span>
          </button>
          <div id="accountPanel" class="topbar-popover card hidden rounded-[1.75rem] p-5 shadow-2xl">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-[11px] uppercase tracking-[0.3em] text-emerald-300/80">Admin Account</p>
                <h2 class="mt-1 text-sm font-semibold">Account settings</h2>
                <p class="mt-1 text-xs text-slate-400">Update the single admin account or sign out of this session.</p>
              </div>
              <button type="button" onclick="closeAccountPanel(event)" class="rounded-full border border-white/10 p-2 text-slate-300 transition hover:border-white/20">
                <i data-lucide="x" class="h-4 w-4"></i>
              </button>
            </div>
            <div id="accountPanelBody" class="mt-4 text-sm text-slate-300">Loading…</div>
          </div>
        </div>
        <button id="themeBtn" type="button" class="rounded-full border border-white/10 p-2 text-slate-300 transition hover:border-amber-400/60 hover:text-white">
          <i data-lucide="sun-moon" class="h-4 w-4"></i>
        </button>
        <button id="restartBtn" type="button" class="rounded-full border border-rose-500/30 p-2 text-rose-300 transition hover:bg-rose-500/10">
          <i data-lucide="rotate-cw" class="h-4 w-4"></i>
        </button>
      </div>
    </div>
  </header>

  <div class="mx-auto flex w-full max-w-[1800px] flex-1 flex-col gap-4 px-4 py-4 lg:flex-row sm:px-6">
    <aside class="w-full shrink-0 lg:w-56">
      <nav class="card sticky top-24 overflow-x-auto rounded-3xl p-3">
        <div class="flex gap-2 lg:block lg:space-y-1" id="navList"></div>
      </nav>
    </aside>

    <main class="min-w-0 flex-1 space-y-4">
      <section id="panel-live" class="panel space-y-4"></section>
      <section id="panel-schedule" class="panel hidden space-y-4"></section>
      <section id="panel-clock" class="panel hidden space-y-4"></section>
      <section id="panel-newspaper" class="panel hidden space-y-4"></section>
      <section id="panel-art" class="panel hidden space-y-4"></section>
      <section id="panel-haynesmann" class="panel hidden space-y-4"></section>
      <section id="panel-comics" class="panel hidden space-y-4"></section>
      <section id="panel-quotes" class="panel hidden space-y-4"></section>
      <section id="panel-ainews" class="panel hidden space-y-4"></section>
    </main>
  </div>
  <div class="px-4 pb-4 text-center text-[11px] text-slate-500 sm:px-6">
    Display Manager v1.1
  </div>
</div>

<div id="toastWrap" class="fixed bottom-4 right-4 z-50 flex max-w-sm flex-col gap-2"></div>
<form id="logoutForm" method="post" class="hidden">
  <input type="hidden" name="auth_action" value="logout">
  <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
</form>

<div id="paperModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
  <div class="card flex max-h-[80vh] w-full max-w-xl flex-col rounded-3xl">
    <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
      <div>
        <h2 class="text-sm font-semibold">Add Newspaper</h2>
        <p class="text-xs text-slate-400">Pick a paper from Freedom Forum.</p>
      </div>
      <button type="button" onclick="closePaperModal()" class="rounded-full border border-white/10 p-2 text-slate-300">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
    </div>
    <div id="paperModalBody" class="overflow-y-auto p-5 text-sm text-slate-300">Loading…</div>
  </div>
</div>

<div id="comicsAuthModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" onclick="if(event.target===this)closeComicsAuthModal()">
  <div class="card flex max-h-[90vh] w-full max-w-xl flex-col rounded-3xl">
    <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
      <div>
        <h2 class="text-sm font-semibold">GoComics Cookie Settings</h2>
        <p class="text-xs text-slate-400">Manage BunnyCDN bypass cookies for GoComics.</p>
      </div>
      <button type="button" onclick="closeComicsAuthModal()" class="rounded-full border border-white/10 p-2 text-slate-300">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
    </div>
    <div class="overflow-y-auto p-5 space-y-5 text-sm text-slate-300">
      <div id="comicsAuthStatus" class="rounded-2xl border px-4 py-3 text-sm">Loading…</div>
      <div>
        <p class="text-xs text-slate-400 mb-1">The <code class="text-slate-300">cookie-refresh</code> service renews cookies automatically each day at 06:00 UTC. Use this only if auto-refresh has failed.</p>
        <p class="text-xs text-slate-400 mb-3">
          Install the <a href="https://chromewebstore.google.com/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc" target="_blank" class="text-amber-400 underline">Get cookies.txt Locally</a>
          Chrome extension → visit <span class="text-slate-300">gocomics.com</span> → click the extension → <em>Copy All</em> → paste below.
        </p>
        <label class="block text-xs text-slate-400 mb-1">Paste cookie data (write-only)</label>
        <textarea id="comicsAuthCookiePaste" rows="6"
          class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 font-mono text-xs text-slate-300 outline-none resize-y"
          placeholder="Paste Netscape cookie data here (must contain bunny_shield)&#10;&#10;Current value is never displayed."></textarea>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="submitComicsCookies()" class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-400">Save cookies</button>
        <button type="button" onclick="closeComicsAuthModal()" class="rounded-2xl border border-white/10 px-4 py-2 text-sm">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
const ADMIN_BOOT = <?php echo json_encode(['username' => $adminUsername, 'csrfToken' => $csrfToken], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
const MODULES = ['clock', 'newspaper', 'art', 'haynesmann', 'comics', 'quotes', 'ainews'];
const CRON_MODULES = ['newspaper', 'comics', 'ainews'];
const NAV_ITEMS = [
  { key: 'live', label: 'Live', icon: 'monitor-smartphone' },
  { key: 'schedule', label: 'Schedule', icon: 'calendar-range' },
  { key: 'clock', label: 'Clock', icon: 'clock-3' },
  { key: 'newspaper', label: 'Newspaper', icon: 'newspaper' },
  { key: 'art', label: 'Art', icon: 'image' },
  { key: 'haynesmann', label: 'Haynesmann', icon: 'image' },
  { key: 'comics', label: 'Comics', icon: 'book-image' },
  { key: 'quotes', label: 'Quotes', icon: 'quote' },
  { key: 'ainews', label: 'AiNews', icon: 'sparkles' },
];
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const DEFAULT_PROVIDER_ORDER = ['kie', 'gemini', 'pollinations', 'huggingface'];

const state = {
  currentPanel: 'live',
  prefs: null,
  configs: {},
  galleries: {},
  comicsPreview: null,
  ainewsPreview: null,
  newspaperPreview: null,
  socket: null,
  paused: false,
  countdown: null,
  countdownInterval: null,
  currentPage: null,
  nextPage: null,
  currentUrl: null,
  currentTimeslot: null,
  previewModule: null,
  papers: null,
  dragSlug: null,
  previewVersion: Date.now(),
  generalConfig: null,
  haConfig: null,
  haStatus: null,
  runningCronModules: {},
  runtimeStatus: null,
  activity: 'home',
  dirtySections: new Set(),
  statusPollInterval: null,
  frameSyncTimeout: null,
  account: {
    username: ADMIN_BOOT.username,
  },
  lastFrameExactUrl: null,
  lastFrameModule: null,
  // Unsaved schedule edits live here; state.prefs.timeslots always mirrors what is saved.
  scheduleDraft: null,
  dirtySlots: new Set(),
  // null = daemon sent no auth result (older visionectd), true/false = explicit result.
  wsAuthed: null,
  wsReconnectTimer: null,
};

const GALLERY_TITLES = { art: 'Art', haynesmann: 'Haynesmann', quotes: 'Quotes' };

function frameWidth() {
  return Math.max(1, Number(state.generalConfig?.frame_width || 1440));
}

function frameHeight() {
  return Math.max(1, Number(state.generalConfig?.frame_height || 2560));
}

function frameAspectRatio() {
  return `${frameWidth()} / ${frameHeight()}`;
}

function frameResolutionLabel() {
  return `${frameWidth()}×${frameHeight()}`;
}

function framePreviewStyle(extra = '') {
  return `aspect-ratio:${frameAspectRatio()};${extra}`;
}

function applyFramePreviewMetrics() {
  document.documentElement.style.setProperty('--frame-width', String(frameWidth()));
  document.documentElement.style.setProperty('--frame-height', String(frameHeight()));
}

function el(id) {
  return document.getElementById(id);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function icon(name, extra = 'h-4 w-4') {
  return `<i data-lucide="${name}" class="${extra}"></i>`;
}

function normalizeNameFromPrefix(prefix) {
  return String(prefix || '')
    .replaceAll('_', ' ')
    .trim()
    .replace(/\b\w/g, char => char.toUpperCase());
}

function toast(message, kind = 'success') {
  const wrap = el('toastWrap');
  const node = document.createElement('div');
  const classes = {
    error: 'border-rose-500/30 bg-rose-950/90 text-rose-100',
    warning: 'border-amber-400/40 bg-amber-950/90 text-amber-100',
    info: 'border-sky-500/30 bg-sky-950/90 text-sky-100',
  }[kind] || 'border-emerald-500/30 bg-emerald-950/90 text-emerald-100';
  node.className = `toast-enter rounded-2xl border px-4 py-3 text-sm shadow-xl ${classes}`;
  node.textContent = message;
  wrap.appendChild(node);
  setTimeout(() => node.remove(), kind === 'success' ? 2800 : 5000);
}

function clone(value) {
  return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

function markDirty(section) {
  if (!section) return;
  state.dirtySections.add(section);
  renderDirtySections();
}

function clearDirty(section) {
  if (!section) return;
  state.dirtySections.delete(section);
  renderDirtySections();
}

function isDirty(section) {
  return state.dirtySections.has(section);
}

function dirtyNotice(section, text) {
  if (!isDirty(section)) return '';
  return `
    <div class="rounded-2xl border border-amber-400/60 bg-amber-400/10 px-4 py-3 text-sm text-amber-200">
      ${escapeHtml(text)}
    </div>
  `;
}

function renderDirtySections() {
  const sectionTexts = {
    schedule: 'Schedule changes are unsaved.',
    clock: 'Clock changes are unsaved.',
    newspaper: 'Newspaper changes are unsaved. Save before running cron.',
    art: 'Art rotation changes are unsaved.',
    haynesmann: 'Haynesmann rotation changes are unsaved.',
    comics: 'Comics changes are unsaved. Save before running cron.',
    quotes: 'Quotes rotation changes are unsaved.',
    ainews: 'AiNews changes are unsaved. Save before running cron.',
  };
  Object.entries(sectionTexts).forEach(([section, text]) => {
    const node = el(`dirty-notice-${section}`);
    if (!node) return;
    node.innerHTML = dirtyNotice(section, text);
    node.classList.toggle('hidden', !isDirty(section));
  });
  CRON_MODULES.forEach(updateCronButton);
  if (state.currentPanel === 'live') {
    const healthNode = el('systemHealthCard');
    if (healthNode) {
      healthNode.outerHTML = renderSystemHealthCard();
      lucide.createIcons();
    }
  }
}

function formatRelativeTime(value) {
  if (!value) return 'Never';
  const then = new Date(value);
  if (Number.isNaN(then.getTime())) return 'Unknown';
  const diff = Math.max(0, Math.round((Date.now() - then.getTime()) / 1000));
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

function freshnessLabel(value, emptyText = 'No data yet') {
  return value ? `Updated ${formatRelativeTime(value)}` : emptyText;
}

function buildHealthWarnings() {
  const warnings = [];
  if (!state.newspaperPreview?.papers?.some(paper => paper.file && paper.enabled !== false)) {
    warnings.push('Newspaper preview has no enabled downloaded front pages yet.');
  }
  if (!state.comicsPreview?.farside?.length && !state.comicsPreview?.strips?.length) {
    warnings.push('Comics preview has no generated strips yet.');
  }
  if ((state.comicsPreview?.warnings || []).length) {
    warnings.push('One or more comic sources are blocked or missing and are using stale/manual strips.');
  }
  if (!state.ainewsPreview?.stories?.length) {
    warnings.push('AiNews has no generated stories yet.');
  }
  return warnings;
}

function dirtySectionForTarget(target) {
  if (!target) return null;
  if (target.closest('[data-rotation]')) {
    return target.closest('[data-rotation]')?.dataset.rotation || null;
  }
  if (target.dataset?.slot) {
    state.dirtySlots.add(target.dataset.slot);
    return 'schedule';
  }
  if (target.matches?.('[data-clock-style]')) return 'clock';
  if (target.matches?.('[data-paper-style],[data-paper-prefix],[data-paper-enabled]')) return 'newspaper';
  if (target.id && target.id.startsWith('comics-gap-')) return 'comics';
  if (target.matches?.('[data-strip-enabled],[data-strip-fetch-mode],[data-strip-image-url]')) return 'comics';
  if (target.matches?.('[data-source-label],[data-source-feed],[data-ainews-field],[data-ainews-secret],[data-ainews-clear]')) return 'ainews';
  if (target.id === 'newComicUrl') return 'comics';
  return null;
}

async function api(action, { method = 'GET', body, query = '' } = {}) {
  const url = `api.php?action=${encodeURIComponent(action)}${query ? '&' + query : ''}`;
  const options = { method, headers: {} };
  if (method !== 'GET') {
    options.headers['X-CSRF-Token'] = ADMIN_BOOT.csrfToken;
  }
  if (body instanceof FormData) {
    options.body = body; // browser sets the multipart boundary
  } else if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }
  const response = await fetch(url, options);
  const text = await response.text();
  let data = null;
  try {
    data = text.trim() === '' ? null : JSON.parse(text);
  } catch (error) {
    data = null;
  }
  if (!data || typeof data !== 'object') {
    throw new Error(`Invalid server response (HTTP ${response.status})`);
  }
  if (!response.ok || data.error) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }
  return data;
}

function renderNav() {
  const topItems = NAV_ITEMS.filter(item => item.key === 'live' || item.key === 'schedule');
  const moduleItems = NAV_ITEMS.filter(item => item.key !== 'live' && item.key !== 'schedule');
  el('navList').innerHTML = `
    <div class="space-y-2">
      ${topItems.map(item => `
        <button type="button"
          data-panel="${item.key}"
          class="nav-btn ${state.currentPanel === item.key ? 'active' : ''} flex w-full items-center gap-3 rounded-2xl border border-white/5 px-3 py-3 text-left text-sm text-slate-300 transition hover:border-white/10 hover:bg-white/5 hover:text-white lg:w-full">
          ${icon(item.icon)}
          <span>${item.label}</span>
        </button>
      `).join('')}
    </div>
    <div class="my-3 flex items-center gap-3 px-2">
      <div class="h-px flex-1 bg-white/10"></div>
      <span class="text-[11px] uppercase tracking-[0.3em] text-slate-500">Modules</span>
      <div class="h-px flex-1 bg-white/10"></div>
    </div>
    <div class="space-y-2">
      ${moduleItems.map(item => `
        <div class="nav-btn ${state.currentPanel === item.key ? 'active' : ''} flex w-full items-center justify-between gap-3 rounded-2xl border border-white/5 px-3 py-3 text-left text-sm text-slate-300 transition hover:border-white/10 hover:bg-white/5 hover:text-white lg:w-full">
          <button type="button"
            data-panel="${item.key}"
            class="flex min-w-0 flex-1 items-center gap-3 text-left">
            ${icon(item.icon)}
            <span class="${isModuleEnabled(item.key) ? '' : 'opacity-55'}">${item.label}</span>
          </button>
          <label class="ui-toggle sidebar-toggle shrink-0" onclick="event.stopPropagation()">
            <input
              type="checkbox"
              data-sidebar-module="${item.key}"
              ${isModuleEnabled(item.key) ? 'checked' : ''}
              onchange="toggleSidebarModule('${item.key}', this.checked)">
            <span class="ui-toggle-track"></span>
          </label>
        </div>
      `).join('')}
    </div>
  `;
  document.querySelectorAll('[data-panel]').forEach(node => {
    node.onclick = () => showPanel(node.dataset.panel);
  });
  lucide.createIcons();
}

function renderHaPanel() {
  const wrap = el('haSettingsBody');
  if (!wrap) return;
  if (!state.haConfig || !state.generalConfig) {
    wrap.innerHTML = '<div class="text-sm text-slate-400">Loading settings…</div>';
    return;
  }

  const status = state.haStatus
    ? `<div class="rounded-2xl border ${state.haStatus.kind === 'error' ? 'border-rose-500/30 bg-rose-500/10 text-rose-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100'} px-3 py-2 text-xs">${escapeHtml(state.haStatus.message)}</div>`
    : '<div class="rounded-2xl border border-white/10 bg-black/10 px-3 py-2 text-xs text-slate-400">No test run yet.</div>';

  wrap.innerHTML = `
    <div class="space-y-4">
      <section class="rounded-[1.5rem] border border-white/10 bg-black/10 p-4">
        <div class="mb-4">
          <p class="text-[11px] uppercase tracking-[0.28em] text-slate-500">General</p>
          <h3 class="mt-1 text-sm font-semibold text-slate-100">Frame resolution</h3>
          <p class="mt-1 text-xs text-slate-400">Used for preview aspect ratio, image preparation defaults, and mirrored frame sleep state. The default for this display is 1440 × 2560.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Frame width</span>
            <input id="general-frame-width" type="number" min="600" max="4000" value="${escapeHtml(state.generalConfig.frame_width || 1440)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Frame height</span>
            <input id="general-frame-height" type="number" min="800" max="5000" value="${escapeHtml(state.generalConfig.frame_height || 2560)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
          </label>
          <label class="flex items-center justify-between rounded-3xl border border-white/10 bg-black/10 px-4 py-3 text-sm sm:col-span-2">
            <div>
              <div class="font-medium text-slate-200">Mirror frame sleep schedule</div>
              <div class="mt-1 text-xs text-slate-400">This does not change the panel's hardware sleep setting. It mirrors the times already configured on the frame so the admin reflects reality.</div>
            </div>
            <span class="ui-toggle">
              <input id="general-sleep-enabled" type="checkbox" ${state.generalConfig.sleep_enabled ? 'checked' : ''}>
              <span class="ui-toggle-track"></span>
            </span>
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Wake time</span>
            <input id="general-wake-time" type="text" value="${escapeHtml(state.generalConfig.wake_time || '08:00')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="08:00">
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Sleep time</span>
            <input id="general-sleep-time" type="text" value="${escapeHtml(state.generalConfig.sleep_time || '23:00')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="23:00">
          </label>
          <div class="text-xs text-slate-400 sm:col-span-2">
            <span class="mb-1 block">control.php token (optional)</span>
            <input id="general-control-token" type="password" autocomplete="new-password" value="" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="${state.generalConfig.secrets?.control_token?.set ? 'Saved. Leave blank to keep it' : 'Not set: control.php is open to the local network'}">
            <span class="mt-1 block text-[11px] text-slate-500">When set, Home Assistant / Node-RED must send it as the X-Control-Token header or a token parameter.</span>
            ${state.generalConfig.secrets?.control_token?.set ? `
            <label class="mt-1 inline-flex items-center gap-2 text-[11px] text-slate-400">
              <input id="general-control-token-clear" type="checkbox"> Clear the saved token
            </label>` : ''}
          </div>
        </div>
        <div class="mt-4 flex justify-end">
          <button type="button" onclick="saveGeneralConfig()" class="rounded-full bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save general settings</button>
        </div>
      </section>
      <section class="rounded-[1.5rem] border border-white/10 bg-black/10 p-4">
        <div class="mb-4">
          <p class="text-[11px] uppercase tracking-[0.28em] text-slate-500">Home Assistant</p>
          <h3 class="mt-1 text-sm font-semibold text-slate-100">Presence pause</h3>
          <p class="mt-1 text-xs text-slate-400">Pause the frame when the entity is away from home.</p>
        </div>
        <label class="flex items-center justify-between rounded-3xl border border-white/10 bg-black/10 px-4 py-3 text-sm">
          <div>
            <div class="font-medium text-slate-200">Enable Home Assistant pause</div>
            <div class="mt-1 text-xs text-slate-400">When enabled, the frame stays paused unless the entity equals the home state.</div>
          </div>
          <span class="ui-toggle">
            <input id="ha-enabled" type="checkbox" ${state.haConfig.enabled ? 'checked' : ''}>
            <span class="ui-toggle-track"></span>
          </span>
        </label>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="text-xs text-slate-400 sm:col-span-2">
            <span class="mb-1 block">Base URL</span>
            <input id="ha-base-url" type="text" value="${escapeHtml(state.haConfig.base_url || '')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="http://homeassistant.local:8123">
          </label>
          <label class="text-xs text-slate-400 sm:col-span-2">
            <span class="mb-1 block">Entity ID</span>
            <input id="ha-entity-id" type="text" value="${escapeHtml(state.haConfig.entity_id || '')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="device_tracker.someone_phone">
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Home state</span>
            <input id="ha-home-state" type="text" value="${escapeHtml(state.haConfig.home_state || '')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="home">
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Timeout (sec)</span>
            <input id="ha-timeout" type="number" min="2" max="30" value="${escapeHtml(state.haConfig.timeout || 10)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
          </label>
          <div class="text-xs text-slate-400 sm:col-span-2">
            <span class="mb-1 block">Long-lived access token</span>
            <input id="ha-access-token" type="password" autocomplete="new-password" value="" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="${state.haConfig.secrets?.access_token?.set ? 'Saved. Leave blank to keep it' : 'Paste Home Assistant token'}">
            ${state.haConfig.secrets?.access_token?.set ? `
            <label class="mt-1 inline-flex items-center gap-2 text-[11px] text-slate-400">
              <input id="ha-access-token-clear" type="checkbox"> Clear the saved token
            </label>` : ''}
          </div>
        </div>
        <div class="mt-3">${status}</div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" onclick="toggleHaTokenVisibility()" class="rounded-full border border-white/10 px-4 py-2 text-sm">Show token</button>
          <button type="button" onclick="testHaConfig()" class="rounded-full border border-sky-500/30 px-4 py-2 text-sm text-sky-200">Test connection</button>
          <button type="button" onclick="saveHaConfig()" class="rounded-full bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save Home Assistant settings</button>
        </div>
      </section>
    </div>
  `;
}

function openHaPanel(event) {
  event?.stopPropagation();
  closeAccountPanel();
  el('haSettingsPanel')?.classList.remove('hidden');
  renderHaPanel();
  lucide.createIcons();
}

function closeHaPanel(event) {
  event?.stopPropagation();
  el('haSettingsPanel')?.classList.add('hidden');
}

function toggleHaPanel(event) {
  event?.stopPropagation();
  const panel = el('haSettingsPanel');
  if (!panel) return;
  if (panel.classList.contains('hidden')) {
    openHaPanel();
  } else {
    closeHaPanel();
  }
}

function readHaForm() {
  return {
    enabled: !!el('ha-enabled')?.checked,
    base_url: el('ha-base-url')?.value?.trim() || '',
    entity_id: el('ha-entity-id')?.value?.trim() || '',
    home_state: el('ha-home-state')?.value?.trim() || '',
    // Write-only: blank keeps the saved token server-side.
    access_token: el('ha-access-token')?.value || '',
    clear_secrets: el('ha-access-token-clear')?.checked ? ['access_token'] : [],
    timeout: Number(el('ha-timeout')?.value || 10),
  };
}

function readGeneralForm() {
  return {
    frame_width: Number(el('general-frame-width')?.value || 1440),
    frame_height: Number(el('general-frame-height')?.value || 2560),
    sleep_enabled: !!el('general-sleep-enabled')?.checked,
    wake_time: el('general-wake-time')?.value?.trim() || '',
    sleep_time: el('general-sleep-time')?.value?.trim() || '',
    // Write-only: blank keeps the saved control token server-side.
    control_token: el('general-control-token')?.value?.trim() || '',
    clear_secrets: el('general-control-token-clear')?.checked ? ['control_token'] : [],
  };
}

function toggleHaTokenVisibility() {
  const input = el('ha-access-token');
  if (!input) return;
  input.type = input.type === 'password' ? 'text' : 'password';
}

function renderAccountPanel() {
  const wrap = el('accountPanelBody');
  if (!wrap) return;

  wrap.innerHTML = `
    <div class="space-y-4">
      <label class="text-xs text-slate-400">
        <span class="mb-1 block">Username</span>
        <input id="account-username" type="text" value="${escapeHtml(state.account.username || '')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
      </label>
      <label class="text-xs text-slate-400">
        <span class="mb-1 block">Current password</span>
        <input id="account-current-password" type="password" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="Required to save changes">
      </label>
      <label class="text-xs text-slate-400">
        <span class="mb-1 block">New password</span>
        <input id="account-new-password" type="password" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="Leave blank to keep current password">
      </label>
      <label class="text-xs text-slate-400">
        <span class="mb-1 block">Confirm new password</span>
        <input id="account-confirm-password" type="password" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="Re-enter new password">
      </label>
      <div class="flex flex-wrap justify-between gap-2">
        <button type="button" onclick="logoutAdmin()" class="rounded-full border border-rose-500/30 px-4 py-2 text-sm text-rose-200">Log out</button>
        <button type="button" onclick="saveAccountSettings()" class="rounded-full bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-emerald-400">Save account</button>
      </div>
    </div>
  `;
}

function openAccountPanel(event) {
  event?.stopPropagation();
  closeHaPanel();
  el('accountPanel')?.classList.remove('hidden');
  renderAccountPanel();
  lucide.createIcons();
}

function closeAccountPanel(event) {
  event?.stopPropagation();
  el('accountPanel')?.classList.add('hidden');
}

function toggleAccountPanel(event) {
  event?.stopPropagation();
  const panel = el('accountPanel');
  if (!panel) return;
  if (panel.classList.contains('hidden')) {
    openAccountPanel();
  } else {
    closeAccountPanel();
  }
}

async function saveAccountSettings() {
  const username = el('account-username')?.value?.trim() || '';
  const currentPassword = el('account-current-password')?.value || '';
  const newPassword = el('account-new-password')?.value || '';
  const confirmPassword = el('account-confirm-password')?.value || '';

  if (!username) {
    toast('Username is required', 'error');
    return;
  }
  if (!currentPassword) {
    toast('Current password is required to save account changes', 'error');
    return;
  }
  if (newPassword && newPassword !== confirmPassword) {
    toast('New password and confirmation do not match', 'error');
    return;
  }

  try {
    const data = await api('account', {
      method: 'POST',
      body: {
        username,
        current_password: currentPassword,
        new_password: newPassword,
      },
    });
    state.account.username = data.username || username;
    el('accountBtnLabel').textContent = state.account.username;
    renderAccountPanel();
    toast('Account settings saved');
  } catch (error) {
    toast(error.message, 'error');
  }
}

function logoutAdmin() {
  el('logoutForm')?.submit();
}

const PANEL_RENDERERS = {
  live: () => renderLive(),
  schedule: () => renderSchedule(),
  clock: () => renderClock(),
  newspaper: () => renderNewspaper(),
  art: () => renderGalleryPanel('art', GALLERY_TITLES.art),
  haynesmann: () => renderGalleryPanel('haynesmann', GALLERY_TITLES.haynesmann),
  comics: () => renderComics(),
  quotes: () => renderGalleryPanel('quotes', GALLERY_TITLES.quotes),
  ainews: () => renderAiNews(),
};

// Render one panel. Panels with unsaved edits are left alone unless force is set, and
// only the visible panel is ever rendered (hidden ones are rebuilt when shown).
function renderPanel(panel, { force = false } = {}) {
  const node = el(`panel-${panel}`);
  if (!node || !PANEL_RENDERERS[panel] || !state.prefs) return;
  if (panel !== state.currentPanel) return;
  if (!force && isDirty(panel) && node.innerHTML.trim() !== '') return;
  PANEL_RENDERERS[panel]();
  lucide.createIcons();
  setTimeout(syncPreviewScale, 0);
}

function renderCurrentPanel(options) {
  renderPanel(state.currentPanel, options);
}

// Re-render whichever of these panels is visible (dirty ones are skipped).
function refreshPanels(panels) {
  if (panels.includes(state.currentPanel)) {
    renderCurrentPanel();
  }
}

function showPanel(panel) {
  const previous = state.currentPanel;
  state.currentPanel = panel;
  document.querySelectorAll('.panel').forEach(node => node.classList.add('hidden'));
  // Drop the DOM (iframes, images) of the panel we leave unless it holds unsaved edits.
  if (previous && previous !== panel && !isDirty(previous)) {
    const prevNode = el(`panel-${previous}`);
    if (prevNode) prevNode.innerHTML = '';
  }
  el(`panel-${panel}`).classList.remove('hidden');
  renderPanel(panel);
  setTimeout(syncPreviewScale, 0);
  renderNav();
}

function pageConfig(module) {
  return state.prefs?.pages?.[module] || { chance: 1, dynamic: true, duration: 1, enabled: true, url: `${module}/` };
}

function isModuleEnabled(module) {
  return pageConfig(module).enabled !== false;
}

function enabledModules() {
  return Object.keys(state.prefs?.pages || {}).filter(module => isModuleEnabled(module));
}

function moduleSupportsCron(module) {
  return CRON_MODULES.includes(module);
}

function moduleCronVerb(module) {
  return module === 'ainews' ? 'Generate latest now' : 'Run cron now';
}

function isConfiguredSleepWindowActive() {
  const cfg = state.generalConfig || {};
  const wake = String(cfg.wake_time || '').trim();
  const sleep = String(cfg.sleep_time || '').trim();
  if (!cfg.sleep_enabled || !isValidTimeText(wake) || !isValidTimeText(sleep)) {
    return false;
  }
  if (wake === sleep) {
    return false;
  }

  const now = new Date();
  const hm = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  return sleep > wake
    ? (hm >= sleep || hm < wake)
    : (hm >= sleep && hm < wake);
}

function isRuntimeSleeping() {
  return (state.runtimeStatus?.display?.activity || state.activity) === 'sleep' || isConfiguredSleepWindowActive();
}

function liveBadgeState() {
  if (isRuntimeSleeping()) {
    return {
      label: 'Sleeping',
      className: 'rounded-full border border-white/10 px-3 py-1 text-xs text-sky-300',
    };
  }
  if (state.paused) {
    return {
      label: 'Paused',
      className: 'rounded-full border border-white/10 px-3 py-1 text-xs text-rose-300',
    };
  }
  return {
    label: 'Running',
    className: 'rounded-full border border-white/10 px-3 py-1 text-xs text-emerald-300',
  };
}

function nextWakeLabel() {
  const cfg = state.generalConfig || {};
  if (!cfg.sleep_enabled || !cfg.wake_time) {
    return 'the configured wake time';
  }
  return cfg.wake_time;
}

function nextWakeSeconds() {
  const cfg = state.generalConfig || {};
  const wake = String(cfg.wake_time || '').trim();
  if (!cfg.sleep_enabled || !isValidTimeText(wake)) {
    return null;
  }

  const [hours, minutes] = wake.split(':').map(Number);
  const now = new Date();
  const next = new Date(now);
  next.setHours(hours, minutes, 0, 0);
  if (next.getTime() <= now.getTime()) {
    next.setDate(next.getDate() + 1);
  }
  return Math.max(0, Math.round((next.getTime() - now.getTime()) / 1000));
}

function ensureAtLeastOneModuleEnabled(candidateModule, nextEnabled) {
  if (nextEnabled) return true;
  const enabledCount = enabledModules().length;
  if (enabledCount <= 1 && isModuleEnabled(candidateModule)) {
    toast('At least one module needs to stay enabled', 'error');
    return false;
  }
  return true;
}

function moduleSaveAction(module) {
  if (module === 'clock') {
    return { label: 'Save settings', handler: 'saveClock()' };
  }
  if (module === 'newspaper') {
    return { label: 'Save settings', handler: 'saveNewspaper()' };
  }
  if (module === 'comics') {
    return { label: 'Save settings', handler: 'saveComics()' };
  }
  if (module === 'ainews') {
    return { label: 'Save settings', handler: 'saveAiNews()' };
  }
  if (['art', 'haynesmann', 'quotes'].includes(module)) {
    return { label: 'Save settings', handler: `saveRotationOnly('${module}')` };
  }
  return { label: 'Save settings', handler: `saveRotationOnly('${module}')` };
}

function cronButtonInner(module) {
  const running = !!state.runningCronModules[module];
  return `
    ${icon(running ? 'loader-circle' : 'play-circle')}
    <span>${running ? 'Running…' : (isDirty(module) ? 'Save before run' : moduleCronVerb(module))}</span>
  `;
}

// Update only the cron button of a module (no panel re-render, so edits survive).
function updateCronButton(module) {
  const button = el(`cron-btn-${module}`);
  if (!button) return;
  button.disabled = !!state.runningCronModules[module] || isDirty(module);
  button.innerHTML = cronButtonInner(module);
  lucide.createIcons();
}

function updateCronStatusUI(module) {
  updateCronButton(module);
  const healthNode = el('systemHealthCard');
  if (healthNode) {
    healthNode.outerHTML = renderSystemHealthCard();
    lucide.createIcons();
  }
}

function rotationCard(module, title, details = '', options = {}) {
  const page = pageConfig(module);
  const showDynamic = options.showDynamic !== false;
  const saveAction = moduleSaveAction(module);
  const cronAction = moduleSupportsCron(module)
    ? `
      <button
        type="button"
        id="cron-btn-${module}"
        onclick="runModuleCron('${module}')"
        ${(state.runningCronModules[module] || isDirty(module)) ? 'disabled' : ''}
        class="inline-flex items-center gap-2 rounded-full border border-sky-500/30 px-3 py-1.5 text-xs font-medium text-sky-200 transition hover:bg-sky-500/10 disabled:cursor-not-allowed disabled:opacity-60">
        ${cronButtonInner(module)}
      </button>
    `
    : '';
  return `
    <div class="card rounded-3xl p-5">
      <div class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">${title}</h3>
          ${details ? `<p class="mt-1 text-xs text-slate-400">${details}</p>` : ''}
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            onclick="${saveAction.handler}"
            class="inline-flex items-center gap-2 rounded-full border border-amber-400/30 px-3 py-1.5 text-xs font-medium text-amber-200 transition hover:bg-amber-400/10">
            ${icon('save')}
            <span>${saveAction.label}</span>
          </button>
          ${cronAction}
        </div>
      </div>
      <div class="grid gap-3 ${showDynamic ? 'sm:grid-cols-4' : 'sm:grid-cols-3'}">
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Chance</span>
          <input data-rotation="${module}" data-field="chance" type="number" min="1" max="10" value="${page.chance}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Duration (min)</span>
          <input data-rotation="${module}" data-field="duration" type="number" min="1" value="${page.duration}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        ${showDynamic ? `
        <label class="flex items-center justify-between rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm">
          <span class="text-slate-300">Allow repeats</span>
          <span class="ui-toggle">
            <input data-rotation="${module}" data-field="dynamic" type="checkbox" ${page.dynamic ? 'checked' : ''}>
            <span class="ui-toggle-track"></span>
          </span>
        </label>
        ` : ''}
        <label class="flex items-center justify-between rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm">
          <span class="text-slate-300">Module enabled</span>
          <span class="ui-toggle">
            <input data-rotation="${module}" data-field="enabled" type="checkbox" ${page.enabled !== false ? 'checked' : ''}>
            <span class="ui-toggle-track"></span>
          </span>
        </label>
      </div>
    </div>
  `;
}

function readRotation(module) {
  const chance = Number(document.querySelector(`[data-rotation="${module}"][data-field="chance"]`)?.value || 1);
  const duration = Number(document.querySelector(`[data-rotation="${module}"][data-field="duration"]`)?.value || 1);
  const dynamicInput = document.querySelector(`[data-rotation="${module}"][data-field="dynamic"]`);
  const dynamic = dynamicInput ? !!dynamicInput.checked : !!state.prefs.pages[module].dynamic;
  const enabledInput = document.querySelector(`[data-rotation="${module}"][data-field="enabled"]`);
  const enabled = !!enabledInput?.checked;
  if (!ensureAtLeastOneModuleEnabled(module, enabled)) {
    if (enabledInput) enabledInput.checked = true;
    throw new Error('At least one module needs to stay enabled');
  }
  state.prefs.pages[module].chance = Math.max(1, Math.min(10, chance));
  state.prefs.pages[module].duration = Math.max(1, duration);
  state.prefs.pages[module].dynamic = dynamic;
  state.prefs.pages[module].enabled = enabled;
}

function socketReady() {
  // An explicit auth failure means the daemon ignores our tasks even though the socket is open.
  return state.socket?.readyState === WebSocket.OPEN && state.wsAuthed !== false;
}

function sendSocketTask(payload) {
  if (!socketReady()) return false;
  state.socket.send(JSON.stringify(payload));
  return true;
}

// Posts the given prefs (default: the saved state.prefs, never the schedule draft).
async function savePrefs(reload = true, prefs = state.prefs) {
  await api('prefs', { method: 'POST', body: prefs });
  if (reload && !sendSocketTask({ task: 'reloadPrefs' })) {
    toast('Saved, but the display is not connected so it did not reload prefs yet.', 'warning');
  }
}

function pruneDisabledFromTimeslots(prefs = state.prefs) {
  Object.keys(prefs.timeslots || {}).forEach(slot => {
    prefs.timeslots[slot].pages = (prefs.timeslots[slot].pages || []).filter(page => prefs.pages?.[page]?.enabled !== false);
  });
}

function fixCurrentPageAfterToggle(module) {
  if (state.currentPage === module && !isModuleEnabled(module)) {
    state.currentPage = enabledModules()[0] || null;
    state.currentUrl = state.currentPage ? state.prefs.pages[state.currentPage].url : null;
  }
}

async function refreshModuleAfterCron(module) {
  await loadSystemStatus();
  if (module === 'newspaper') {
    await loadNewspaperPreview();
  } else if (module === 'comics') {
    await loadComicsPreview();
  } else if (module === 'ainews') {
    await loadAiNewsPreview();
  }
  state.previewVersion = Date.now();
  refreshPanels([module]);

  if (state.currentPage === module) {
    refreshLivePreview(true);
    window.setTimeout(() => refreshLivePreview(true), 700);
  }
}

async function runModuleCron(module) {
  if (!moduleSupportsCron(module) || state.runningCronModules[module]) return;
  state.runningCronModules[module] = true;
  updateCronStatusUI(module);
  toast(`Running ${module} update…`, 'info');
  try {
    const result = await api('run_module_cron', {
      method: 'POST',
      query: `module=${encodeURIComponent(module)}`,
      body: { module },
    });
    if (!result.ok) {
      const exitCode = Number.isInteger(result.exit_code) ? result.exit_code : 'unknown';
      try {
        await refreshModuleAfterCron(module);
      } catch (_) {
        // Keep the cron failure visible even if the status refresh also fails.
      }
      throw new Error(`${module} cron failed with exit code ${exitCode}.`);
    }
    await refreshModuleAfterCron(module);
    const output = String(result.output || '').trim();
    const summary = output
      ? output.split('\n').map(line => line.trim()).filter(Boolean).slice(-1)[0]
      : 'Cron finished';
    toast(`${module} updated. ${summary}`);
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    delete state.runningCronModules[module];
    updateCronStatusUI(module);
  }
}

function renderLive() {
  const sleeping = isRuntimeSleeping();
  const pageOptions = enabledModules().map(page => `<option value="${page}" ${state.currentPage === page ? 'selected' : ''}>${page}</option>`).join('');
  const previewOptions = enabledModules().map(page => `<option value="${page}" ${state.previewModule === page ? 'selected' : ''}>${page}</option>`).join('');
  const frameUrl = currentFramePreviewUrl() || '/';
  const frameSeenText = state.runtimeStatus?.frame?.last_seen_at
    ? `Served ${formatRelativeTime(state.runtimeStatus.frame.last_seen_at)}`
    : 'Waiting for frame request…';
  const nextUrl = selectedModulePreviewUrl();
  state.lastFrameExactUrl = state.runtimeStatus?.frame?.exact_url || null;
  state.lastFrameModule = state.runtimeStatus?.frame?.last_module || null;
  const liveState = liveBadgeState();
  el('panel-live').innerHTML = `
    <div class="space-y-4">
      <div class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_380px]">
        ${renderSystemHealthCard()}
        <div class="card rounded-[2rem] p-5">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-[11px] uppercase tracking-[0.35em] text-amber-400/80">Live Control</p>
              <h2 class="mt-1 text-xl font-bold">What the display is doing right now</h2>
            </div>
            <div class="${liveState.className}" id="liveBadge">
              ${liveState.label}
            </div>
          </div>
          <div class="mt-5 space-y-3">
            ${sleeping ? `<div class="rounded-2xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">The frame is asleep right now, so live reload and jump actions will not reach it until it wakes at ${escapeHtml(nextWakeLabel())}.</div>` : ''}
            <label class="text-xs text-slate-400">
              <span class="mb-1 block whitespace-nowrap">Jump to page</span>
              <select id="pageSelect" ${sleeping ? 'disabled' : ''} class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none disabled:cursor-not-allowed disabled:opacity-60">${pageOptions}</select>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
              <button type="button" onclick="goToPage()" ${sleeping ? 'disabled' : ''} class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-60">Go now</button>
              <button type="button" onclick="revertToSchedule()" ${sleeping ? 'disabled' : ''} class="rounded-2xl border border-white/10 px-4 py-2 text-sm transition hover:border-white/20 disabled:cursor-not-allowed disabled:opacity-60">Revert to schedule</button>
            </div>
          </div>
        </div>
      </div>
      <div class="card rounded-[2rem] p-4">
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <div class="mb-2 flex items-center justify-between gap-3 text-xs uppercase tracking-[0.25em] text-slate-500">
              <span>Current module</span>
              <button type="button" onclick="reloadSelectedModulePreview()" class="inline-flex items-center gap-1 rounded-full border border-white/10 px-2 py-1 normal-case tracking-normal text-slate-300 transition hover:border-white/20 hover:text-white">
                ${icon('refresh-cw', 'h-3.5 w-3.5')}
                <span>Reload</span>
              </button>
            </div>
            <label class="mb-3 block text-xs text-slate-400">
              <select id="previewModuleSelect" onchange="changePreviewModule(this.value)" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">${previewOptions}</select>
            </label>
            <div class="preview-frame rounded-[1.5rem] border border-white/10 shadow-2xl">
              ${nextUrl
                ? `<iframe id="nextPreviewFrame" src="${nextUrl}" loading="eager"></iframe>`
                : '<div class="flex h-full items-center justify-center text-sm text-slate-400">No preview module selected</div>'}
            </div>
          </div>
          <div class="pt-[2.45rem]">
            <div class="mb-2">
              <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Current frame</div>
              <div class="mt-1 text-xs text-slate-400">${escapeHtml(frameSeenText)}</div>
            </div>
            <div class="preview-frame rounded-[1.5rem] border border-white/10 shadow-2xl">
              <iframe id="previewFrame" src="${frameUrl}" loading="eager"></iframe>
            </div>
          </div>
        </div>
        <p class="mt-3 text-xs text-slate-400">Scaled from the configured ${escapeHtml(frameResolutionLabel())} frame for a truer preview. Use Current module to test-render any enabled module without changing what is on the frame.</p>
      </div>
    </div>
  `;
  syncPreviewScale();
  lucide.createIcons();
  syncGlobalPauseButton();
}

function ensureScheduleDraft() {
  if (!state.scheduleDraft || !isDirty('schedule')) {
    state.scheduleDraft = clone(state.prefs.timeslots || {});
    state.dirtySlots.clear();
  }
  return state.scheduleDraft;
}

function renderSchedule() {
  const draft = ensureScheduleDraft();
  const cards = Object.entries(draft).map(([slot, config]) => {
    const slotAttr = escapeHtml(slot);
    const rangeCards = DAYS.map(day => {
      const range = (config.day_time || {})[day] || { from: '08:00', till: '10:00' };
      const enabled = !!(config.day_time || {})[day];
      const dayTone = {
        mon: 'bg-sky-500/10',
        tue: 'bg-emerald-500/10',
        wed: 'bg-amber-500/10',
        thu: 'bg-fuchsia-500/10',
        fri: 'bg-rose-500/10',
        sat: 'bg-cyan-500/10',
        sun: 'bg-orange-500/10',
      }[day] || 'bg-white/5';
      return `
      <div class="rounded-3xl ${dayTone} px-3 py-4 text-xs">
        <div class="flex items-center justify-between gap-2">
          <div class="font-semibold uppercase tracking-[0.2em] text-slate-300">${day}</div>
          <label class="inline-flex items-center text-[11px] text-slate-300">
            <span class="ui-toggle">
              <input id="slot-${slotAttr}-${day}-enabled" data-slot="${slotAttr}" type="checkbox" ${enabled ? 'checked' : ''}>
              <span class="ui-toggle-track"></span>
            </span>
          </label>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-3">
          <label>
            <span class="mb-1 block text-[10px] uppercase tracking-[0.2em] text-slate-500">From</span>
            <input id="slot-${slotAttr}-${day}-from" data-slot="${slotAttr}" type="text" value="${escapeHtml(range.from)}" placeholder="08:45" class="w-full min-w-0 rounded-2xl border border-white/10 bg-black/20 px-4 py-2.5 text-base text-inherit outline-none">
          </label>
          <label>
            <span class="mb-1 block text-[10px] uppercase tracking-[0.2em] text-slate-500">Till</span>
            <input id="slot-${slotAttr}-${day}-till" data-slot="${slotAttr}" type="text" value="${escapeHtml(range.till)}" placeholder="11:30" class="w-full min-w-0 rounded-2xl border border-white/10 bg-black/20 px-4 py-2.5 text-base text-inherit outline-none">
          </label>
        </div>
      </div>
    `;
    });
    const weekdayRanges = rangeCards.slice(0, 5).join('');
    const weekendRanges = rangeCards.slice(5).join('');
    const weekendSpacers = Array.from({ length: 3 }, () => '<div class="hidden md:block"></div>').join('');

    const chips = Object.keys(state.prefs.pages).map(page => {
      const enabled = isModuleEnabled(page);
      const active = (config.pages || []).includes(page);
      const classes = !enabled
        ? 'border-white/5 bg-white/5 text-slate-600 cursor-not-allowed'
        : active
          ? 'border-amber-400/60 bg-amber-400/10 text-amber-200'
          : 'border-white/10 text-slate-400 hover:border-white/20 hover:text-white';
      const click = enabled ? `data-action="toggle-slot-page" data-slot-name="${slotAttr}" data-page="${escapeHtml(page)}"` : 'disabled';
      return `
      <button type="button" ${click}
        class="rounded-full border px-3 py-1 text-xs transition ${classes}">
        ${escapeHtml(page)}
      </button>
    `;
    }).join('');

    return `
      <div class="card rounded-[2rem] p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-[220px]">
            <h3 class="text-lg font-semibold capitalize">${escapeHtml(slot)}</h3>
            <p class="text-xs text-slate-400">Edit the active window for each day and the pages eligible in this slot.</p>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <button type="button" data-action="remove-slot" data-slot-name="${slotAttr}" class="rounded-full border border-rose-500/30 px-4 py-2 text-xs text-rose-300">Delete</button>
            <button type="button" data-action="save-slot" data-slot-name="${slotAttr}" class="rounded-full bg-amber-500 px-4 py-2 text-xs font-medium text-slate-950 transition hover:bg-amber-400">Save slot</button>
          </div>
        </div>
        <div class="mt-4">
          <div class="grid gap-3 md:grid-cols-5">${weekdayRanges}</div>
          <div class="mt-3 grid gap-3 md:grid-cols-5">${weekendRanges}${weekendSpacers}</div>
        </div>
        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
          <div class="flex flex-wrap gap-2">${chips}</div>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Module Duration (min)</span>
            <input id="slot-duration-${slotAttr}" data-slot="${slotAttr}" type="number" min="1" value="${escapeHtml(config.duration)}" class="w-36 rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
          </label>
        </div>
      </div>
    `;
  }).join('');

  el('panel-schedule').innerHTML = `
    <div id="dirty-notice-schedule" class="${isDirty('schedule') ? '' : 'hidden'}">${dirtyNotice('schedule', 'Schedule changes are unsaved.')}</div>
    <div class="card rounded-[2rem] p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-[11px] uppercase tracking-[0.35em] text-amber-400/80">Schedule</p>
          <h2 class="mt-1 text-xl font-bold">Timeslot-driven page selection</h2>
          <p class="mt-2 text-sm text-slate-400">Add new slots, edit each day directly, and control which pages are allowed in every window.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" onclick="addScheduleSlot()" class="rounded-full border border-white/10 px-4 py-2 text-sm">Add slot</button>
          <button type="button" onclick="saveAllSchedule()" class="rounded-full bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save all changes</button>
        </div>
      </div>
    </div>
    ${cards}
  `;
}

function renderClock() {
  const cfg = state.configs.clock || { enabled_styles: [] };
  const styles = ['digital', 'analog', 'words', 'clocks', 'flip'].map(style => `
    <label class="overflow-hidden rounded-3xl border border-white/10 bg-black/10">
      <div class="preview-frame bg-white" style="${framePreviewStyle()}">
        <iframe src="/clock/clock.${style}.html?preview=${state.previewVersion}" title="${style} preview"></iframe>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
        <span class="capitalize">${style}</span>
        <span class="ui-toggle">
          <input type="checkbox" data-clock-style="${style}" ${cfg.enabled_styles.includes(style) ? 'checked' : ''}>
          <span class="ui-toggle-track"></span>
        </span>
      </div>
    </label>
  `).join('');

  el('panel-clock').innerHTML = `
    <div id="dirty-notice-clock" class="${isDirty('clock') ? '' : 'hidden'}">${dirtyNotice('clock', 'Clock changes are unsaved.')}</div>
    ${rotationCard('clock', 'Clock', 'Show one of several full-frame clock styles. Use this module when you want the frame to act like a calm ambient clock.')}
    <div class="card rounded-[2rem] p-5">
      <h3 class="text-sm font-semibold">Enabled styles</h3>
      <p class="mt-1 text-xs text-slate-400">Each card previews the actual clock template file used by the module.</p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">${styles}</div>
      <button type="button" onclick="saveClock()" class="mt-5 rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save clock settings</button>
    </div>
  `;
}

function newspaperEntries() {
  const cfg = state.configs.newspaper || {};
  return Object.entries(cfg);
}

function renderNewspaper() {
  const preview = state.newspaperPreview || { papers: [] };
  const entries = newspaperEntries().map(([name, paper]) => `
    <div class="rounded-2xl border border-white/10 px-4 py-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <div class="font-medium">${escapeHtml(name)}</div>
          <div class="mt-1 text-xs text-slate-400">${escapeHtml(paper.prefix)}</div>
        </div>
        <div class="flex items-center gap-3">
          <label class="flex items-center gap-2 text-xs text-slate-300">
            <span>${paper.enabled === false ? 'Disabled' : 'Enabled'}</span>
            <span class="ui-toggle">
              <input type="checkbox" data-paper-enabled="${escapeHtml(name)}" ${paper.enabled === false ? '' : 'checked'}>
              <span class="ui-toggle-track"></span>
            </span>
          </label>
          <button type="button" data-action="remove-newspaper" data-name="${escapeHtml(name)}" class="rounded-full border border-rose-500/30 px-3 py-1 text-xs text-rose-300">Remove</button>
        </div>
      </div>
      <div class="mt-3 grid gap-3 lg:grid-cols-[180px_1fr]">
        <input type="text" data-paper-prefix="${escapeHtml(name)}" value="${escapeHtml(paper.prefix)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-inherit outline-none" placeholder="Tag like NY_NYT">
        <input type="text" data-paper-style="${escapeHtml(name)}" value="${escapeHtml(paper.style)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-inherit outline-none">
      </div>
    </div>
  `).join('') || '<p class="text-sm text-slate-400">No newspapers selected yet.</p>';

  el('panel-newspaper').innerHTML = `
    <div id="dirty-notice-newspaper" class="${isDirty('newspaper') ? '' : 'hidden'}">${dirtyNotice('newspaper', 'Newspaper changes are unsaved. Save before running cron.')}</div>
    ${rotationCard('newspaper', 'Newspaper', 'Show the latest front pages from your selected newspapers. This works especially well in morning schedule slots.')}
    <div class="card rounded-[2rem] p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">Today's papers</h3>
          <p class="text-xs text-slate-400">Latest fetched newspaper fronts from the live module folder. ${escapeHtml(freshnessLabel(preview.updated_at, 'No fetch recorded yet.'))}</p>
        </div>
        <a href="/newspaper/" target="_blank" class="rounded-full border border-white/10 px-4 py-2 text-sm">Open full newspaper page</a>
      </div>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        ${preview.papers.filter(paper => paper.enabled !== false).map(paper => `
          <div class="overflow-hidden rounded-3xl border border-white/10 bg-black/10">
            <div class="overflow-hidden bg-white" style="${framePreviewStyle()}">
              ${paper.file
                ? `<img src="/newspaper/${encodeURIComponent(paper.file)}?v=${state.previewVersion}" loading="lazy" class="h-full w-full object-contain bg-white" alt="${escapeHtml(paper.name)}">`
                : `<div class="flex h-full items-center justify-center text-sm text-slate-400">No file yet</div>`}
            </div>
            <div class="px-3 py-2 text-xs text-slate-400">${escapeHtml(paper.name)}</div>
          </div>
        `).join('') || '<p class="text-sm text-slate-400">No previews available.</p>'}
      </div>
    </div>
    <div class="card rounded-[2rem] p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">Papers</h3>
          <p class="text-xs text-slate-400">The prefix is used by the existing cron job to fetch the latest front page.</p>
        </div>
        <button type="button" onclick="openPaperModal()" class="rounded-full border border-white/10 px-4 py-2 text-sm">Add paper</button>
      </div>
      <div class="mt-4 space-y-3">${entries}</div>
      <button type="button" onclick="saveNewspaper()" class="mt-5 rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save newspaper settings</button>
    </div>
  `;
}

function galleryThumbUrl(module, item) {
  return `api.php?action=thumb&module=${encodeURIComponent(module)}&file=${encodeURIComponent(item.name)}&v=${item.mtime || 0}`;
}

function renderGalleryPanel(module, title) {
  if (!state.galleries[module]) {
    el(`panel-${module}`).innerHTML = '<div class="card rounded-[2rem] p-5 text-sm text-slate-400">Loading gallery…</div>';
    loadGallery(module)
      .then(() => renderPanel(module))
      .catch(error => toast(`Could not load ${title} gallery: ${error.message}`, 'error'));
    return;
  }
  const files = state.galleries[module];
  // Grid shows ~300px cached thumbnails; the full image opens in a new tab.
  const items = files.map(item => `
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-black/20">
      <a href="/${module}/${encodeURIComponent(item.name)}?v=${item.mtime || 0}" target="_blank" rel="noopener" class="block overflow-hidden bg-white/5" style="${framePreviewStyle()}">
        <img src="${galleryThumbUrl(module, item)}" loading="lazy" decoding="async" class="h-full w-full object-contain bg-white" alt="${escapeHtml(item.name)}">
      </a>
      <div class="flex items-center justify-between gap-2 px-3 py-2">
        <div class="min-w-0 truncate text-[11px] text-slate-400">${escapeHtml(item.name)}</div>
        <button type="button" data-action="delete-image" data-module="${module}" data-file="${escapeHtml(item.name)}" class="rounded-full border border-rose-500/30 px-3 py-1 text-[11px] text-rose-300">Delete</button>
      </div>
    </div>
  `).join('') || '<p class="text-sm text-slate-400">No images found.</p>';

  el(`panel-${module}`).innerHTML = `
    <div id="dirty-notice-${module}" class="${isDirty(module) ? '' : 'hidden'}">${dirtyNotice(module, `${title} rotation changes are unsaved.`)}</div>
    ${rotationCard(module, title, module === 'art'
      ? 'Display a rotating gallery of uploaded artwork prepared for the frame.'
      : module === 'quotes'
        ? 'Show quote images from your uploaded collection as a simple inspirational module.'
        : 'Show a rotating gallery from the Haynesmann image collection.')}
    <div class="card rounded-[2rem] p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">Gallery</h3>
          <p class="text-xs text-slate-400">Uploads are converted to grayscale JPG and saved server-side.</p>
        </div>
        <label class="rounded-full border border-white/10 px-4 py-2 text-sm cursor-pointer">
          Upload
          <input type="file" multiple accept="image/*" class="hidden" data-upload-gallery="${module}">
        </label>
      </div>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">${items}</div>
      <button type="button" onclick="saveRotationOnly('${module}')" class="mt-5 rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save ${title.toLowerCase()} rotation</button>
    </div>
  `;
}

function renderComics() {
  const cfg = state.configs.comics;
  const preview = state.comicsPreview || { farside: [], strips: [], sources: {}, warnings: [] };
  const sourceMap = preview.sources || {};
  const warnings = preview.warnings || [];
  const strips = [...(cfg.strips || [])].sort((a, b) => a.order - b.order).map(strip => {
    const source = sourceMap[strip.slug] || {};
    const fetchMode = strip.fetch_mode || 'auto';
    const staleText = source.stale_since ? `Stale since ${formatRelativeTime(source.stale_since)}` : '';
    const statusText = source.status ? String(source.status).replace(/^\w/, c => c.toUpperCase()) : 'Unknown';
    const staleSinceMs = source.stale_since ? new Date(source.stale_since).getTime() : 0;
    const showStripWarning = ['blocked', 'missing'].includes(source.status) &&
      strip.enabled &&
      isModuleEnabled('comics') &&
      (staleSinceMs > 0 ? (Date.now() - staleSinceMs) > 25 * 3600 * 1000 : true);
    return `
    <div draggable="true"
      data-strip="${escapeHtml(strip.slug)}"
      class="strip-item rounded-2xl border border-white/10 px-4 py-3 cursor-move">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-start gap-3">
          <div class="rounded-full border border-white/10 p-2 text-slate-400">${icon(strip.type === 'hardcoded' ? 'panel-top' : strip.type === 'dilbert' ? 'newspaper' : 'grip')}</div>
          <div>
            <div class="font-medium">${escapeHtml(strip.label)}</div>
            <div class="text-xs uppercase tracking-[0.2em] text-slate-500">${escapeHtml(strip.type)}</div>
            <div class="mt-2 text-xs text-slate-400">Status: ${escapeHtml(statusText)}${source.last_success_at ? ` • Last good strip ${escapeHtml(formatRelativeTime(source.last_success_at))}` : ''}${staleText ? ` • ${escapeHtml(staleText)}` : ''}</div>
            ${source.message ? `<div class="mt-1 text-xs ${['blocked', 'missing'].includes(source.status) ? 'text-amber-300' : 'text-slate-400'}">${escapeHtml(source.message)}</div>` : ''}
          </div>
        </div>
        <div class="flex items-center gap-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <span>Enabled</span>
            <span class="ui-toggle">
              <input type="checkbox" data-strip-enabled="${escapeHtml(strip.slug)}" ${strip.enabled ? 'checked' : ''}>
              <span class="ui-toggle-track"></span>
            </span>
          </label>
          ${strip.type === 'gocomics' ? `<button type="button" data-action="remove-strip" data-slug="${escapeHtml(strip.slug)}" class="rounded-full border border-rose-500/30 px-3 py-1 text-xs text-rose-300">Remove</button>` : ''}
        </div>
      </div>
      ${strip.type !== 'hardcoded' ? `
        <div class="mt-4 grid gap-3 lg:grid-cols-[180px_1fr_auto]">
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Source mode</span>
            <select data-strip-fetch-mode="${escapeHtml(strip.slug)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
              <option value="auto" ${fetchMode === 'auto' ? 'selected' : ''}>Automatic</option>
              <option value="upload" ${fetchMode === 'upload' ? 'selected' : ''}>Manual upload</option>
              <option value="url" ${fetchMode === 'url' ? 'selected' : ''}>Direct image URL</option>
            </select>
          </label>
          <label class="text-xs text-slate-400">
            <span class="mb-1 block">Image URL</span>
            <input data-strip-image-url="${escapeHtml(strip.slug)}" type="text" value="${escapeHtml(strip.image_url || '')}" placeholder="https://example.com/today-strip.jpg" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
          </label>
          <div class="flex items-end gap-2">
            <button type="button" data-action="import-strip-url" data-slug="${escapeHtml(strip.slug)}" class="rounded-2xl border border-white/10 px-4 py-2 text-sm">Import URL</button>
            <label class="rounded-2xl border border-white/10 px-4 py-2 text-sm cursor-pointer">
              Upload strip
              <input type="file" accept="image/*" class="hidden" data-upload-strip="${escapeHtml(strip.slug)}">
            </label>
          </div>
        </div>
      ` : ''}
      ${showStripWarning ? `<div class="mt-3 rounded-2xl border border-amber-400/40 bg-amber-400/10 px-3 py-2 text-xs text-amber-200">This strip could not be refreshed automatically. Keep the last good strip, upload a replacement, or switch to a direct image URL.</div>` : ''}
    </div>
  `;
  }).join('');

  el('panel-comics').innerHTML = `
    <div id="dirty-notice-comics" class="${isDirty('comics') ? '' : 'hidden'}">${dirtyNotice('comics', 'Comics changes are unsaved. Save before running cron.')}</div>
    ${rotationCard('comics', 'Comics', 'Combine multiple comic strips into one long page for the frame. Reorder strips and manage sources below.', { showDynamic: false })}
    <div class="card rounded-[2rem] p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">Today's comics preview</h3>
          <p class="text-xs text-slate-400">${preview.updated_at ? `Last metadata update: ${escapeHtml(formatRelativeTime(preview.updated_at))}` : (preview.updated ? `Last metadata update: ${escapeHtml(preview.updated)}` : 'Preview uses the latest generated strip files.')}</p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" onclick="openComicsAuthModal()" class="rounded-full border border-white/10 px-4 py-2 text-sm flex items-center gap-2">
            <i data-lucide="cookie" class="h-4 w-4"></i> Cookie settings
          </button>
          <a href="/comics/" target="_blank" class="rounded-full border border-white/10 px-4 py-2 text-sm">Open comics page</a>
        </div>
      </div>
      ${warnings.length ? `
        <div class="mt-4 space-y-2">
          ${warnings.map(warning => `<div class="rounded-2xl border border-amber-400/40 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">${escapeHtml(warning.label)}: ${escapeHtml(warning.message)}${warning.stale_since ? ` • stale since ${escapeHtml(formatRelativeTime(warning.stale_since))}` : ''}</div>`).join('')}
        </div>
      ` : ''}
      <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(280px,360px)_1fr]">
        <div class="overflow-hidden rounded-[1.5rem] border border-white/10 bg-white" style="${framePreviewStyle()}">
          <iframe src="/comics/?preview=${state.previewVersion}" class="h-full w-full border-0"></iframe>
        </div>
        <div class="space-y-3">
          ${(preview.farside || []).length ? `
            <div class="flex flex-wrap gap-3">
              ${(preview.farside || []).map(panel => `
                <div class="comics-thumb w-full max-w-[150px] overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                  <div style="aspect-ratio:${panel.width || 4}/${panel.height || 3}" class="overflow-hidden bg-white">
                    <img src="/comics/${encodeURIComponent(panel.file)}?v=${state.previewVersion}" loading="lazy" class="h-full w-full object-contain bg-white" alt="">
                  </div>
                  <div class="px-3 py-2 text-[11px] text-slate-400">Far Side</div>
                </div>
              `).join('')}
            </div>
          ` : ''}
          ${(preview.strips || []).map(strip => `
            <div class="comics-thumb overflow-hidden rounded-3xl border border-white/10 bg-white/5">
              <div style="aspect-ratio:${strip.width || 16}/${strip.height || 7}" class="overflow-hidden bg-white">
                <img src="/comics/${encodeURIComponent(strip.file)}?v=${state.previewVersion}" loading="lazy" class="h-full w-full object-contain bg-white" alt="">
              </div>
              <div class="px-3 py-2 text-[11px] text-slate-400">${escapeHtml(strip.label || strip.file)}</div>
            </div>
          `).join('')}
        </div>
      </div>
    </div>
    <div class="card rounded-[2rem] p-5">
      <h3 class="text-sm font-semibold">Layout</h3>
      <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Gap between rows</span>
          <input id="comics-gap-strip" type="number" min="0" value="${cfg.gap_strip}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Gap min</span>
          <input id="comics-gap-min" type="number" min="0" value="${cfg.gap_min}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Gap max</span>
          <input id="comics-gap-max" type="number" min="0" value="${cfg.gap_max}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
      </div>
    </div>
    <div class="card rounded-[2rem] p-5">
      <h3 class="text-sm font-semibold">Strips</h3>
      <p class="mt-1 text-xs text-slate-400">Drag any strip to reorder it. Use automatic fetch, a manual upload, or a direct image URL for each strip.</p>
      <div class="mt-4 grid gap-3 lg:grid-cols-[1fr_auto]">
        <input id="newComicUrl" type="text" placeholder="https://www.gocomics.com/garfield" class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        <button type="button" onclick="addComicStripFromUrl()" class="rounded-2xl border border-white/10 px-4 py-2 text-sm">Add GoComics strip</button>
      </div>
      <div class="mt-4 space-y-3">${strips}</div>
      <button type="button" onclick="saveComics()" class="mt-5 rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save comics settings</button>
    </div>
  `;
  lucide.createIcons();
}

function renderAiNews() {
  const cfg = state.configs.ainews || { sources: [], provider_order: DEFAULT_PROVIDER_ORDER };
  const preview = state.ainewsPreview || { stories: [] };
  const sources = (cfg.sources || []).map((source, index) => `
    <div class="rounded-2xl border border-white/10 p-4">
      <div class="grid gap-3 lg:grid-cols-[180px_1fr_auto_auto]">
        <input data-source-label="${index}" type="text" value="${escapeHtml(source.label)}" class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="Label">
        <input data-source-feed="${index}" type="text" value="${escapeHtml(source.feed)}" class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="RSS feed URL">
        <button type="button" onclick="validateFeed(${index})" class="rounded-2xl border border-white/10 px-4 py-2 text-sm">Validate</button>
        <button type="button" onclick="removeSource(${index})" class="rounded-2xl border border-rose-500/30 px-4 py-2 text-sm text-rose-300">Remove</button>
      </div>
      <div id="feed-status-${index}" class="mt-2 text-xs text-slate-500"></div>
    </div>
  `).join('') || '<p class="text-sm text-slate-400">No sources configured.</p>';

  const apiFields = [
    ['groq_api_key', 'Groq'],
    ['gemini_api_key', 'Gemini'],
    ['pollinations_api_key', 'Pollinations'],
    ['huggingface_api_key', 'HuggingFace'],
    ['kie_api_key', 'kie.ai'],
  ].map(([key, label]) => {
    // Write-only: the server only says whether a key is saved, never its value.
    const isSet = !!cfg.secrets?.[key]?.set;
    return `
    <div class="text-xs text-slate-400">
      <span class="mb-1 block">${label} <span class="${isSet ? 'text-emerald-300' : 'text-slate-500'}">(${isSet ? 'saved' : 'not set'})</span></span>
      <input data-ainews-secret="${key}" type="password" autocomplete="new-password" value="" placeholder="${isSet ? 'Leave blank to keep the saved key' : 'Paste API key'}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
      ${isSet ? `<label class="mt-1 inline-flex items-center gap-2 text-[11px]"><input type="checkbox" data-ainews-clear="${key}"> Clear saved key</label>` : ''}
    </div>
  `;
  }).join('');

  el('panel-ainews').innerHTML = `
    <div id="dirty-notice-ainews" class="${isDirty('ainews') ? '' : 'hidden'}">${dirtyNotice('ainews', 'AiNews changes are unsaved. Save before running cron.')}</div>
    ${rotationCard('ainews', 'AiNews', 'Turn RSS headlines into illustrated story cards for the frame. Configure feeds, prompts, and image-generation providers below.')}
    <div class="card rounded-[2rem] p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">Today's AiNews pulls</h3>
          <p class="text-xs text-slate-400">Previewing the currently generated story set from <code>data.json</code>. ${escapeHtml(freshnessLabel(preview.updated_at, 'No generation recorded yet.'))}</p>
        </div>
        <a href="/ainews/" target="_blank" class="rounded-full border border-white/10 px-4 py-2 text-sm">Open full AiNews page</a>
      </div>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        ${preview.stories.map((story, index) => `
          <div class="overflow-hidden rounded-3xl border border-white/10 bg-black/10">
            <div class="relative overflow-hidden bg-white" style="${framePreviewStyle()}">
              <img src="/ainews/${encodeURIComponent(story.image || ('story' + (index + 1) + '.jpg'))}?v=${state.previewVersion}" loading="lazy" class="h-full w-full object-cover" alt="${escapeHtml(story.title || '')}">
              <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black via-black/85 to-transparent px-4 pb-4 pt-16 text-white">
                <div class="text-[10px] uppercase tracking-[0.25em] text-white/65">${escapeHtml(story.source || '')}</div>
                <div class="mt-2 text-sm font-semibold leading-tight">${escapeHtml(story.title || 'Untitled')}</div>
                <div class="mt-2 text-xs leading-relaxed text-white/82" style="display:-webkit-box;-webkit-line-clamp:6;-webkit-box-orient:vertical;overflow:hidden;">${escapeHtml(story.summary || '')}</div>
              </div>
            </div>
            <div class="px-3 py-2 text-[11px] text-slate-500">${escapeHtml(story.date || '')}</div>
          </div>
        `).join('') || '<p class="text-sm text-slate-400">No stories found for today.</p>'}
      </div>
    </div>
    <div class="card rounded-[2rem] p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="text-sm font-semibold">RSS sources</h3>
          <p class="text-xs text-slate-400">Only RSS feeds with a channel/item structure are accepted.</p>
        </div>
        <button type="button" onclick="addSource()" class="rounded-full border border-white/10 px-4 py-2 text-sm">Add source</button>
      </div>
      <div class="mt-4 space-y-3">${sources}</div>
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
      <div class="card rounded-[2rem] p-5 space-y-4">
        <h3 class="text-sm font-semibold">Text generation</h3>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Summary words</span>
          <input data-ainews-field="summary_words" type="number" min="20" value="${escapeHtml(cfg.summary_words || 60)}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Gemini model</span>
          <input data-ainews-field="gemini_model" type="text" value="${escapeHtml(cfg.gemini_model || 'gemini-2.5-flash-image')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">kie.ai model</span>
          <input data-ainews-field="kie_model" type="text" value="${escapeHtml(cfg.kie_model || 'google/nano-banana')}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Fallback order</span>
          <input data-ainews-field="provider_order_text" type="text" value="${escapeHtml((cfg.provider_order || DEFAULT_PROVIDER_ORDER).join(', '))}" class="w-full rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none" placeholder="kie, gemini, pollinations, huggingface">
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Summary prompt</span>
          <textarea data-ainews-field="summary_prompt" rows="5" class="w-full rounded-3xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-inherit outline-none">${escapeHtml(cfg.summary_prompt || '')}</textarea>
        </label>
        <label class="text-xs text-slate-400">
          <span class="mb-1 block">Image prompt</span>
          <textarea data-ainews-field="image_prompt" rows="7" class="w-full rounded-3xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-inherit outline-none">${escapeHtml(cfg.image_prompt || '')}</textarea>
        </label>
      </div>
      <div class="card rounded-[2rem] p-5">
        <h3 class="text-sm font-semibold">API keys</h3>
        <div class="mt-4 grid gap-3">${apiFields}</div>
        <button type="button" onclick="toggleApiInputs()" class="mt-4 rounded-full border border-white/10 px-4 py-2 text-sm">Show or hide keys</button>
      </div>
    </div>
    <button type="button" onclick="saveAiNews()" class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-amber-400">Save AiNews settings</button>
  `;
}

// Full initial render. Later updates re-render only the affected (visible, non-dirty) panel.
function renderAll() {
  applyFramePreviewMetrics();
  showPanel(state.currentPanel);
  renderHaPanel();
  lucide.createIcons();
  syncGlobalPauseButton();
}

function syncGlobalPauseButton() {
  const label = el('globalPauseLabel');
  const wrap = el('globalPauseIconWrap');
  if (!label || !wrap) return;
  label.textContent = state.paused ? 'Resume' : 'Pause';
  wrap.innerHTML = state.paused
    ? '<i data-lucide="play" class="h-4 w-4"></i>'
    : '<i data-lucide="pause" class="h-4 w-4"></i>';
  lucide.createIcons();
}

function isValidTimeText(value) {
  return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(String(value || '').trim());
}

function collectScheduleSlot(slot) {
  const dayTime = {};
  for (const day of DAYS) {
    const enabled = el(`slot-${slot}-${day}-enabled`)?.checked;
    if (!enabled) continue;
    const from = el(`slot-${slot}-${day}-from`)?.value?.trim() || '';
    const till = el(`slot-${slot}-${day}-till`)?.value?.trim() || '';
    if (!isValidTimeText(from) || !isValidTimeText(till)) {
      throw new Error(`Invalid time in ${slot} ${day}. Use HH:MM.`);
    }
    dayTime[day] = { from, till };
  }
  return {
    day_time: dayTime,
    duration: Number(el(`slot-duration-${slot}`)?.value || 1),
  };
}

function syncPreviewScale() {
  document.querySelectorAll('.preview-frame').forEach(frame => {
    const iframe = frame.querySelector('iframe');
    if (!iframe) return;
    const scale = Math.min(frame.clientWidth / frameWidth(), frame.clientHeight / frameHeight());
    iframe.style.transform = `scale(${scale})`;
  });
}

function withPreviewVersion(url) {
  if (!url) return null;
  const separator = url.includes('?') ? '&' : '?';
  return `${url}${separator}preview=${state.previewVersion}`;
}

function currentFramePreviewUrl() {
  const exactUrl = state.runtimeStatus?.frame?.exact_url;
  if (exactUrl) {
    return withPreviewVersion(exactUrl);
  }
  const frameModule = state.runtimeStatus?.frame?.last_module || state.currentPage;
  if (!frameModule || !state.prefs?.pages?.[frameModule]) {
    return null;
  }
  return withPreviewVersion(`/${state.prefs.pages[frameModule].url}`);
}

function selectedModulePreviewUrl() {
  const previewModule = state.previewModule || state.currentPage;
  if (!previewModule || !state.prefs?.pages?.[previewModule]) {
    return null;
  }
  return withPreviewVersion(`/${state.prefs.pages[previewModule].url}`);
}

function currentFrameDetail() {
  const frame = state.runtimeStatus?.frame || {};
  if (frame.asset_file) {
    return frame.asset_file;
  }
  if (frame.paper_name) {
    return frame.paper_name;
  }
  if (frame.paper_prefix) {
    return frame.paper_prefix;
  }
  if (frame.story_title) {
    return frame.story_title;
  }
  if (frame.style) {
    return frame.style;
  }
  return frame.exact_url || state.currentUrl || '';
}

function refreshLivePreview(forceReset = false) {
  state.previewVersion = Date.now();
  const nextSrc = currentFramePreviewUrl();
  const iframe = el('previewFrame');
  const nextFrame = el('nextPreviewFrame');
  const nextPreviewSrc = selectedModulePreviewUrl();
  if (!iframe) return;

  if (forceReset) {
    iframe.setAttribute('src', 'about:blank');
    window.setTimeout(() => {
      const liveFrame = el('previewFrame');
      if (!liveFrame) return;
      liveFrame.setAttribute('src', nextSrc || 'about:blank');
      const queuedNextFrame = el('nextPreviewFrame');
      if (queuedNextFrame) {
        queuedNextFrame.setAttribute('src', nextPreviewSrc || 'about:blank');
      }
    }, 40);
    return;
  }

  iframe.setAttribute('src', nextSrc || 'about:blank');
  if (nextFrame) {
    nextFrame.setAttribute('src', nextPreviewSrc || 'about:blank');
  }
}

function reloadSelectedModulePreview() {
  state.previewVersion = Date.now();
  const nextFrame = el('nextPreviewFrame');
  if (!nextFrame) return;
  const nextPreviewSrc = selectedModulePreviewUrl();
  nextFrame.setAttribute('src', nextPreviewSrc || 'about:blank');
}

function changePreviewModule(module) {
  if (!module || !state.prefs?.pages?.[module]) return;
  state.previewModule = module;
  renderLive();
}

function updateCountdown(seconds) {
  state.countdown = Math.max(0, Number(seconds) || 0);
  clearInterval(state.countdownInterval);
  paintCountdown();
  state.countdownInterval = setInterval(() => {
    if (!state.paused) {
      state.countdown = Math.max(0, state.countdown - 1);
      paintCountdown();
    }
  }, 1000);
}

function paintCountdown() {
  const countdownNode = el('countdown');
  if (!countdownNode) return;
  const m = Math.floor((state.countdown || 0) / 60);
  const s = Math.floor((state.countdown || 0) % 60);
  const sleeping = isRuntimeSleeping();
  const target = sleeping
    ? 'Frame wakes'
    : (state.currentPage ? `Next refresh: ${state.currentPage}` : 'Next refresh');
  const slotLabel = state.currentTimeslot ? `Slot: ${state.currentTimeslot}` : 'Slot: default';
  const detail = currentFrameDetail();
  countdownNode.innerHTML = `
    <div class="flex items-center gap-3 overflow-hidden whitespace-nowrap">
      <span class="shrink-0">${escapeHtml(`${slotLabel} • ${target} in ${m}:${String(s).padStart(2, '0')}`)}</span>
      ${detail ? `<span class="min-w-0 truncate text-slate-400">• Current frame: ${escapeHtml(detail)}</span>` : ''}
    </div>
  `;
}

function renderSystemHealthCard() {
  const status = state.runtimeStatus || {};
  const display = status.display || {};
  const frame = status.frame || {};
  const cron = status.cron || {};
  const warnings = buildHealthWarnings();
  const cronModules = ['newspaper', 'comics', 'ainews'].filter(module => isModuleEnabled(module));
  return `
    <div class="card rounded-[2rem] p-5" id="systemHealthCard">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-[11px] uppercase tracking-[0.35em] text-sky-200">System Health</p>
          <h2 class="mt-1 text-xl font-bold">Runtime and content freshness</h2>
        </div>
        <div class="rounded-full border border-white/10 px-3 py-1 text-xs text-slate-300">
          Runtime ${freshnessLabel(display.updated_at, 'not seen yet')}
        </div>
      </div>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3">
          <div class="text-[11px] uppercase tracking-[0.25em] text-slate-500">Active Page</div>
          <div class="mt-2 text-sm font-semibold">${escapeHtml(display.curPage || state.currentPage || 'Unknown')}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3">
          <div class="text-[11px] uppercase tracking-[0.25em] text-slate-500">Active Slot</div>
          <div class="mt-2 text-sm font-semibold">${escapeHtml(display.timeslot || state.currentTimeslot || 'default')}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3">
          <div class="text-[11px] uppercase tracking-[0.25em] text-slate-500">Presence</div>
          <div class="mt-2 text-sm font-semibold">${escapeHtml(display.activity || state.activity || 'home')}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3">
          <div class="text-[11px] uppercase tracking-[0.25em] text-slate-500">Preview Freshness</div>
          <div class="mt-2 text-sm font-semibold">${freshnessLabel(
            state.ainewsPreview?.updated_at || state.newspaperPreview?.updated_at || state.comicsPreview?.updated_at,
            'No preview data yet'
          )}</div>
        </div>
        <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3 sm:col-span-2 xl:col-span-4">
          <div class="text-[11px] uppercase tracking-[0.25em] text-slate-500">Frame Last Seen</div>
          <div class="mt-2 text-sm font-semibold">${frame.last_seen_at ? `${formatRelativeTime(frame.last_seen_at)} on ${escapeHtml(frame.last_module || 'unknown module')}` : 'Waiting for a non-preview frame request…'}</div>
          <div class="mt-1 text-xs text-slate-400">${frame.last_path ? escapeHtml(frame.last_path) : 'Admin preview loads do not count toward this signal.'}</div>
        </div>
      </div>
      <div class="mt-4 grid gap-3 xl:grid-cols-3">
        ${cronModules.map(module => {
          const entry = cron[module] || {};
          return `
            <div class="rounded-2xl border border-white/10 bg-black/10 px-4 py-3">
              <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-semibold capitalize">${module}</div>
                <div class="text-xs ${entry.running ? 'text-amber-300' : 'text-slate-400'}">${entry.running ? 'Running now' : 'Idle'}</div>
              </div>
              <div class="mt-2 text-xs text-slate-400">
                Last run: ${entry.last_run_at ? `${formatRelativeTime(entry.last_run_at)} (${escapeHtml(entry.last_run_kind || 'unknown')})` : 'Never'}
              </div>
            </div>
          `;
        }).join('')}
      </div>
      ${warnings.length ? `
        <div class="mt-4 space-y-2">
          ${warnings.map(warning => `<div class="rounded-2xl border border-amber-400/60 bg-amber-400/10 px-4 py-3 text-sm text-amber-200">${escapeHtml(warning)}</div>`).join('')}
        </div>
      ` : ''}
    </div>
  `;
}

function updateLiveStatusUI() {
  const sleeping = isRuntimeSleeping();
  if (sleeping) {
    const wakeSeconds = nextWakeSeconds();
    if (wakeSeconds !== null) {
      state.countdown = wakeSeconds;
      paintCountdown();
    }
  }
  const liveBadge = el('liveBadge');
  if (liveBadge) {
    const liveState = liveBadgeState();
    liveBadge.textContent = liveState.label;
    liveBadge.className = liveState.className;
  }
  const reloadButton = el('reloadCurrentBtn');
  if (reloadButton) {
    reloadButton.disabled = sleeping;
    reloadButton.className = `rounded-full border border-white/10 p-2 text-slate-300 transition ${sleeping ? 'cursor-not-allowed opacity-50' : 'hover:border-sky-400/60 hover:text-white'}`;
    reloadButton.title = sleeping ? `Frame asleep until ${nextWakeLabel()}` : 'Reload current page';
  }
  const pageSelect = el('pageSelect');
  if (pageSelect && state.currentPage) {
    pageSelect.value = state.currentPage;
  }
  const healthNode = el('systemHealthCard');
  if (healthNode) {
    healthNode.outerHTML = renderSystemHealthCard();
  }
  const nextExactUrl = state.runtimeStatus?.frame?.exact_url || null;
  const nextLastModule = state.runtimeStatus?.frame?.last_module || null;
  const frameChanged = nextExactUrl !== state.lastFrameExactUrl || nextLastModule !== state.lastFrameModule;
  if (state.currentPanel === 'live' && frameChanged) {
    state.lastFrameExactUrl = nextExactUrl;
    state.lastFrameModule = nextLastModule;
    state.previewVersion = Date.now();
    const iframe = el('previewFrame');
    if (iframe) {
      iframe.setAttribute('src', currentFramePreviewUrl() || 'about:blank');
    }
    const nextFrame = el('nextPreviewFrame');
    if (nextFrame) {
      nextFrame.setAttribute('src', selectedModulePreviewUrl() || 'about:blank');
    }
  }
  lucide.createIcons();
  syncGlobalPauseButton();
}

function scheduleFrameStatusSync() {
  if (state.frameSyncTimeout) {
    clearTimeout(state.frameSyncTimeout);
  }

  state.frameSyncTimeout = setTimeout(async () => {
    try {
      await loadSystemStatus();
      if (state.currentPanel === 'live') {
        updateLiveStatusUI();
      }
    } catch (error) {
    } finally {
      state.frameSyncTimeout = null;
    }
  }, 900);
}

function scheduleReconnect(delay = 5000) {
  clearTimeout(state.wsReconnectTimer);
  state.wsReconnectTimer = setTimeout(connectWebSocket, delay);
}

function handleSocketAuth(data) {
  state.wsAuthed = !!data.ok && data.role === 'admin';
  if (!state.wsAuthed) {
    el('countdown').textContent = 'Not authorized - reconnecting';
    try { state.socket?.close(); } catch (error) {}
  } else {
    el('countdown').textContent = 'Connected';
    state.socket.send(JSON.stringify({ task: 'getStatus' }));
  }
}

// Fetches a fresh token for every (re)connect; tokens expire after an hour.
async function connectWebSocket() {
  clearTimeout(state.wsReconnectTimer);
  let token;
  try {
    token = (await api('ws_token')).token;
  } catch (error) {
    el('countdown').textContent = 'Reconnecting…';
    scheduleReconnect();
    return;
  }
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const socket = new WebSocket(`${protocol}//${window.location.hostname}:12345/?token=${encodeURIComponent(token)}`);
  state.socket = socket;
  state.wsAuthed = null;
  socket.onopen = () => {
    if (state.wsAuthed === false) return;
    el('countdown').textContent = 'Connected';
    socket.send(JSON.stringify({ task: 'getStatus' }));
  };
  socket.onmessage = event => {
    let data;
    try {
      data = JSON.parse(event.data);
    } catch (error) {
      return;
    }
    if (!data || typeof data !== 'object') return;
    if (data.type === 'auth') {
      handleSocketAuth(data);
      return;
    }
    if (data.url) {
      state.currentUrl = data.url;
      refreshLivePreview(true);
      scheduleFrameStatusSync();
    }
    if (data.status) {
      state.paused = !!data.status.paused;
      state.currentPage = data.status.curPage || state.currentPage;
      state.nextPage = data.status.nextPage || state.nextPage;
      state.currentTimeslot = data.status.timeslot || null;
      state.activity = data.status.activity || state.activity;
      if (state.currentPage && state.prefs?.pages?.[state.currentPage]) {
        state.currentUrl = state.prefs.pages[state.currentPage].url;
      }
      state.runtimeStatus = {
        ...(state.runtimeStatus || {}),
        display: {
          ...((state.runtimeStatus && state.runtimeStatus.display) || {}),
          ...(data.status || {}),
          updated_at: new Date().toISOString(),
        },
      };
      const sleepSeconds = data.status.activity === 'sleep' ? nextWakeSeconds() : null;
      updateCountdown(sleepSeconds !== null
        ? sleepSeconds
        : ((data.status.endTime || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000)));
      updateLiveStatusUI();
    }
  };
  socket.onclose = () => {
    if (state.socket !== socket) return;
    if (state.wsAuthed !== false) {
      el('countdown').textContent = 'Reconnecting…';
    }
    scheduleReconnect();
  };
}

async function loadConfig(module) {
  state.configs[module] = await api('module_config', { query: `module=${encodeURIComponent(module)}` });
}

async function loadGallery(module) {
  const data = await api('gallery', { query: `module=${encodeURIComponent(module)}` });
  state.galleries[module] = (data.files || []).map(item => (typeof item === 'string' ? { name: item, mtime: 0 } : item));
}

async function loadComicsPreview() {
  state.comicsPreview = await api('comics_preview');
}

async function loadAiNewsPreview() {
  state.ainewsPreview = await api('ainews_preview');
}

async function loadNewspaperPreview() {
  state.newspaperPreview = await api('newspaper_preview');
}

async function loadHaConfig() {
  state.haConfig = await api('ha_config');
}

async function loadGeneralConfig() {
  state.generalConfig = await api('general_config');
  applyFramePreviewMetrics();
}

async function loadSystemStatus() {
  const data = await api('status');
  const snapshot = data?.status || data;
  state.runtimeStatus = snapshot;
  if (snapshot?.display) {
    state.activity = snapshot.display.activity || state.activity;
  }
}

async function boot() {
  try {
    state.prefs = await api('prefs');
    // Galleries load lazily when their panel is first shown.
    await Promise.all([
      ...MODULES.map(loadConfig),
      loadComicsPreview(),
      loadAiNewsPreview(),
      loadNewspaperPreview(),
      loadGeneralConfig(),
      loadHaConfig(),
      loadSystemStatus(),
    ]);
    state.currentPage = state.runtimeStatus?.display?.curPage || Object.keys(state.prefs.pages)[0];
    state.previewModule = state.currentPage;
    state.nextPage = state.runtimeStatus?.display?.nextPage || state.nextPage;
    state.currentTimeslot = state.runtimeStatus?.display?.timeslot || state.currentTimeslot;
    state.activity = state.runtimeStatus?.display?.activity || state.activity;
    if (state.currentPage && state.prefs.pages[state.currentPage]) {
      state.currentUrl = state.prefs.pages[state.currentPage].url;
    }
    renderAll();
    connectWebSocket();
    clearInterval(state.statusPollInterval);
    state.statusPollInterval = setInterval(async () => {
      try {
        await loadSystemStatus();
        if (state.currentPanel === 'live') {
          updateLiveStatusUI();
        }
      } catch (error) {
      }
    }, 30000);
  } catch (error) {
    document.body.innerHTML = `<div class="mx-auto max-w-xl p-8 text-sm text-rose-200">Admin boot failed: ${escapeHtml(error.message)}</div>`;
  }
}

function toggleTheme() {
  const isDark = document.documentElement.classList.toggle('dark');
  document.documentElement.classList.toggle('light', !isDark);
  localStorage.setItem('visionect-theme', isDark ? 'dark' : 'light');
}

function notConnectedToast() {
  toast('Not connected to the display service. Retrying the connection…', 'error');
}

async function togglePause() {
  if (!sendSocketTask({ task: state.paused ? 'unpause' : 'pause' })) notConnectedToast();
}

async function reloadCurrentPage() {
  if (isRuntimeSleeping()) {
    toast(`The frame is asleep until ${nextWakeLabel()}, so reload will not reach it yet.`, 'error');
    return;
  }
  if (!state.currentPage) return;
  if (!sendSocketTask({ task: 'setPage', page: state.currentPage })) {
    notConnectedToast();
    return;
  }
  refreshLivePreview(true);
  window.setTimeout(() => refreshLivePreview(true), 700);
  scheduleFrameStatusSync();
}

function goToPage() {
  if (isRuntimeSleeping()) {
    toast(`The frame is asleep until ${nextWakeLabel()}, so jump actions are temporarily blocked.`, 'error');
    return;
  }
  const page = el('pageSelect').value;
  if (!page || !isModuleEnabled(page)) return;
  if (!sendSocketTask({ task: 'setPage', page })) {
    notConnectedToast();
    return;
  }
  state.currentPage = page;
  state.nextPage = page;
  state.currentUrl = state.prefs.pages[page].url;
  state.previewVersion = Date.now();
  renderLive();
  scheduleFrameStatusSync();
}

function revertToSchedule() {
  if (isRuntimeSleeping()) {
    toast(`The frame is asleep until ${nextWakeLabel()}, so schedule resume will apply after wake.`, 'error');
    return;
  }
  if (!sendSocketTask({ task: 'resumeSchedule' })) {
    notConnectedToast();
    return;
  }
  scheduleFrameStatusSync();
}

function toggleSlotPage(slot, page) {
  if (!isModuleEnabled(page)) return;
  syncDraftFromFormLoose();
  const draft = ensureScheduleDraft();
  if (!draft[slot]) return;
  const pages = draft[slot].pages || (draft[slot].pages = []);
  const idx = pages.indexOf(page);
  if (idx >= 0) {
    pages.splice(idx, 1);
  } else {
    pages.push(page);
  }
  state.dirtySlots.add(slot);
  markDirty('schedule');
  renderPanel('schedule', { force: true });
}

// Copy a slot's form inputs into the draft (throws on invalid times).
function syncDraftSlotFromForm(slot) {
  const next = collectScheduleSlot(slot);
  state.scheduleDraft[slot].day_time = next.day_time;
  state.scheduleDraft[slot].duration = next.duration;
}

// Keep in-progress form values when the schedule panel is re-rendered from the draft.
function syncDraftFromFormLoose() {
  if (!state.scheduleDraft || !el('panel-schedule')?.innerHTML.trim()) return;
  Object.keys(state.scheduleDraft).forEach(slot => {
    try { syncDraftSlotFromForm(slot); } catch (error) { /* keep previous draft values */ }
  });
}

async function saveScheduleSlot(slot) {
  try {
    ensureScheduleDraft();
    if (!state.scheduleDraft[slot]) throw new Error(`Unknown slot ${slot}`);
    syncDraftSlotFromForm(slot);
    const next = clone(state.prefs);
    next.timeslots[slot] = clone(state.scheduleDraft[slot]);
    pruneDisabledFromTimeslots(next);
    await savePrefs(true, next);
    state.prefs = next;
    state.dirtySlots.delete(slot);
    if (state.dirtySlots.size === 0) {
      clearDirty('schedule');
    }
    toast(`${slot} saved`);
  } catch (error) {
    toast(error.message, 'error');
  }
}

function addScheduleSlot() {
  const name = prompt('New slot name');
  if (!name) return;
  const key = name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
  if (!key) return;
  syncDraftFromFormLoose();
  const draft = ensureScheduleDraft();
  if (draft[key]) {
    toast('That slot already exists', 'error');
    return;
  }
  const firstPage = enabledModules()[0] || Object.keys(state.prefs.pages)[0];
  const dayTime = {};
  DAYS.forEach(day => {
    dayTime[day] = { from: '08:00', till: '10:00' };
  });
  draft[key] = {
    day_time: dayTime,
    duration: 30,
    pages: firstPage ? [firstPage] : [],
  };
  state.dirtySlots.add(key);
  markDirty('schedule');
  renderPanel('schedule', { force: true });
}

function removeScheduleSlot(slot) {
  if (!confirm(`Delete the ${slot} slot?`)) return;
  syncDraftFromFormLoose();
  const draft = ensureScheduleDraft();
  delete draft[slot];
  state.dirtySlots.add(slot);
  markDirty('schedule');
  renderPanel('schedule', { force: true });
}

async function saveAllSchedule() {
  try {
    const draft = ensureScheduleDraft();
    Object.keys(draft).forEach(syncDraftSlotFromForm);
    const next = clone(state.prefs);
    next.timeslots = clone(draft);
    pruneDisabledFromTimeslots(next);
    await savePrefs(true, next);
    state.prefs = next;
    state.dirtySlots.clear();
    clearDirty('schedule');
    renderPanel('schedule', { force: true });
    toast('Schedule saved');
  } catch (error) {
    toast(error.message, 'error');
  }
}

/**
 * Shared save path for module panels: reads the rotation card, optionally builds the
 * module config, posts the SAVED prefs (never the schedule draft) and the config.
 * On failure the prefs are rolled back (if they were not written) and the panel stays
 * dirty so the form keeps the user's edits for a retry.
 */
async function saveModule(module, { label, buildConfig = null, afterSave = null } = {}) {
  const previousPrefs = clone(state.prefs);
  let prefsSaved = false;
  try {
    readRotation(module);
    pruneDisabledFromTimeslots();
    const config = buildConfig ? buildConfig() : null;
    await savePrefs(true);
    prefsSaved = true;
    if (config) {
      await api('module_config', {
        method: 'POST',
        query: `module=${encodeURIComponent(module)}`,
        body: config,
      });
    }
    clearDirty(module);
    fixCurrentPageAfterToggle(module);
    if (afterSave) await afterSave();
    renderNav();
    renderPanel(module, { force: true });
    toast(`${label} saved`);
    return true;
  } catch (error) {
    if (!prefsSaved) {
      state.prefs = previousPrefs;
    }
    toast(error.message || `${label}: save failed`, 'error');
    return false;
  }
}

function saveRotationOnly(module) {
  return saveModule(module, { label: `${GALLERY_TITLES[module] || module} rotation` });
}

async function toggleSidebarModule(module, enabled) {
  if (!state.prefs?.pages?.[module]) return;
  if (!ensureAtLeastOneModuleEnabled(module, enabled)) {
    renderNav();
    return;
  }
  const previousPrefs = clone(state.prefs);
  const previousPage = state.currentPage;
  const previousUrl = state.currentUrl;
  try {
    state.prefs.pages[module].enabled = !!enabled;
    pruneDisabledFromTimeslots();
    fixCurrentPageAfterToggle(module);
    await savePrefs(true);
  } catch (error) {
    state.prefs = previousPrefs;
    state.currentPage = previousPage;
    state.currentUrl = previousUrl;
    renderNav();
    toast(`Could not ${enabled ? 'enable' : 'disable'} ${module}: ${error.message}`, 'error');
    return;
  }
  renderNav();
  // The module panel may hold unsaved edits; only patch its enabled toggle in that case.
  const enabledInput = document.querySelector(`[data-rotation="${module}"][data-field="enabled"]`);
  if (enabledInput) enabledInput.checked = !!enabled;
  refreshPanels(['live', 'schedule', module]);
  toast(`${module} ${enabled ? 'enabled' : 'disabled'}`);
}

function saveClock() {
  return saveModule('clock', {
    label: 'Clock settings',
    buildConfig: () => {
      state.configs.clock.enabled_styles = [...document.querySelectorAll('[data-clock-style]')]
        .filter(node => node.checked)
        .map(node => node.dataset.clockStyle);
      return state.configs.clock;
    },
  });
}

function saveNewspaper() {
  return saveModule('newspaper', {
    label: 'Newspaper settings',
    buildConfig: () => {
      const papers = collectNewspaperFromForm();
      for (const [name, paper] of Object.entries(papers)) {
        if (!/^[A-Za-z0-9_-]+$/.test(paper.prefix)) {
          throw new Error(`Invalid tag for ${name}: letters, numbers, _ and - only`);
        }
      }
      return papers;
    },
    afterSave: loadNewspaperPreview,
  });
}

// Read the comics form (gaps + per-strip settings) into state.configs.comics.
function collectComicsConfigFromForm() {
  const cfg = state.configs.comics;
  cfg.gap_strip = Number(el('comics-gap-strip')?.value ?? cfg.gap_strip ?? 0) || 0;
  cfg.gap_min = Number(el('comics-gap-min')?.value ?? cfg.gap_min ?? 0) || 0;
  cfg.gap_max = Number(el('comics-gap-max')?.value ?? cfg.gap_max ?? 0) || 0;
  cfg.strips = cfg.strips.map(strip => {
    const slug = CSS.escape(strip.slug);
    const enabledInput = document.querySelector(`[data-strip-enabled="${slug}"]`);
    return {
      ...strip,
      enabled: enabledInput ? !!enabledInput.checked : !!strip.enabled,
      fetch_mode: document.querySelector(`[data-strip-fetch-mode="${slug}"]`)?.value || strip.fetch_mode || 'auto',
      image_url: document.querySelector(`[data-strip-image-url="${slug}"]`)?.value?.trim() ?? strip.image_url ?? '',
    };
  });
  return cfg;
}

function saveComics() {
  return saveModule('comics', {
    label: 'Comics settings',
    buildConfig: collectComicsConfigFromForm,
    afterSave: loadComicsPreview,
  });
}

async function persistComicsModuleConfig() {
  await api('module_config', {
    method: 'POST',
    query: 'module=comics',
    body: collectComicsConfigFromForm(),
  });
}

function addSource() {
  syncAiNewsSourcesFromForm();
  state.configs.ainews.sources.push({ label: 'New', feed: '' });
  markDirty('ainews');
  renderPanel('ainews', { force: true });
}

function removeSource(index) {
  syncAiNewsSourcesFromForm();
  state.configs.ainews.sources.splice(index, 1);
  markDirty('ainews');
  renderPanel('ainews', { force: true });
}

function syncAiNewsSourcesFromForm() {
  state.configs.ainews.sources = (state.configs.ainews.sources || []).map((source, index) => ({
    label: document.querySelector(`[data-source-label="${index}"]`)?.value ?? source.label,
    feed: document.querySelector(`[data-source-feed="${index}"]`)?.value ?? source.feed,
  }));
}

async function validateFeed(index) {
  const feed = document.querySelector(`[data-source-feed="${index}"]`)?.value || '';
  const status = el(`feed-status-${index}`);
  status.textContent = 'Checking feed…';
  try {
    await api('validate_feed', { method: 'POST', body: { url: feed } });
    status.textContent = 'Feed looks valid.';
    status.className = 'mt-2 text-xs text-emerald-300';
  } catch (error) {
    status.textContent = error.message;
    status.className = 'mt-2 text-xs text-rose-300';
  }
}

function toggleApiInputs() {
  document.querySelectorAll('[data-ainews-secret]').forEach(node => {
    node.type = node.type === 'password' ? 'text' : 'password';
  });
}

function saveAiNews() {
  return saveModule('ainews', {
    label: 'AiNews settings',
    buildConfig: () => {
      syncAiNewsSourcesFromForm();
      const cfg = state.configs.ainews;
      cfg.sources = cfg.sources.filter(source => source.label && source.feed);
      document.querySelectorAll('[data-ainews-field]').forEach(node => {
        cfg[node.dataset.ainewsField] = node.value;
      });
      cfg.provider_order = String(cfg.provider_order_text || '')
        .split(',')
        .map(item => item.trim().toLowerCase())
        .filter(Boolean);
      delete cfg.provider_order_text;
      // Secrets are write-only: send only newly typed keys plus explicit clears.
      const body = { ...cfg };
      delete body.secrets;
      document.querySelectorAll('[data-ainews-secret]').forEach(node => {
        const value = node.value.trim();
        if (value) body[node.dataset.ainewsSecret] = value;
      });
      body.clear_secrets = [...document.querySelectorAll('[data-ainews-clear]')]
        .filter(node => node.checked)
        .map(node => node.dataset.ainewsClear);
      return body;
    },
    afterSave: async () => {
      await Promise.all([loadConfig('ainews'), loadAiNewsPreview()]);
    },
  });
}

async function uploadImages(module, files) {
  if (!files?.length) return;
  let uploaded = 0;
  for (const file of files) {
    const formData = new FormData();
    formData.append('module', module);
    formData.append('file', file);
    try {
      await api('upload', { method: 'POST', query: `module=${encodeURIComponent(module)}`, body: formData });
      uploaded += 1;
    } catch (error) {
      toast(`${file.name}: ${error.message}`, 'error');
    }
  }
  try {
    await loadGallery(module);
    renderPanel(module, { force: true });
  } catch (error) {
    toast(`Could not reload gallery: ${error.message}`, 'error');
  }
  if (uploaded) {
    toast(uploaded === files.length ? 'Upload complete' : `Uploaded ${uploaded} of ${files.length}`);
  }
}

async function deleteImage(module, file) {
  if (!confirm(`Delete ${file}?`)) return;
  try {
    await api('delete_image', { method: 'POST', body: { module, file } });
    await loadGallery(module);
    renderPanel(module, { force: true });
    toast('Image deleted');
  } catch (error) {
    toast(`Delete failed: ${error.message}`, 'error');
  }
}

function startStripDrag(slug) {
  state.dragSlug = slug;
  document.querySelector(`[data-strip="${CSS.escape(slug)}"]`)?.classList.add('dragging');
}

function dropStrip(targetSlug) {
  const sourceSlug = state.dragSlug;
  document.querySelectorAll('.strip-item').forEach(node => node.classList.remove('dragging'));
  if (!sourceSlug || sourceSlug === targetSlug) return;

  collectComicsConfigFromForm();
  const ordered = [...state.configs.comics.strips];
  const from = ordered.findIndex(strip => strip.slug === sourceSlug);
  const to = ordered.findIndex(strip => strip.slug === targetSlug);
  if (from < 0 || to < 0) return;
  ordered.splice(to, 0, ordered.splice(from, 1)[0]);

  state.configs.comics.strips = ordered.map((strip, index) => ({ ...strip, order: index + 1 }));
  markDirty('comics');
  renderPanel('comics', { force: true });
}

function addComicStripFromUrl() {
  const url = el('newComicUrl').value.trim();
  const match = url.match(/gocomics\.com\/([A-Za-z0-9_-]+)/i);
  if (!match) {
    toast('Paste a full GoComics URL', 'error');
    return;
  }
  const slug = match[1].toLowerCase();
  collectComicsConfigFromForm();
  if (state.configs.comics.strips.some(strip => strip.slug === slug)) {
    toast('That comic is already in the list', 'error');
    return;
  }
  state.configs.comics.strips.push({
    slug: slug,
    label: normalizeNameFromPrefix(slug.replace(/-/g, ' ')),
    type: 'gocomics',
    enabled: true,
    order: state.configs.comics.strips.length + 1,
    fetch_mode: 'auto',
    image_url: '',
  });
  el('newComicUrl').value = '';
  markDirty('comics');
  renderPanel('comics', { force: true });
}

function removeComicStrip(slug) {
  collectComicsConfigFromForm();
  state.configs.comics.strips = state.configs.comics.strips
    .filter(strip => strip.slug !== slug)
    .map((strip, index) => ({ ...strip, order: index + 1 }));
  markDirty('comics');
  renderPanel('comics', { force: true });
}

async function uploadComicStrip(slug, files) {
  const file = files?.[0];
  if (!file) return;
  try {
    const formData = new FormData();
    formData.append('slug', slug);
    formData.append('file', file);
    await api('comics_upload_strip', { method: 'POST', body: formData });
    const strip = state.configs.comics.strips.find(item => item.slug === slug);
    if (strip) {
      strip.fetch_mode = 'upload';
      strip.image_url = '';
    }
    const modeInput = document.querySelector(`[data-strip-fetch-mode="${CSS.escape(slug)}"]`);
    if (modeInput) modeInput.value = 'upload';
    const urlInput = document.querySelector(`[data-strip-image-url="${CSS.escape(slug)}"]`);
    if (urlInput) urlInput.value = '';
    await persistComicsModuleConfig();
    await loadComicsPreview();
    clearDirty('comics');
    state.previewVersion = Date.now();
    renderPanel('comics', { force: true });
    toast('Comic strip uploaded');
  } catch (error) {
    toast(error.message || 'Comic upload failed', 'error');
  }
}

async function importComicStripUrl(slug) {
  const url = document.querySelector(`[data-strip-image-url="${CSS.escape(slug)}"]`)?.value?.trim() || '';
  if (!/^https?:\/\//i.test(url)) {
    toast('Paste a full image URL', 'error');
    return;
  }
  try {
    const modeInput = document.querySelector(`[data-strip-fetch-mode="${CSS.escape(slug)}"]`);
    if (modeInput) modeInput.value = 'url';
    await persistComicsModuleConfig();
    await api('comics_import_url', {
      method: 'POST',
      body: { slug, url },
    });
    await loadComicsPreview();
    clearDirty('comics');
    state.previewVersion = Date.now();
    renderPanel('comics', { force: true });
    toast('Comic strip imported from URL');
  } catch (error) {
    toast(error.message || 'Comic URL import failed', 'error');
  }
}

async function openPaperModal() {
  el('paperModal').classList.remove('hidden');
  el('paperModal').classList.add('flex');
  if (!state.papers) {
    el('paperModalBody').textContent = 'Loading…';
    try {
      const data = await api('newspapers');
      state.papers = data.papers || [];
    } catch (error) {
      el('paperModalBody').textContent = `Could not load the paper list: ${error.message}`;
      return;
    }
  }
  const list = state.papers.map((paper, index) => `
    <button type="button" data-action="add-paper" data-paper-index="${index}" class="mb-2 flex w-full items-center justify-between rounded-2xl border border-white/10 px-4 py-3 text-left transition hover:border-amber-400/50 hover:bg-white/5">
      <span>${escapeHtml(paper.name)}</span>
      <span class="text-xs text-slate-500">${escapeHtml(paper.prefix)}</span>
    </button>
  `).join('') || '<p class="text-sm text-slate-400">No papers found.</p>';

  el('paperModalBody').innerHTML = `
    <div class="mb-5 rounded-3xl border border-white/10 bg-black/10 p-4">
      <h3 class="text-sm font-semibold">Add by tag</h3>
      <p class="mt-1 text-xs text-slate-400">Paste a newspaper tag like <code>NY_NYT</code>. Name is optional.</p>
      <div class="mt-3 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
        <input id="customPaperPrefix" type="text" placeholder="NY_NYT" class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        <input id="customPaperName" type="text" placeholder="New York Times" class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-inherit outline-none">
        <button type="button" onclick="addCustomPaper()" class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950">Add</button>
      </div>
    </div>
    ${list}
  `;
}

function closePaperModal() {
  el('paperModal').classList.add('hidden');
  el('paperModal').classList.remove('flex');
}

async function openComicsAuthModal() {
  el('comicsAuthModal').classList.remove('hidden');
  el('comicsAuthModal').classList.add('flex');
  el('comicsAuthCookiePaste').value = '';
  lucide.createIcons();
  try {
    const data = await api('comics_auth_status');
    let cls, msg;
    if (!data.configured) {
      cls = 'border-rose-500/40 bg-rose-500/10 text-rose-200';
      msg = 'GoComics cookies not configured. Strips will be blocked.';
    } else if (data.expired) {
      cls = 'border-rose-500/40 bg-rose-500/10 text-rose-200';
      msg = `Cookies expired ${data.expires_str}. Paste fresh cookies below.`;
    } else if (data.expiring_soon) {
      cls = 'border-amber-400/40 bg-amber-400/10 text-amber-200';
      msg = `Cookies expire ${data.expires_str} (in less than 3 days). Consider refreshing. Last refreshed: ${data.refreshed_at} via ${data.source}.`;
    } else {
      cls = 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200';
      msg = `Cookies valid until ${data.expires_str}. Last refreshed: ${data.refreshed_at} via ${data.source}.`;
    }
    el('comicsAuthStatus').className = `rounded-2xl border px-4 py-3 text-sm ${cls}`;
    el('comicsAuthStatus').textContent = msg;
  } catch (e) {
    el('comicsAuthStatus').textContent = 'Could not load cookie status.';
  }
}

function closeComicsAuthModal() {
  el('comicsAuthModal').classList.add('hidden');
  el('comicsAuthModal').classList.remove('flex');
}

async function submitComicsCookies() {
  const raw = el('comicsAuthCookiePaste').value.trim();
  if (!raw) { toast('Paste cookie data first', 'error'); return; }
  if (!raw.includes('bunny_shield')) { toast('Cookie data must contain bunny_shield', 'error'); return; }
  try {
    const data = await api('comics_save_cookies', { method: 'POST', body: { cookies: raw } });
    toast(data.message || 'Cookies saved');
    closeComicsAuthModal();
  } catch (e) {
    toast(e.message || 'Failed to save cookies', 'error');
  }
}

// Copy the newspaper form (prefix/style/enabled) into state.configs.newspaper.
function collectNewspaperFromForm() {
  for (const [name, paper] of Object.entries(state.configs.newspaper || {})) {
    const styleInput = document.querySelector(`[data-paper-style="${CSS.escape(name)}"]`);
    const prefixInput = document.querySelector(`[data-paper-prefix="${CSS.escape(name)}"]`);
    const enabledInput = document.querySelector(`[data-paper-enabled="${CSS.escape(name)}"]`);
    paper.style = styleInput?.value || paper.style;
    paper.prefix = (prefixInput?.value || paper.prefix || '').trim();
    paper.enabled = enabledInput ? !!enabledInput.checked : paper.enabled !== false;
  }
  return state.configs.newspaper;
}

function addPaper(name, prefix, style) {
  prefix = String(prefix || '').trim();
  if (!/^[A-Za-z0-9_-]+$/.test(prefix)) {
    toast('Newspaper tags may only contain letters, numbers, _ and -', 'error');
    return;
  }
  collectNewspaperFromForm();
  const key = String(name || '').replace(/[^A-Za-z0-9]/g, '') || prefix;
  state.configs.newspaper[key] = { prefix, style, enabled: true };
  markDirty('newspaper');
  closePaperModal();
  renderPanel('newspaper', { force: true });
}

function addCustomPaper() {
  const prefix = el('customPaperPrefix').value.trim();
  if (!prefix) {
    toast('Enter a newspaper tag like NY_NYT', 'error');
    return;
  }
  const rawName = el('customPaperName').value.trim();
  const name = rawName || normalizeNameFromPrefix(prefix);
  const style = prefix.indexOf('NY_') === 0 ? 'width:100%;margin:-70px 0 0 0' : 'width:99%;margin:-4.6rem 0 0 0';
  addPaper(name, prefix, style);
}

function removeNewspaper(name) {
  collectNewspaperFromForm();
  delete state.configs.newspaper[name];
  markDirty('newspaper');
  renderPanel('newspaper', { force: true });
}

async function restartContainer() {
  if (!confirm('Restart the web content container now?\n\nThis will briefly interrupt the live frame, admin UI, and module previews while the container comes back up.')) return;
  try {
    await api('restart', { method: 'POST' });
    toast('Restart requested');
  } catch (error) {
    toast(`Restart failed: ${error.message}`, 'error');
  }
}

async function testHaConfig() {
  try {
    const config = readHaForm();
    // A blank token field makes the server test with the saved token.
    const result = await api('ha_status', { method: 'POST', body: config });
    state.haStatus = {
      kind: 'success',
      message: `${result.entity_id} is currently "${result.state}". ${result.is_home ? 'Frame would stay active.' : 'Frame would pause.'}`,
    };
    renderHaPanel();
    toast('Home Assistant connection looks good');
  } catch (error) {
    state.haStatus = { kind: 'error', message: error.message };
    renderHaPanel();
    toast(error.message, 'error');
  }
}

async function saveGeneralConfig() {
  try {
    const config = readGeneralForm();
    const result = await api('general_config', { method: 'POST', body: config });
    state.generalConfig = result.config || config;
    applyFramePreviewMetrics();
    const notified = sendSocketTask({ task: 'refreshActivity' });
    await loadSystemStatus();
    renderHaPanel();
    renderCurrentPanel();
    syncPreviewScale();
    toast(`General settings saved (${frameResolutionLabel()})`);
    if (!notified) {
      toast('The display is not connected, so it did not refresh its activity yet.', 'warning');
    }
  } catch (error) {
    toast(error.message, 'error');
  }
}

async function saveHaConfig() {
  try {
    const config = readHaForm();
    const result = await api('ha_config', { method: 'POST', body: config });
    state.haConfig = result.config;
    const notified = sendSocketTask({ task: 'refreshActivity' });
    if (!notified) {
      toast('The display is not connected, so it did not refresh its activity yet.', 'warning');
    }
    await loadSystemStatus();
    state.haStatus = {
      kind: 'success',
      message: state.haConfig.enabled
        ? 'Home Assistant presence pause saved. The display worker was asked to refresh activity immediately.'
        : 'Home Assistant presence pause disabled.',
    };
    renderHaPanel();
    toast('Home Assistant settings saved');
  } catch (error) {
    state.haStatus = { kind: 'error', message: error.message };
    renderHaPanel();
    toast(error.message, 'error');
  }
}

el('themeBtn').onclick = toggleTheme;
el('restartBtn').onclick = restartContainer;
el('globalPauseBtn').onclick = togglePause;
el('haSettingsBtn').onclick = toggleHaPanel;
el('accountBtn').onclick = toggleAccountPanel;
el('haSettingsPanel')?.addEventListener('click', event => {
  event.stopPropagation();
});
el('accountPanel')?.addEventListener('click', event => {
  event.stopPropagation();
});
document.addEventListener('click', event => {
  const panel = el('haSettingsPanel');
  const button = el('haSettingsBtn');
  const accountPanel = el('accountPanel');
  const accountButton = el('accountBtn');
  if (panel && button && !panel.classList.contains('hidden') && !panel.contains(event.target) && !button.contains(event.target)) {
    closeHaPanel();
  }
  if (accountPanel && accountButton && !accountPanel.classList.contains('hidden') && !accountPanel.contains(event.target) && !accountButton.contains(event.target)) {
    closeAccountPanel();
  }
});
document.addEventListener('input', event => {
  const section = dirtySectionForTarget(event.target);
  if (section) {
    markDirty(section);
  }
});
document.addEventListener('change', event => {
  const section = dirtySectionForTarget(event.target);
  if (section) {
    markDirty(section);
  }
});
// Delegated handlers: user-controlled values (file names, slugs, slot names, paper names)
// travel in data- attributes, never inside inline onclick strings.
document.addEventListener('click', event => {
  const target = event.target.closest('[data-action]');
  if (!target) return;
  const d = target.dataset;
  switch (d.action) {
    case 'toggle-slot-page': toggleSlotPage(d.slotName, d.page); break;
    case 'remove-slot': removeScheduleSlot(d.slotName); break;
    case 'save-slot': saveScheduleSlot(d.slotName); break;
    case 'remove-newspaper': removeNewspaper(d.name); break;
    case 'delete-image': deleteImage(d.module, d.file); break;
    case 'remove-strip': removeComicStrip(d.slug); break;
    case 'import-strip-url': importComicStripUrl(d.slug); break;
    case 'add-paper': {
      const paper = state.papers?.[Number(d.paperIndex)];
      if (paper) addPaper(paper.name, paper.prefix, paper.style);
      break;
    }
  }
});
document.addEventListener('change', event => {
  const target = event.target;
  if (target.dataset?.uploadGallery) {
    uploadImages(target.dataset.uploadGallery, target.files).finally(() => { target.value = ''; });
  } else if (target.dataset?.uploadStrip) {
    uploadComicStrip(target.dataset.uploadStrip, target.files).finally(() => { target.value = ''; });
  }
});
document.addEventListener('dragstart', event => {
  const strip = event.target.closest?.('[data-strip]');
  if (strip) startStripDrag(strip.dataset.strip);
});
document.addEventListener('dragover', event => {
  if (event.target.closest?.('[data-strip]')) event.preventDefault();
});
document.addEventListener('drop', event => {
  const strip = event.target.closest?.('[data-strip]');
  if (!strip) return;
  event.preventDefault();
  dropStrip(strip.dataset.strip);
});
window.addEventListener('resize', syncPreviewScale);
boot();
</script>
</body>
</html>
