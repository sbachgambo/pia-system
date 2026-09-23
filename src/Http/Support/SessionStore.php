<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * Thin wrapper over a key/value session bag. In production it is constructed
 * over $_SESSION (by the SessionMiddleware added in Phase 3, when the
 * server-rendered console exists); in tests it can be constructed over a plain
 * array, so anything that depends on session state stays unit-testable.
 */
final class SessionStore
{
    /** @param array<string,mixed> $bag */
    public function __construct(private array $bag = [])
    {
    }

    public static function overSuperglobal(): self
    {
        $store = new self();
        // Bind the internal bag to $_SESSION by reference.
        $store->bag = &$_SESSION;

        return $store;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->bag);
    }

    public function remove(string $key): void
    {
        unset($this->bag[$key]);
    }
}
