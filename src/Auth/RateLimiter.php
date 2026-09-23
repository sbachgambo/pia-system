<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use PDO;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table (§7, DEV-2).
 *
 * Each call to hit() counts one request against a bucket for the current
 * window. When the count exceeds the limit it returns the seconds until the
 * window rolls over. Counting is race-safe via INSERT ... ON DUPLICATE KEY
 * UPDATE against the unique (bucket_key, window_started_at) index.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxHits,
        private readonly int $windowSeconds,
    ) {
    }

    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public function hit(string $bucketKey): array
    {
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);
        $windowStartSql = (new DateTimeImmutable("@{$windowStart}"))->format('Y-m-d H:i:s');

        $insert = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_started_at, hits, created_at, updated_at)
             VALUES (:k, :w, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE hits = hits + 1, updated_at = UTC_TIMESTAMP()'
        );
        $insert->execute(['k' => $bucketKey, 'w' => $windowStartSql]);

        $select = $this->pdo->prepare(
            'SELECT hits FROM rate_limits WHERE bucket_key = :k AND window_started_at = :w'
        );
        $select->execute(['k' => $bucketKey, 'w' => $windowStartSql]);
        $count = (int) $select->fetchColumn();

        $retryAfter = ($windowStart + $this->windowSeconds) - $now;

        return [
            'allowed'     => $count <= $this->maxHits,
            'remaining'   => max(0, $this->maxHits - $count),
            'retry_after' => max(1, $retryAfter),
        ];
    }

    /** Enforce, throwing 429 when the bucket is over its limit. */
    public function enforce(string $bucketKey): void
    {
        $result = $this->hit($bucketKey);

        if (!$result['allowed']) {
            throw new RateLimitedException($result['retry_after']);
        }
    }

    /** For the nightly cron (Phase 7). */
    public function purgeOldWindows(DateTimeImmutable $olderThan): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_started_at < :cutoff');
        $stmt->execute(['cutoff' => $olderThan->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }
}
