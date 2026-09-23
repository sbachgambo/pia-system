<?php

declare(strict_types=1);

namespace App\Invoicing;

use PDO;

/**
 * The fixed parts of the CBN invoice letter — number prefix, addressee, the
 * service description and the bank to pay into. One row; until an admin saves
 * it, the defaults below (taken from the client's sample invoice) apply.
 */
final class InvoiceSettingsRepository
{
    public const DEFAULTS = [
        'number_prefix'       => 'ADW',
        'addressee'           => "TRADE & EXCHANGE DEPARTMENT\nCENTRAL BANK OF NIGERIA\nCENTRAL BUSINESS DISTRICT, ABUJA, FCT",
        'service_description' => 'Quality, Quantity & Price Competitiveness Inspection on behalf of Federal Government of Nigeria in respect of Non-Oil Exports in Compliance with General Export Regulations.',
        'bank_account_name'   => null,
        'bank_account_number' => null,
        'bank_name'           => null,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{number_prefix:string,addressee:string,service_description:string,bank_account_name:?string,bank_account_number:?string,bank_name:?string} */
    public function get(): array
    {
        $row = $this->pdo->query(
            'SELECT number_prefix, addressee, service_description, bank_account_name, bank_account_number, bank_name
               FROM invoice_settings WHERE id = 1'
        )->fetch();

        return $row === false ? self::DEFAULTS : [
            'number_prefix'       => (string) $row['number_prefix'],
            'addressee'           => (string) $row['addressee'],
            'service_description' => (string) $row['service_description'],
            'bank_account_name'   => $row['bank_account_name'] !== null ? (string) $row['bank_account_name'] : null,
            'bank_account_number' => $row['bank_account_number'] !== null ? (string) $row['bank_account_number'] : null,
            'bank_name'           => $row['bank_name'] !== null ? (string) $row['bank_name'] : null,
        ];
    }

    /** Whether the bank details an invoice needs have been filled in. */
    public function isComplete(): bool
    {
        $s = $this->get();

        return ($s['bank_account_name'] ?? '') !== '' && ($s['bank_account_number'] ?? '') !== '' && ($s['bank_name'] ?? '') !== '';
    }

    /** @param array{number_prefix:string,addressee:string,service_description:string,bank_account_name:?string,bank_account_number:?string,bank_name:?string} $data */
    public function update(array $data, ?int $updatedBy): void
    {
        $this->pdo->prepare(
            'INSERT INTO invoice_settings
                (id, number_prefix, addressee, service_description, bank_account_name, bank_account_number, bank_name, updated_by, created_at, updated_at)
             VALUES (1, :p, :a, :d, :an, :ano, :bn, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                number_prefix = VALUES(number_prefix), addressee = VALUES(addressee),
                service_description = VALUES(service_description), bank_account_name = VALUES(bank_account_name),
                bank_account_number = VALUES(bank_account_number), bank_name = VALUES(bank_name),
                updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
        )->execute([
            'p'   => $data['number_prefix'],
            'a'   => $data['addressee'],
            'd'   => $data['service_description'],
            'an'  => $data['bank_account_name'],
            'ano' => $data['bank_account_number'],
            'bn'  => $data['bank_name'],
            'by'  => $updatedBy,
        ]);
    }
}
