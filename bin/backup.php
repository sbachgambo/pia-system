#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Create a backup (database + generated/uploaded files) and prune old ones.
 * Intended for a hosting cron job:
 *
 *   30 1 * * *  cd /path/to/app && php bin/backup.php --keep=14 >> storage/logs/backup.log 2>&1
 *
 * Options:
 *   --keep=N   keep the newest N backups (default 14), delete the rest
 *
 * The archive lands in storage/backups/ — outside the web root. It contains
 * every password hash and all client data: copy it OFF the server (that is the
 * point of a backup) and protect it accordingly.
 */

use App\Backup\BackupService;
use App\Http\ContainerFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$keep = 14;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m) === 1) {
        $keep = max(1, (int) $m[1]);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php bin/backup.php [--keep=N]\n");
        exit(2);
    }
}

$service = ContainerFactory::create()->get(BackupService::class);

try {
    $b = $service->create();
    $pruned = $service->prune($keep);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('c') . '] backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "[%s] backup ok: %s (%s KB, %d tables, %d rows, %d files%s), pruned %d old\n",
    gmdate('c'),
    $b['name'],
    number_format($b['bytes'] / 1024, 1, '.', ''),
    $b['tables'],
    $b['rows'],
    $b['files'],
    $b['includes_files'] ? '' : '; DB only — ext-zip missing, copy storage/ separately',
    $pruned,
);
