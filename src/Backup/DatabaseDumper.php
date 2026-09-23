<?php

declare(strict_types=1);

namespace App\Backup;

use PDO;

/**
 * Pure-PHP logical dump (no mysqldump/exec — the same constraint as D6 for
 * PDFs: shared hosting may not allow shelling out). Produces a plain SQL script
 * that `mysql < database.sql` restores: DROP/CREATE per table (from SHOW CREATE
 * TABLE) followed by batched INSERTs, wrapped so foreign-key order doesn't
 * matter. Rows are streamed (unbuffered) so memory stays flat on big tables.
 *
 * Tables listed in $structureOnly keep their definition but not their rows —
 * ephemeral data that is worthless (and slightly sensitive) in a backup.
 */
final class DatabaseDumper
{
    private const BATCH_ROWS = 200;
    private const BATCH_BYTES = 262144;

    /** @param list<string> $structureOnly */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $structureOnly = ['rate_limits'],
    ) {
    }

    /**
     * @param callable(string):void $write receives consecutive chunks of the script
     * @return array{tables:int, rows:int, table_rows:array<string,int>}
     */
    public function dump(callable $write): array
    {
        // TIMESTAMP columns are stored as UTC instants and *rendered* in the
        // session time zone. Read them in UTC and tell the restoring session
        // to interpret the literals as UTC too — otherwise restoring on a
        // server whose zone isn't UTC shifts every timestamp by its offset.
        $previousZone = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $this->pdo->exec("SET time_zone = '+00:00'");

        try {
            return $this->doDump($write);
        } finally {
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($previousZone));
        }
    }

    /**
     * @param callable(string):void $write
     * @return array{tables:int, rows:int, table_rows:array<string,int>}
     */
    private function doDump(callable $write): array
    {
        $write("-- ADWOL PIA logical backup\n-- Created " . gmdate('Y-m-d H:i:s') . " UTC\n");
        $write("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tableRows = [];
        $total = 0;
        foreach ($this->tables() as $table) {
            $ident = self::ident($table);
            $create = $this->pdo->query("SHOW CREATE TABLE {$ident}")->fetch(PDO::FETCH_NUM);

            $write("-- ----------------------------------------\n-- {$table}\n-- ----------------------------------------\n");
            $write("DROP TABLE IF EXISTS {$ident};\n" . $create[1] . ";\n\n");

            $n = in_array($table, $this->structureOnly, true) ? 0 : $this->dumpRows($table, $write);
            $tableRows[$table] = $n;
            $total += $n;
        }

        $write("SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");

        return ['tables' => count($tableRows), 'rows' => $total, 'table_rows' => $tableRows];
    }

    /** @return list<string> */
    private function tables(): array
    {
        $rows = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $names = array_map(static fn (array $r): string => (string) $r[0], $rows);
        sort($names);

        return $names;
    }

    /** @param callable(string):void $write */
    private function dumpRows(string $table, callable $write): int
    {
        $ident = self::ident($table);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $stmt = $this->pdo->query("SELECT * FROM {$ident}");
            $columns = null;
            $batch = [];
            $bytes = 0;
            $count = 0;

            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($columns === null) {
                    $columns = implode(', ', array_map([self::class, 'ident'], array_keys($row)));
                }
                $values = '(' . implode(', ', array_map([$this, 'literal'], $row)) . ')';
                $batch[] = $values;
                $bytes += strlen($values);
                $count++;

                if (count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                    $write("INSERT INTO {$ident} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $bytes = 0;
                }
            }
            $stmt->closeCursor();

            if ($batch !== []) {
                $write("INSERT INTO {$ident} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $write("\n");

            return $count;
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    private function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.') ?: '0';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return $this->pdo->quote((string) $v);
    }

    private static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
