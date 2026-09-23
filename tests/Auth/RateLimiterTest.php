<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\RateLimiter;
use App\Auth\RateLimitedException;
use App\Tests\Support\DatabaseTestCase;

final class RateLimiterTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['rate_limits'];
    }

    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        $limiter = new RateLimiter($this->pdo, maxHits: 3, windowSeconds: 900);
        $bucket = 'auth:/api/auth/login:203.0.113.9';

        self::assertTrue($limiter->hit($bucket)['allowed']);   // 1
        self::assertTrue($limiter->hit($bucket)['allowed']);   // 2

        $third = $limiter->hit($bucket);                       // 3
        self::assertTrue($third['allowed']);
        self::assertSame(0, $third['remaining']);

        $fourth = $limiter->hit($bucket);                      // 4 -> over
        self::assertFalse($fourth['allowed']);
        self::assertGreaterThan(0, $fourth['retry_after']);
    }

    public function testEnforceThrowsWhenOverLimit(): void
    {
        $limiter = new RateLimiter($this->pdo, maxHits: 1, windowSeconds: 900);

        $limiter->enforce('bucket-x'); // ok

        $this->expectException(RateLimitedException::class);
        $limiter->enforce('bucket-x');
    }

    public function testDifferentBucketsAreCountedSeparately(): void
    {
        $limiter = new RateLimiter($this->pdo, maxHits: 1, windowSeconds: 900);

        $limiter->enforce('ip-a');
        $limiter->enforce('ip-b'); // different key, still fine

        $this->addToAssertionCount(1);
    }
}
