<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The NXP number is the key that identifies a transaction (client decision,
 * Sep 2026), so no two records may share one. Exports must carry it (enforced
 * in ConsignmentService); imports never do, so the column stays NULLable —
 * MySQL lets any number of NULLs coexist under a unique index.
 *
 * Blank strings are folded into NULL first so they can't collide with each
 * other. The collation is case-insensitive, so "nxp-1" and "NXP-1" count as
 * the same number.
 */
final class UniqueNxpNumber extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE consignments SET form_nxp_number = NULL WHERE TRIM(form_nxp_number) = ''");
        $this->execute('UPDATE consignments SET form_nxp_number = TRIM(form_nxp_number) WHERE form_nxp_number IS NOT NULL');

        $this->table('consignments')
            ->addIndex(['form_nxp_number'], ['unique' => true, 'name' => 'consignments_nxp_number_uq'])
            ->update();
    }

    public function down(): void
    {
        $this->table('consignments')->removeIndexByName('consignments_nxp_number_uq')->update();
    }
}
