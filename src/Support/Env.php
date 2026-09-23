<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Thin wrapper over vlucas/phpdotenv.
 *
 * Loads a single env file into $_ENV/$_SERVER (never overwriting real
 * environment variables set by the host) and provides typed accessors that
 * FAIL FAST when a required key is absent or blank. Config bugs should stop
 * the app at boot, not surface as a null three layers deep.
 */
final class Env
{
    private static bool $loaded = false;

    /**
     * @param string      $basePath Project root (contains the env file).
     * @param string|null  $file     Env filename; defaults to `.env`, or
     *                               `.env.testing` when APP_ENV=testing.
     */
    public static function load(string $basePath, ?string $file = null): void
    {
        if (self::$loaded) {
            return;
        }

        $file ??= (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null) === 'testing')
            ? '.env.testing'
            : '.env';

        if (is_file($basePath . DIRECTORY_SEPARATOR . $file)) {
            // safeLoad: do not throw if the file is missing (host may inject
            // real env vars instead); createImmutable: never clobber those.
            Dotenv::createImmutable($basePath, $file)->safeLoad();
        }

        self::$loaded = true;
    }

    /** Force a reload — test bootstrap only. */
    public static function reset(): void
    {
        self::$loaded = false;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException(
                "Required environment variable [{$key}] is missing or blank. "
                . 'Copy .env.example to .env and fill it in.'
            );
        }

        return $value;
    }

    public static function getInt(string $key, ?int $default = null): int
    {
        $value = self::get($key);

        if ($value === null) {
            if ($default === null) {
                throw new RuntimeException("Required integer env var [{$key}] is missing.");
            }
            return $default;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new RuntimeException("Env var [{$key}] must be an integer, got: {$value}");
        }

        return (int) $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new RuntimeException("Env var [{$key}] must be boolean, got: {$value}"),
        };
    }
}
