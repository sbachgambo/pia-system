<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private function hasher(array $opts = ['memory_cost' => 65536, 'time_cost' => 2, 'threads' => 1]): PasswordHasher
    {
        return new PasswordHasher($opts);
    }

    public function testHashIsArgon2idAndVerifies(): void
    {
        $hash = $this->hasher()->hash('s3cret-value');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($this->hasher()->verify('s3cret-value', $hash));
        self::assertFalse($this->hasher()->verify('wrong', $hash));
    }

    public function testVerifyAgainstEmptyHashIsFalseNotError(): void
    {
        self::assertFalse($this->hasher()->verify('anything', ''));
    }

    public function testNeedsRehashWhenParametersIncrease(): void
    {
        $weak = $this->hasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1])->hash('pw');

        $strong = $this->hasher(['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);

        self::assertTrue($strong->needsRehash($weak));
        self::assertFalse($strong->needsRehash($strong->hash('pw')));
    }
}
