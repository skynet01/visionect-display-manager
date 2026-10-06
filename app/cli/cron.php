#!/usr/local/bin/php
<?php
/**
 * Module cron runner.
 *
 *   php cron.php                      No-op (container start). Prints one line, exits 0.
 *   php cron.php --run                Run every ENABLED module's cron.php (ainews last).
 *   php cron.php --run <module>       Run one module's cron.php (enabled or not), e.g. --run comics.
 *   php cron.php --run ... --dry-run  Show what would run; runs nothing.
 *
 * Scheduled daily from the HOST root crontab:
 *   5 6 * * * docker exec visionect-web-content php /app/cli/cron.php --run
 * Modules missing from PREFS.json, or with "enabled": false, are skipped by --run.
 * Each module runs as `php cron.php 2>&1` in its folder under the same per-module lock as the
 * admin's "Run now" (config/cron_<module>.lock). Exit code: 0 all ok, 1 a module failed, 2 usage.
 */

if (PHP_SAPI !== 'cli') {
    exit('Please run this from the command line');
}

require_once dirname(__DIR__) . '/lib/security.php';

const CRON_HTDOCS_DIR = __DIR__ . '/../htdocs';
const CRON_OUTPUT_LIMIT = 8000;

/** Replace (not merge) the cron_runner summary so its lists don't keep stale entries. */
function cron_record_runner(array $summary): void
{
    visionect_mutate_runtime_status(function (array $status) use ($summary) {
        $status['cron_runner'] = $summary;
        return $status;
    });
}

$args = array_slice($argv ?? [], 1);
if (empty($args)) {
    print "cron.php: no --run given; nothing to do (module crons run from the host crontab).\n";
    exit(0);
}

$run = false;
$dryRun = false;
$only = null;
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--run') {
        $run = true;
        if (isset($args[$i + 1]) && strpos($args[$i + 1], '-') !== 0) {
            $only = $args[++$i];
        }
    } elseif (strpos($arg, '--run=') === 0) {
        $run = true;
        $only = substr($arg, 6);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Usage: php cron.php [--run [module]] [--dry-run]\n");
        exit(2);
    }
}
if (!$run) {
    fwrite(STDERR, "Usage: php cron.php [--run [module]] [--dry-run]\n");
    exit(2);
}

$available = [];
foreach (glob(CRON_HTDOCS_DIR . '/*/cron.php') ?: [] as $file) {
    $available[basename(dirname($file))] = dirname($file);
}
ksort($available, SORT_STRING);

if ($only !== null) {
    $only = trim($only);
    if (!preg_match('/^[a-z0-9_\-]+$/i', $only) || !isset($available[$only])) {
        fwrite(STDERR, "cron.php: unknown module '{$only}'. Modules with a cron.php: " . implode(', ', array_keys($available)) . "\n");
        exit(2);
    }
    $modules = [$only];
    $kind = 'cli';
} else {
    $prefs = visionect_read_json_file(dirname(__DIR__) . '/config/PREFS.json');
    if (!is_array($prefs) || !is_array($prefs['pages'] ?? null)) {
        fwrite(STDERR, "cron.php: PREFS.json missing or invalid; running nothing.\n");
        cron_record_runner([
            'last_run_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'kind' => 'scheduled',
            'ok' => false,
            'ran' => [],
            'failed' => [],
            'skipped' => [],
            'error' => 'PREFS.json missing or invalid',
        ]);
        exit(1);
    }
    $pages = $prefs['pages'];
    $modules = [];
    foreach (array_keys($available) as $module) {
        $page = $pages[$module] ?? null;
        $enabled = is_array($page) && (!array_key_exists('enabled', $page) || (bool)$page['enabled']);
        if (!$enabled) {
            print "!! Skipping " . (is_array($page) ? 'disabled' : 'unlisted') . " module {$module}\n";
            continue;
        }
        $modules[] = $module;
    }
    // ainews (paid image generation, slowest) always runs last.
    usort($modules, function ($a, $b) {
        return [(int)($a === 'ainews'), $a] <=> [(int)($b === 'ainews'), $b];
    });
    $kind = 'scheduled';
}

if ($dryRun) {
    print "cron.php --dry-run: would run " . (empty($modules) ? 'nothing' : implode(', ', $modules)) . "\n";
    exit(0);
}

function cron_lock_path(string $module): string
{
    return dirname(__DIR__) . '/config/cron_' . preg_replace('/[^a-z0-9_\-]/i', '_', $module) . '.lock';
}

function cron_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Run one module cron. Returns its exit code, or null when skipped because it is already running. */
function cron_run_module(string $module, string $dir, string $kind): ?int
{
    $lockHandle = @fopen(cron_lock_path($module), 'c+');
    if ($lockHandle === false) {
        print "!! Could not open the cron lock for {$module}\n";
        return 1;
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        print "!! Skipping {$module}: its cron is already running\n";
        fclose($lockHandle);
        return null;
    }

    visionect_update_runtime_status(['cron' => [$module => [
        'running' => true,
        'started_at' => cron_now(),
        'last_run_kind' => $kind,
    ]]]);

    print "!! Running {$module}/cron.php ({$kind})...\n";
    $command = 'cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' cron.php 2>&1';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $output = trim(implode("\n", $lines));
    print $output . ($output !== '' ? "\n" : '');
    print "!! {$module} finished with exit code {$exitCode}\n";

    if (strlen($output) > CRON_OUTPUT_LIMIT) {
        $output = "...\n" . substr($output, -CRON_OUTPUT_LIMIT);
    }
    $now = cron_now();
    visionect_update_runtime_status(['cron' => [$module => [
        'running' => false,
        'finished_at' => $now,
        'last_run_at' => $now,
        'last_run_kind' => $kind,
        'last_output' => $output,
        // Result of the last cli/cron.php run (the admin's manual runs don't touch this).
        'last_cli_result' => [
            'at' => $now,
            'kind' => $kind,
            'exit_code' => $exitCode,
            'ok' => $exitCode === 0,
        ],
    ]]]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    return $exitCode;
}

$ran = [];
$failed = [];
$skipped = [];
foreach ($modules as $module) {
    $code = cron_run_module($module, $available[$module], $kind);
    if ($code === null) {
        $skipped[] = $module;
    } elseif ($code !== 0) {
        $failed[] = $module;
        $ran[] = $module;
    } else {
        $ran[] = $module;
    }
}

cron_record_runner([
    'last_run_at' => cron_now(),
    'kind' => $kind,
    'ok' => empty($failed),
    'ran' => $ran,
    'failed' => $failed,
    'skipped' => $skipped,
    'error' => null,
]);

print "!! cron.php done: ran " . (empty($ran) ? 'nothing' : implode(', ', $ran))
    . (empty($failed) ? '' : '; FAILED: ' . implode(', ', $failed))
    . (empty($skipped) ? '' : '; skipped (busy): ' . implode(', ', $skipped)) . "\n";
exit(empty($failed) ? 0 : 1);
