<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * operational_settings — single-row table (id = 1), same pattern as
 * company_settings (20260917090001): env vars in config/settings.php remain
 * the fresh-install default; App\Settings\OperationalSettingsRepository falls
 * back to them until an admin saves a change at /console/settings, so no seed
 * row is required.
 *
 * Nullable columns mean "use the env default" — distinct from a column that
 * has been explicitly set to a value, without needing a separate "is this
 * overridden" flag per field.
 */
final class CreateOperationalSettingsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('operational_settings', $this->tableOptions('Editable operational/workflow config'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'updated_by', 'users', nullable: true);

        $table->addColumn('compliance_grace_hours', 'integer', ['null' => true, 'signed' => false]);

        $this->addTimestamps($table);

        $table->create();
    }
}
