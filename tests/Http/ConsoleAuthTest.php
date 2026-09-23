<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Auth\AccountSuspendedException;
use App\Auth\InvalidCredentialsException;
use App\Auth\PasswordHasher;
use App\Auth\UserRepository;
use App\Http\Console\ConsoleAuth;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class ConsoleAuthTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'users'];
    }

    private function auth(): ConsoleAuth
    {
        return new ConsoleAuth(
            new UserRepository($this->pdo),
            new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]),
        );
    }

    public function testOfficeReviewerCanSignIn(): void
    {
        $u = $this->makeUser(['email' => 'rev@adwol.test', 'password' => 'Console-1', 'role' => 'office_reviewer']);

        $user = $this->auth()->attempt('  REV@adwol.test ', 'Console-1');

        self::assertSame($u['uuid'], $user['uuid']);

        $stamped = $this->pdo->query('SELECT last_login_at FROM users WHERE email = "rev@adwol.test"')->fetchColumn();
        self::assertNotNull($stamped);
    }

    public function testWrongPasswordRejected(): void
    {
        $this->makeUser(['email' => 'a@adwol.test', 'password' => 'Console-1', 'role' => 'admin']);

        $this->expectException(InvalidCredentialsException::class);
        $this->auth()->attempt('a@adwol.test', 'bad');
    }

    public function testUnknownEmailRejected(): void
    {
        $this->expectException(InvalidCredentialsException::class);
        $this->auth()->attempt('ghost@adwol.test', 'whatever');
    }

    public function testSuspendedRejected(): void
    {
        $this->makeUser(['email' => 's@adwol.test', 'password' => 'Console-1', 'role' => 'admin', 'status' => 'suspended']);

        $this->expectException(AccountSuspendedException::class);
        $this->auth()->attempt('s@adwol.test', 'Console-1');
    }

    public function testInspectorCannotUseTheConsole(): void
    {
        $this->makeUser(['email' => 'insp@adwol.test', 'password' => 'Console-1', 'role' => 'inspector']);

        try {
            $this->auth()->attempt('insp@adwol.test', 'Console-1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('forbidden', $e->getErrorCode());
        }
    }
}
