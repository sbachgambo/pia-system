<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Support\Csrf;
use App\Http\Support\SessionStore;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableWithinASessionAndValidates(): void
    {
        $csrf = new Csrf(new SessionStore());

        $t1 = $csrf->token();
        $t2 = $csrf->token();

        self::assertSame($t1, $t2);
        self::assertSame(64, strlen($t1));
        self::assertTrue($csrf->isValid($t1));
    }

    public function testRejectsWrongOrMissingToken(): void
    {
        $csrf = new Csrf(new SessionStore());
        $csrf->token();

        self::assertFalse($csrf->isValid('nope'));
        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(''));
    }

    public function testRejectsWhenSessionHasNoToken(): void
    {
        // A fresh session with nothing issued yet.
        self::assertFalse((new Csrf(new SessionStore()))->isValid('anything'));
    }

    public function testRotateInvalidatesTheOldToken(): void
    {
        $session = new SessionStore();
        $csrf = new Csrf($session);

        $old = $csrf->token();
        $new = $csrf->rotate();

        self::assertNotSame($old, $new);
        self::assertFalse($csrf->isValid($old));
        self::assertTrue($csrf->isValid($new));
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        self::assertNotSame(
            (new Csrf(new SessionStore()))->token(),
            (new Csrf(new SessionStore()))->token(),
        );
    }
}
