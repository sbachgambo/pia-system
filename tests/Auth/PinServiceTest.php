<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\InvalidCredentialsException;
use App\Auth\PasswordHasher;
use App\Auth\PinService;
use App\Auth\UserRepository;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class PinServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'users'];
    }

    private function service(): PinService
    {
        return new PinService(
            new UserRepository($this->pdo),
            new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]),
            minLength: 4,
            maxLength: 8,
        );
    }

    /** @return array<string,mixed> */
    private function userRow(string $email): array
    {
        return (new UserRepository($this->pdo))->findByEmail($email);
    }

    public function testSetStoresAVerifiableArgon2idHash(): void
    {
        $u = $this->makeUser(['email' => 'pin1@adwol.test', 'password' => 'Field-Pass-1', 'role' => 'inspector']);

        $this->service()->set($this->userRow('pin1@adwol.test'), 'Field-Pass-1', '8261');

        $hash = (string) $this->pdo->query('SELECT pin_hash FROM users WHERE id = ' . $u['id'])->fetchColumn();
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify('8261', $hash));
        self::assertFalse(password_verify('0000', $hash));
    }

    public function testSetRequiresTheCurrentPassword(): void
    {
        $this->makeUser(['email' => 'pin2@adwol.test', 'password' => 'Field-Pass-1']);

        $this->expectException(InvalidCredentialsException::class);
        $this->service()->set($this->userRow('pin2@adwol.test'), 'wrong-password', '8261');
    }

    /**
     * @dataProvider weakPins
     */
    public function testWeakPinsAreRejected(string $pin): void
    {
        $this->makeUser(['email' => 'pin3@adwol.test', 'password' => 'Field-Pass-1']);

        try {
            $this->service()->set($this->userRow('pin3@adwol.test'), 'Field-Pass-1', $pin);
            self::fail("PIN '{$pin}' should have been rejected");
        } catch (ApiException $e) {
            self::assertSame('weak_pin', $e->getErrorCode());
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertNull($this->pdo->query('SELECT pin_hash FROM users WHERE email = "pin3@adwol.test"')->fetchColumn());
    }

    /** @return array<string,array{0:string}> */
    public static function weakPins(): array
    {
        return [
            'too short'       => ['123'],
            'too long'        => ['123456789'],
            'not digits'      => ['12a4'],
            'all same'        => ['7777'],
            'ascending run'   => ['3456'],
            'descending run'  => ['8765'],
            'wrapping run'    => ['9012'],
        ];
    }

    public function testClearNullsThePin(): void
    {
        $u = $this->makeUser(['email' => 'pin4@adwol.test', 'password' => 'Field-Pass-1']);
        $svc = $this->service();

        $svc->set($this->userRow('pin4@adwol.test'), 'Field-Pass-1', '8261');
        self::assertNotNull($this->pdo->query('SELECT pin_hash FROM users WHERE id = ' . $u['id'])->fetchColumn());

        $svc->clear($u['id']);
        self::assertNull($this->pdo->query('SELECT pin_hash FROM users WHERE id = ' . $u['id'])->fetchColumn());
    }
}
