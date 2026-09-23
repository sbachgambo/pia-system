<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the migrated schema matches brief §4: the five core tables exist as
 * InnoDB/utf8mb4, the uuid columns are unique CHAR(36), the D1 status enum has
 * all six states, the dashboard composite index is present, and the declared
 * foreign keys exist.
 *
 * (bootstrap.php has already run `phinx migrate -e testing`.)
 *
 * MySQL returns information_schema column names upper-cased; every fetch here
 * is normalised to lower-case keys via row()/rows() so the assertions read
 * consistently.
 */
final class SchemaTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static string $schema = '';

    public static function setUpBeforeClass(): void
    {
        $config = (require dirname(__DIR__, 2) . '/config/settings.php')['db'];
        self::$pdo = Database::connect($config);
        self::$schema = $config['database'];
    }

    /** @return list<string> */
    private static function coreTables(): array
    {
        return ['users', 'clients', 'consignments', 'inspection_requests', 'inspections'];
    }

    public function testAllCoreTablesExistAsInnoDbUtf8mb4(): void
    {
        foreach (self::coreTables() as $table) {
            $row = $this->row(
                'SELECT engine, table_collation
                   FROM information_schema.tables
                  WHERE table_schema = ? AND table_name = ?',
                [self::$schema, $table],
            );

            self::assertNotNull($row, "Table `{$table}` is missing.");
            self::assertSame('InnoDB', $row['engine'], "`{$table}` must be InnoDB.");
            self::assertStringStartsWith('utf8mb4', (string) $row['table_collation'], "`{$table}` must be utf8mb4.");
        }
    }

    public function testUuidColumnsAreUniqueChar36(): void
    {
        foreach (self::coreTables() as $table) {
            $col = $this->column($table, 'uuid');
            self::assertNotNull($col, "`{$table}.uuid` missing.");
            self::assertSame('char', $col['data_type']);
            self::assertSame('36', (string) $col['character_maximum_length']);

            self::assertTrue(
                $this->hasUniqueIndexOn($table, ['uuid']),
                "`{$table}.uuid` must have a UNIQUE index (sync idempotency, §3)."
            );
        }
    }

    public function testInspectionsStatusEnumHasAllSixD1States(): void
    {
        $col = $this->column('inspections', 'status');
        self::assertNotNull($col);

        foreach (['scheduled', 'in_progress', 'synced', 'amended', 'finalized', 'rejected'] as $state) {
            self::assertStringContainsString("'{$state}'", (string) $col['column_type'], "missing D1 state `{$state}`");
        }
    }

    public function testInspectionsHasStatusScheduledCompositeIndex(): void
    {
        $cols = array_column(
            $this->rows(
                'SELECT column_name, seq_in_index
                   FROM information_schema.statistics
                  WHERE table_schema = ? AND table_name = ? AND index_name = ?
               ORDER BY seq_in_index',
                [self::$schema, 'inspections', 'inspections_status_scheduled_idx'],
            ),
            'column_name',
        );

        self::assertSame(['status', 'scheduled_at'], $cols, 'dashboard composite index (§4) missing or wrong order.');
    }

    public function testUsersEmailIsUnique(): void
    {
        self::assertTrue($this->hasUniqueIndexOn('users', ['email']));
    }

    public function testDeclaredForeignKeysExist(): void
    {
        $expected = [
            ['consignments', 'client_id', 'clients'],
            ['inspection_requests', 'consignment_id', 'consignments'],
            ['inspection_requests', 'requested_by', 'users'],
            ['inspections', 'inspection_request_id', 'inspection_requests'],
            ['inspections', 'inspector_id', 'users'],
            ['inspections', 'finalized_by', 'users'],
        ];

        foreach ($expected as [$table, $column, $ref]) {
            $row = $this->row(
                'SELECT referenced_table_name
                   FROM information_schema.key_column_usage
                  WHERE table_schema = ? AND table_name = ? AND column_name = ?
                    AND referenced_table_name IS NOT NULL',
                [self::$schema, $table, $column],
            );

            self::assertNotNull($row, "FK `{$table}.{$column}` -> `{$ref}` missing.");
            self::assertSame($ref, $row['referenced_table_name'], "FK `{$table}.{$column}` -> `{$ref}` missing.");
        }
    }

    public function testInspectionsUuidHasNoForeignKey(): void
    {
        // §4/§3: the server never assigns inspection identity and `id` is
        // internal-only. The uuid is client-owned; it must not be an FK.
        $row = $this->row(
            'SELECT COUNT(*) AS c
               FROM information_schema.key_column_usage
              WHERE table_schema = ? AND table_name = ? AND column_name = ?
                AND referenced_table_name IS NOT NULL',
            [self::$schema, 'inspections', 'uuid'],
        );

        self::assertSame(0, (int) $row['c']);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param list<scalar> $params
     * @return array<string,mixed>|null Lower-cased keys.
     */
    private function row(string $sql, array $params): ?array
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : array_change_key_case($row, CASE_LOWER);
    }

    /**
     * @param list<scalar> $params
     * @return list<array<string,mixed>> Lower-cased keys.
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $r): array => array_change_key_case($r, CASE_LOWER),
            $stmt->fetchAll(),
        );
    }

    /** @return array<string,mixed>|null */
    private function column(string $table, string $column): ?array
    {
        return $this->row(
            'SELECT data_type, character_maximum_length, column_type, is_nullable, column_default
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [self::$schema, $table, $column],
        );
    }

    /** @param list<string> $columns */
    private function hasUniqueIndexOn(string $table, array $columns): bool
    {
        $rows = $this->rows(
            'SELECT index_name, column_name, seq_in_index, non_unique
               FROM information_schema.statistics
              WHERE table_schema = ? AND table_name = ?
           ORDER BY index_name, seq_in_index',
            [self::$schema, $table],
        );

        $byIndex = [];
        foreach ($rows as $r) {
            if ((int) $r['non_unique'] === 0) {
                $byIndex[$r['index_name']][] = $r['column_name'];
            }
        }

        foreach ($byIndex as $cols) {
            if ($cols === $columns) {
                return true;
            }
        }

        return false;
    }
}
