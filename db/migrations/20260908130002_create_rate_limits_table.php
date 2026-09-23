<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * rate_limits (DEV-2) — fixed-window request counter for §7 rate limiting.
 *
 * One row per (bucket, time-window). A bucket is something like
 * "auth:ip:203.0.113.7" or "login:email:foo@example.com". The window start is
 * "now" floored to the configured window length. Counting is race-safe via
 *   INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1
 * against the unique (bucket_key, window_started_at) index. Stale rows are
 * pruned opportunistically and by the nightly cron (Phase 7).
 *
 * No Redis/memcached on shared hosting, so this lives in MySQL by design.
 */
final class CreateRateLimitsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('rate_limits', $this->tableOptions('Fixed-window rate-limit counters (§7)'));

        $this->addId($table);

        $table
            ->addColumn('bucket_key', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('window_started_at', 'datetime', ['null' => false])
            ->addColumn('hits', 'integer', ['signed' => false, 'null' => false, 'default' => 0]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['bucket_key', 'window_started_at'], ['unique' => true, 'name' => 'rate_limits_bucket_window_uq'])
            ->addIndex(['window_started_at'], ['name' => 'rate_limits_window_idx'])
            ->create();
    }
}
