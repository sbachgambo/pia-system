<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * consignment_trade_details — Phase 6 addition, NOT in brief §4.
 *
 * The real CCI (Clean Certificate of Inspection) layout supplied by the
 * client (sample-docs/2021-00002.pdf) carries several trade-finance fields
 * §4's `consignments` has no room for: the importer, both parties' banks, the
 * commercial invoice, and the basis of sale. These are known at intake time
 * (the office enters them alongside the consignment), so they live in a 1:1
 * side table rather than widening `consignments` itself — additive, and a
 * consignment created before Phase 6 simply has no row here until edited.
 * Every column is nullable: not every consignment will ever become a CCI.
 */
final class CreateConsignmentTradeDetailsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('consignment_trade_details', $this->tableOptions('CCI trade/banking fields (Phase 6)'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'consignment_id', 'consignments', onDelete: 'CASCADE');

        $table
            ->addColumn('importer_name', 'string', ['limit' => 200, 'null' => true, 'comment' => 'CCI field 8'])
            ->addColumn('importer_address', 'text', ['null' => true, 'comment' => 'CCI field 8'])
            ->addColumn('exporter_bank_name', 'string', ['limit' => 200, 'null' => true, 'comment' => 'CCI field 10'])
            ->addColumn('importer_bank_name', 'string', ['limit' => 200, 'null' => true, 'comment' => 'CCI field 11'])
            ->addColumn('bank_reference', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI fields 12/13'])
            ->addColumn('invoice_number', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI field 18'])
            ->addColumn('invoice_date', 'date', ['null' => true, 'comment' => 'CCI field 19'])
            ->addColumn('basis_of_sale', 'enum', [
                'values' => ['FOB', 'CFR', 'CIF'],
                'null'   => true,
                'comment' => 'CCI fields 20/43',
            ])
            ->addColumn('method_of_payment', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI field 21'])
            ->addColumn('freight_charges', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => true, 'comment' => 'CCI fields 24/41'])
            ->addColumn('insurance_charges', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => true, 'comment' => 'CCI fields 25/42']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['consignment_id'], ['unique' => true, 'name' => 'consignment_trade_details_consignment_uq'])
            ->create();
    }
}
