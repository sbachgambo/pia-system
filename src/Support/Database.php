<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO factory with the strict, no-surprises settings the brief mandates
 * (§7: "PDO with prepared statements throughout — no raw query concatenation").
 *
 * - ERRMODE_EXCEPTION so a failed query is never silently ignored.
 * - EMULATE_PREPARES = false so placeholders are real server-side prepares,
 *   not client-side string interpolation.
 * - DEFAULT_FETCH_MODE = assoc so callers never depend on numeric columns.
 * - utf8mb4 connection charset to match the schema (§4).
 *
 * Not a singleton by design: the DI container owns the single shared instance
 * for a request; tests build their own.
 */
final class Database
{
    /** @param array<string,mixed> $config The `db` slice of settings. */
    public static function connect(array $config): PDO
    {
        $driver = $config['driver'] ?? 'mysql';

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                "Unsupported DB driver [{$driver}]. This system targets MySQL 8 / MariaDB 10.6+ (brief D5)."
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4',
        );

        try {
            $pdo = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ],
            );
        } catch (PDOException $e) {
            // Message may contain the DSN but never the password.
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        // Enforce UTC at the session level so NOW()/CURRENT_TIMESTAMP and any
        // server-side date math stay in UTC regardless of server config (§4).
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /**
     * Lightweight connectivity probe for the health endpoint.
     *
     * @return array{ok:bool, error?:string, latency_ms?:float}
     */
    public static function ping(PDO $pdo): array
    {
        $start = microtime(true);

        try {
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok'         => true,
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }
}
