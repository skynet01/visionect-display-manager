#!/usr/local/bin/php
<?php

require dirname(__DIR__) . '/lib/security.php';

$prefs = visionect_read_json_file(dirname(__DIR__) . '/config/PREFS.json') ?? [];
$pages = $prefs['pages'] ?? [];

$isModuleEnabled = function ($moduleName) use ($pages) {
    if (!array_key_exists($moduleName, $pages)) {
        return true;
    }

    return !array_key_exists('enabled', $pages[$moduleName]) || (bool)$pages[$moduleName]['enabled'];
};

$cronLockPath = function ($module) {
    return dirname(__DIR__) . '/config/cron_' . preg_replace('/[^a-z0-9_\-]/i', '_', $module) . '.lock';
};

$htdocsDir = dirname(__DIR__) . '/htdocs';
$dirs = glob($htdocsDir . '/*', GLOB_ONLYDIR);

$runCron = function ($dir, $kind = 'scheduled') use ($cronLockPath) {
    $module = basename($dir);
    $lockHandle = fopen($cronLockPath($module), 'c+');
    if ($lockHandle === false) {
        print "!! Could not create cron lock for {$module}\n";
        return;
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        print "!! Skipping {$module}; cron already running\n";
        fclose($lockHandle);
        return;
    }

    visionect_update_runtime_status([
        'cron' => [
            $module => [
                'running' => true,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_kind' => $kind,
            ],
        ],
    ]);

    print "!! Running cron script {$dir}/cron.php...\n";
    $output = shell_exec($dir . '/cron.php');
    print $output;

    visionect_update_runtime_status([
        'cron' => [
            $module => [
                'running' => false,
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'last_run_kind' => $kind,
                'last_output' => trim((string)$output),
            ],
        ],
    ]);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
};

// Run all modules except ainews first, then ainews last
$deferred = [];
foreach ($dirs as $dir) {
    if (!file_exists($dir . '/cron.php')) continue;
    if (!$isModuleEnabled(basename($dir))) {
        print "!! Skipping disabled module " . basename($dir) . "\n";
        continue;
    }
    if (basename($dir) === 'ainews') { $deferred[] = $dir; continue; }
    $runCron($dir);
}
foreach ($deferred as $dir) {
    if (!$isModuleEnabled(basename($dir))) {
        print "!! Skipping disabled module " . basename($dir) . "\n";
        continue;
    }
    $runCron($dir);
}
