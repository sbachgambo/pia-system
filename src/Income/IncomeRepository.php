<?php

declare(strict_types=1);

namespace App\Income;

use DateTimeImmutable;
use PDO;

/**
 * Read-only queries behind the income report. Income is earned per ISSUED CCI
 * (the same certificates the monthly CBN invoice bills), so this reads the
 * `documents` register joined to the NXP record's FOB and the inspection's
 * exchange rate — nothing is stored separately that could drift.
 */
final class IncomeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Issued CCIs in [$from, $to).
     *
     * @return list<array<string,mixed>>
     */
    public function ccis(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.uuid AS document_uuid, d.document_number, d.issued_at, co.uuid AS nxp_uuid,
                    co.form_nxp_number AS nxp_number, co.zone, co.currency, co.declared_value,
                    cl.uuid AS client_uuid, cl.name AS client_name, i.uuid AS inspection_uuid, sd.exchange_rate
               FROM documents d
               JOIN inspections i ON i.id = d.inspection_id
               JOIN inspection_requests ir ON ir.id = i.inspection_request_id
               JOIN consignments co ON co.id = ir.consignment_id
               JOIN clients cl ON cl.id = co.client_id
               LEFT JOIN inspection_shipment_details sd ON sd.inspection_id = i.id
              WHERE d.type = 'CCI' AND d.status = 'issued' AND d.issued_at >= :s AND d.issued_at < :e
           ORDER BY d.issued_at, d.document_number"
        );
        $stmt->execute(['s' => $from->format('Y-m-d H:i:s'), 'e' => $to->format('Y-m-d H:i:s')]);

        return $stmt->fetchAll();
    }

    /**
     * Live (not void) CBN invoices whose month falls in [$from, $to).
     *
     * @return list<array{invoice_number:string,period_month:string,status:string,fee_ngn:string,paid_at:?string}>
     */
    public function invoices(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT invoice_number, period_month, status, fee_ngn, paid_at FROM cbn_invoices
              WHERE status <> 'void' AND period_month >= :s AND period_month < :e
           ORDER BY period_month"
        );
        $stmt->execute(['s' => $from->format('Y-m-01'), 'e' => $to->format('Y-m-d')]);

        return $stmt->fetchAll();
    }
}
