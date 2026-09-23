<?php

declare(strict_types=1);

namespace App\Database;

use Phinx\Db\Table;

/**
 * Shared helpers so every table in the schema is built the same way:
 * InnoDB + utf8mb4, BIGINT UNSIGNED auto-increment PK, a unique CHAR(36) uuid
 * where the brief calls for one, unsigned FK columns with real foreign keys,
 * and a created_at / updated_at pair (brief §4).
 *
 * Used by the Phinx migration classes (which cannot be namespaced, so this
 * lives under App\ and is pulled in as a trait).
 */
trait MigrationSupport
{
    /**
     * Table options that match brief §4: InnoDB, utf8mb4, and an explicitly
     * defined primary key (we add the `id` column ourselves so it is
     * BIGINT UNSIGNED rather than Phinx's default INT).
     *
     * @return array<string,mixed>
     */
    protected function tableOptions(string $comment = ''): array
    {
        $options = [
            'engine'      => 'InnoDB',
            'encoding'    => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'id'          => false,
            'primary_key' => ['id'],
        ];

        if ($comment !== '') {
            $options['comment'] = $comment;
        }

        return $options;
    }

    /** BIGINT UNSIGNED PK AUTO_INCREMENT. */
    protected function addId(Table $table): Table
    {
        return $table->addColumn('id', 'biginteger', [
            'identity' => true,
            'signed'   => false,
        ]);
    }

    /** CHAR(36) uuid, unique by default. */
    protected function addUuid(Table $table, bool $unique = true): Table
    {
        $table->addColumn('uuid', 'char', ['limit' => 36, 'null' => false]);

        if ($unique) {
            $table->addIndex(['uuid'], ['unique' => true, 'name' => $table->getName() . '_uuid_uq']);
        }

        return $table;
    }

    /**
     * Unsigned BIGINT foreign-key column + the FK constraint itself.
     * Every FK column is indexed (Phinx indexes FK columns automatically),
     * per the §4 indexing note.
     */
    protected function addForeignKeyColumn(
        Table $table,
        string $column,
        string $referencedTable,
        bool $nullable = false,
        string $onDelete = 'RESTRICT',
        string $referencedColumn = 'id',
    ): Table {
        $table->addColumn($column, 'biginteger', [
            'signed' => false,
            'null'   => $nullable,
        ]);

        $table->addForeignKey($column, $referencedTable, $referencedColumn, [
            'delete' => $onDelete,
            'update' => 'NO_ACTION',
            'constraint' => $table->getName() . '_' . $column . '_fk',
        ]);

        return $table;
    }

    /**
     * created_at / updated_at. Phinx's addTimestamps gives:
     *   created_at  TIMESTAMP  NOT NULL  DEFAULT CURRENT_TIMESTAMP
     *   updated_at  TIMESTAMP  NULL      ON UPDATE CURRENT_TIMESTAMP
     * which matches brief §4 (the "ON UPDATE CURRENT_TIMESTAMP pair").
     */
    protected function addTimestamps(Table $table): Table
    {
        return $table->addTimestamps('created_at', 'updated_at');
    }
}
