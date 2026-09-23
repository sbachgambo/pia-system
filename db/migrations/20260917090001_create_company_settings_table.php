<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * company_settings — Phase 6 follow-up, NOT in brief §4.
 *
 * The letterhead printed on generated documents (name / address /
 * representative title) started as env-only config (`PIA_COMPANY_NAME` etc,
 * A29). The client asked for it to be editable from the console instead of
 * requiring a redeploy — this single-row table backs that. Row `id = 1`
 * always; `App\Settings\CompanySettingsRepository` falls back to the env
 * defaults in `config/settings.php` until an admin actually saves a change,
 * so no seed migration/row is required.
 */
final class CreateCompanySettingsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('company_settings', $this->tableOptions('Editable document letterhead (Phase 6 follow-up)'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'updated_by', 'users', nullable: true);

        $table
            ->addColumn('name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('address', 'text', ['null' => true])
            ->addColumn('representative_title', 'string', ['limit' => 100, 'null' => false]);

        $this->addTimestamps($table);

        $table->create();
    }
}
