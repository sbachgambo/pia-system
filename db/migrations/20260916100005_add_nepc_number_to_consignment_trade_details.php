<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds the NEPC registration number (CCI field 2) to consignment_trade_details.
 * Missed in the initial Phase 6 migration; added as its own migration rather
 * than editing an already-applied one.
 */
final class AddNepcNumberToConsignmentTradeDetails extends AbstractMigration
{
    public function change(): void
    {
        $this->table('consignment_trade_details')
            ->addColumn('nepc_number', 'string', [
                'limit'   => 50,
                'null'    => true,
                'after'   => 'importer_address',
                'comment' => 'CCI field 2 — exporter NEPC registration number',
            ])
            ->update();
    }
}
