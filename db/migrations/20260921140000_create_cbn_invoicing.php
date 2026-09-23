<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * Monthly service-fee invoice from the PIA to the Central Bank of Nigeria
 * (sample-docs "PIA Invoice June 2023"): one invoice per month, the CCIs issued
 * that month grouped by region, fee = 0.35% of FOB in naira.
 *
 *  - cbn_invoices       the issued invoices. `lines` is a snapshot of the grouped
 *                       figures at issue time, so the invoice never changes if a
 *                       record is edited later; the PDF is signed like a CCI.
 *  - invoice_sequences  its own counter — sharing document_sequences would eat
 *                       CCI numbers. Keyed by year with no surrogate id (see below).
 *  - invoice_settings   single row: invoice-number prefix, the CBN addressee,
 *                       the service description and the bank to pay into.
 */
final class CreateCbnInvoicing extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $inv = $this->table('cbn_invoices', $this->tableOptions('Monthly PIA service-fee invoices to the CBN'));
        $this->addId($inv);
        $this->addUuid($inv);
        $this->addForeignKeyColumn($inv, 'issued_by', 'users', nullable: true);
        $inv
            ->addColumn('invoice_number', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('period_month', 'date', ['null' => false, 'comment' => 'first day of the invoiced month'])
            ->addColumn('status', 'enum', ['values' => ['issued', 'paid', 'void'], 'null' => false, 'default' => 'issued'])
            ->addColumn('cci_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('fob_ngn', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false])
            ->addColumn('fee_rate', 'decimal', ['precision' => 7, 'scale' => 6, 'null' => false])
            ->addColumn('fee_ngn', 'decimal', ['precision' => 18, 'scale' => 2, 'null' => false])
            ->addColumn('lines', 'json', ['null' => false, 'comment' => 'grouped figures at issue time'])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('content_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('hmac_signature', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('issued_at', 'datetime', ['null' => false])
            ->addColumn('paid_at', 'date', ['null' => true])
            ->addColumn('payment_reference', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('voided_at', 'datetime', ['null' => true])
            ->addColumn('void_reason', 'string', ['limit' => 255, 'null' => true]);
        $this->addTimestamps($inv);
        $inv
            ->addIndex(['invoice_number'], ['unique' => true, 'name' => 'cbn_invoices_number_uq'])
            ->addIndex(['period_month'], ['name' => 'cbn_invoices_period_idx'])
            ->addIndex(['status'], ['name' => 'cbn_invoices_status_idx'])
            ->create();

        // `year` is the primary key and there is deliberately NO auto-increment
        // id: the LAST_INSERT_ID(expr) allocation idiom returns the surrogate id
        // instead of the counter on a year's first insert if one exists (the
        // same trap document_sequences fell into — see its rebuild migration).
        $this->table('invoice_sequences', [
            'engine'      => 'InnoDB',
            'encoding'    => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'id'          => false,
            'primary_key' => ['year'],
            'comment'     => 'Yearly counter for CBN invoice numbers',
        ])
            ->addColumn('year', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('next_number', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addTimestamps('created_at', 'updated_at')
            ->create();

        $set = $this->table('invoice_settings', $this->tableOptions('CBN invoice letter details (single row)'));
        $this->addId($set);
        $this->addForeignKeyColumn($set, 'updated_by', 'users', nullable: true);
        $set
            ->addColumn('number_prefix', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('addressee', 'text', ['null' => false])
            ->addColumn('service_description', 'text', ['null' => false])
            ->addColumn('bank_account_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('bank_account_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('bank_name', 'string', ['limit' => 150, 'null' => true]);
        $this->addTimestamps($set);
        $set->create();
    }
}
