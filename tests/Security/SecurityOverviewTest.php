<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecurityOverview;
use App\Tests\Support\DatabaseTestCase;

final class SecurityOverviewTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['rate_limits', 'refresh_tokens', 'users'];
    }

    private function bucket(string $key, int $hits): void
    {
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_started_at, hits, created_at, updated_at)
             VALUES (:k, UTC_TIMESTAMP(), :h, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute(['k' => $key, 'h' => $hits]);
    }

    public function testBucketsAreParsedFlaggedAndSummarised(): void
    {
        $this->bucket('auth:/console/login:203.0.113.9', 14);
        $this->bucket('auth:/api/auth/login:2001:db8::1', 3);
        $o = new SecurityOverview($this->pdo, 10, 900);

        $b = $o->busiestBuckets();
        self::assertSame('203.0.113.9', $b[0]['ip']);
        self::assertSame('/console/login', $b[0]['path']);
        self::assertTrue($b[0]['blocked']);
        self::assertSame('2001:db8::1', $b[1]['ip'], 'IPv6 addresses keep their colons');
        self::assertFalse($b[1]['blocked']);

        $s = $o->rateLimitSummary();
        self::assertSame(1, $s['blocked_windows']);
        self::assertSame(2, $s['distinct_ips']);
        self::assertSame(17, $s['total_hits']);
        self::assertSame(15, $o->windowMinutes());
    }

    public function testAccountHygieneCounts(): void
    {
        $this->makeUser(['role' => 'admin']);
        $this->makeUser(['status' => 'suspended']);
        $h = (new SecurityOverview($this->pdo, 10, 900))->accountHygiene();

        self::assertSame(1, $h['active']);
        self::assertSame(1, $h['suspended']);
        self::assertSame(1, $h['never_signed_in']);
        self::assertSame(1, $h['admins']);
    }
}
