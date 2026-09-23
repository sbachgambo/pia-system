#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Send the daily "needs attention" digest email. For a hosting cron job, e.g.
 * every weekday at 07:30:
 *
 *   30 7 * * 1-5  cd /path/to/app && php bin/send-digest.php >> storage/logs/digest.log 2>&1
 *
 * Does nothing (and says so) when MAIL_HOST isn't configured. Nobody is
 * emailed when there is nothing waiting on them.
 */

use App\Http\ContainerFactory;
use App\Notifications\DigestService;

require dirname(__DIR__) . '/vendor/autoload.php';

$result = ContainerFactory::create()->get(DigestService::class)->send();

if (!$result['enabled']) {
    printf("[%s] digest skipped: email is not configured (MAIL_HOST).\n", gmdate('c'));
    exit(0);
}

printf("[%s] digest: %d sent, %d skipped (nothing to report), %d failed\n", gmdate('c'), $result['sent'], $result['skipped'], $result['failed']);
exit($result['failed'] > 0 ? 1 : 0);
