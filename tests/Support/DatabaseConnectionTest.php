<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The DB layer must be strict by construction (brief §7): real prepared
 * statements, exceptions on error, and a UTC session regardless of server
 * config (§4).
 */
final class DatabaseConnectionTest extends TestCase
{
    private static function config(): array
    {
        return (require dirname(__DIR__, 2) . '/config/settings.php')['db'];
    }

    public function testConnectsAndPings(): void
    {
        $pdo = Database::connect(self::config());
        $result = Database::ping($pdo);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertArrayHasKey('latency_ms', $result);
    }

    public function testUsesRealPreparedStatementsNotEmulation(): void
    {
        $pdo = Database::connect(self::config());

        // PDO reports this as int 0 on some drivers, bool false on others.
        self::assertFalse(
            (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
            'Emulated prepares would reintroduce string interpolation risk (§7).'
        );
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testSessionTimeZoneIsUtc(): void
    {
        $pdo = Database::connect(self::config());
        $tz = $pdo->query('SELECT @@session.time_zone')->fetchColumn();

        self::assertSame('+00:00', $tz, 'All timestamps are stored/computed in UTC (§4).');
    }

    public function testRejectsNonMysqlDriver(): void
    {
        $this->expectExceptionMessage('Unsupported DB driver');
        Database::connect(['driver' => 'pgsql'] + self::config());
    }
}
