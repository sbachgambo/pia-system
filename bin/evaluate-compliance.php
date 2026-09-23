#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Nightly compliance evaluation (brief §8). Intended to be invoked by a
 * hosting cron job:
 *
 *   0 2 * * *  php /path/to/bin/evaluate-compliance.php >> storage/logs/compliance.log 2>&1
 *
 * By default evaluates the current calendar month (re-running it nightly is
 * intentional — the month's figures firm up as more scheduled_at deadlines
 * pass). Pass --month=YYYY-MM to (re-)evaluate a specific month, e.g. to
 * backfill history after changing COMPLIANCE_GRACE_HOURS.
 *
 * Idempotent: ComplianceRepository::upsert() overwrites, never duplicates.
 */

use App\Compliance\ComplianceEvaluator;
use App\Http\ContainerFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['month::']);
$month = is_string($options['month'] ?? null) ? trim((string) $options['month']) : null;

if ($month !== null && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    fwrite(STDERR, "--month must be YYYY-MM (got \"{$month}\")\n");
    exit(1);
}

$container = ContainerFactory::create();
$evaluator = $container->get(ComplianceEvaluator::class);

$target = $month !== null ? new DateTimeImmutable($month . '-01') : new DateTimeImmutable('now');

$results = $evaluator->evaluateMonth($target);

$alerts = array_filter($results, static fn (array $r): bool => $r['alert_triggered']);

printf(
    "[%s] Evaluated %s: %d inspector(s), %d alert(s).\n",
    gmdate('c'),
    $target->format('Y-m'),
    count($results),
    count($alerts),
);

foreach ($alerts as $a) {
    printf("  ALERT  %-30s zone=%-15s streak=%d\n", $a['inspector_name'], $a['inspector_zone'] ?? '-', $a['consecutive_miss_count']);
}

exit(0);
