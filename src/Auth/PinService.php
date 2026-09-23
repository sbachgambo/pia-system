<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;

/**
 * Sets / clears an inspector's offline PIN (D4, brief §6).
 *
 * Setting a PIN requires re-entering the account password — a stolen access
 * token alone must not be enough to plant a PIN that then unlocks the cached
 * session on a lost device. The PIN itself is hashed with the same Argon2id
 * parameters as the password (§7) and stored in `users.pin_hash`.
 */
final class PinService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly int $minLength = 4,
        private readonly int $maxLength = 8,
    ) {
    }

    /**
     * @param array<string,mixed> $user a `users` row (id + password_hash)
     * @throws InvalidCredentialsException if the password check fails
     * @throws ApiException 422 `weak_pin` if the PIN is too guessable
     */
    public function set(array $user, string $currentPassword, string $pin): void
    {
        if (!$this->hasher->verify($currentPassword, (string) $user['password_hash'])) {
            throw new InvalidCredentialsException();
        }

        $this->assertAcceptable($pin);

        $this->users->updatePinHash((int) $user['id'], $this->hasher->hash($pin));
    }

    public function clear(int $userId): void
    {
        $this->users->updatePinHash($userId, null);
    }

    private function assertAcceptable(string $pin): void
    {
        if (preg_match('/^\d+$/', $pin) !== 1) {
            $this->reject('PIN must contain digits only.');
        }

        $length = strlen($pin);
        if ($length < $this->minLength || $length > $this->maxLength) {
            $this->reject("PIN must be {$this->minLength}\u{2013}{$this->maxLength} digits long.");
        }

        if (preg_match('/^(\d)\1*$/', $pin) === 1) {
            $this->reject('PIN cannot be one digit repeated.');
        }

        if ($this->isRun($pin)) {
            $this->reject('PIN cannot be a simple ascending or descending run.');
        }
    }

    /** True for 1234.., 4321.., wrapping runs like 8901 or 3210. */
    private function isRun(string $pin): bool
    {
        $ascending = true;
        $descending = true;

        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            $step = ((int) $pin[$i] - (int) $pin[$i - 1] + 10) % 10;
            if ($step !== 1) {
                $ascending = false;
            }
            if ($step !== 9) {
                $descending = false;
            }
        }

        return $ascending || $descending;
    }

    private function reject(string $message): never
    {
        throw new ApiException(422, 'weak_pin', $message, ['field' => 'pin']);
    }
}
