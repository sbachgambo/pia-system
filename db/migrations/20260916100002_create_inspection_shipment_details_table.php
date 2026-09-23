<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * inspection_shipment_details — Phase 6 addition, NOT in brief §4.
 *
 * The rest of the real CCI layout (sample-docs/2021-00002.pdf): shipping
 * particulars, the actual weighed/inspected figures, and the NESS fee
 * paper trail. Unlike consignment_trade_details, these are only known once
 * the inspection has happened — the office fills them in during review
 * (alongside amend/finalize, Phase 5), so this is keyed to `inspections`,
 * not `consignments`. All nullable — a CCI can be issued with some fields
 * blank, exactly as the real sample does (several figures are blank on it).
 */
final class CreateInspectionShipmentDetailsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('inspection_shipment_details', $this->tableOptions('CCI shipping/NESS fields (Phase 6)'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'inspection_id', 'inspections', onDelete: 'CASCADE');

        $table
            ->addColumn('shipment_date', 'date', ['null' => true, 'comment' => 'CCI field 27'])
            ->addColumn('shipping_agent', 'string', ['limit' => 200, 'null' => true, 'comment' => 'CCI field 27B'])
            ->addColumn('carrier_vessel', 'string', ['limit' => 200, 'null' => true, 'comment' => 'CCI field 28'])
            ->addColumn('loading_ref_no', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI field 28B'])
            ->addColumn('container_numbers', 'string', ['limit' => 255, 'null' => true, 'comment' => 'CCI field 31'])
            ->addColumn('packing_details', 'string', ['limit' => 255, 'null' => true, 'comment' => 'CCI field 37'])
            ->addColumn('quality_remark', 'string', ['limit' => 255, 'null' => true, 'comment' => 'CCI field 38'])
            ->addColumn('gross_weight_kg', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => true, 'comment' => 'CCI field 37B'])
            ->addColumn('net_weight_kg', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => true, 'comment' => 'CCI field 38B'])
            ->addColumn('forex_exchange_date', 'date', ['null' => true, 'comment' => 'CCI field 45'])
            ->addColumn('exchange_rate', 'decimal', ['precision' => 12, 'scale' => 4, 'null' => true, 'comment' => 'CCI field 47'])
            ->addColumn('ness_charges_paid', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => true, 'comment' => 'CCI field 48'])
            ->addColumn('ness_receipt_no', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI field 49'])
            ->addColumn('ness_actual_payable', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => true, 'comment' => 'CCI field 50'])
            ->addColumn('ness_balance_paid', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => true, 'comment' => 'CCI field 51'])
            ->addColumn('ness_balance_receipt_no', 'string', ['limit' => 100, 'null' => true, 'comment' => 'CCI field 52']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['inspection_id'], ['unique' => true, 'name' => 'inspection_shipment_details_inspection_uq'])
            ->create();
    }
}
