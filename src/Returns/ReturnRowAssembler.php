<?php

declare(strict_types=1);

namespace App\Returns;

use DateTimeImmutable;
use PDO;

/**
 * Pulls one flat row per document (CCI/NNCI) issued in a period — the same
 * "register" shape every regulator report in `sample-docs/` is built from
 * (the CBN "Compliant Report", the NBS "Shipment Data Report", the annual
 * template). Each `App\Returns\Builders\*` picks the subset/order/labels it
 * needs; this is the one place the join lives.
 *
 * Only `issued` documents count — a `void`ed one shouldn't appear in a
 * regulator submission.
 */
final class ReturnRowAssembler
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function rowsForPeriod(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                d.document_number, d.type AS document_type, d.issued_at,
                i.scheduled_at AS inspection_date, i.location_detail AS point_of_exit,
                cl.name AS exporter_name, cl.rc_number,
                co.direction, co.product_category, co.product_description, co.hs_code,
                co.quantity, co.unit_of_measure, co.declared_value, co.currency,
                co.origin_country, co.destination_country, co.zone, co.form_nxp_number,
                td.nepc_number, td.exporter_bank_name, td.invoice_number, td.invoice_date,
                sd.shipment_date, sd.gross_weight_kg, sd.net_weight_kg, sd.exchange_rate,
                sd.ness_receipt_no, sd.ness_actual_payable, sd.forex_exchange_date
             FROM documents d
             JOIN inspections i ON i.id = d.inspection_id
             JOIN inspection_requests ir ON ir.id = i.inspection_request_id
             JOIN consignments co ON co.id = ir.consignment_id
             JOIN clients cl ON cl.id = co.client_id
             LEFT JOIN consignment_trade_details td ON td.consignment_id = co.id
             LEFT JOIN inspection_shipment_details sd ON sd.inspection_id = i.id
             WHERE d.status = 'issued' AND d.issued_at >= :start AND d.issued_at < :end
             ORDER BY d.issued_at ASC, d.document_number ASC"
        );
        $stmt->execute(['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]);

        return $stmt->fetchAll();
    }
}
