<?php

declare(strict_types=1);

namespace App\Returns\Builders;

/**
 * NBS monthly return — columns match the client's real "Monthly Statistical
 * Data Report" / "Shipment Data Report" (sample-docs) in the same order.
 * `Int Ref No.` and `Repatriation Date`/`Receipt No. 2` are on the real
 * sheet but aren't captured anywhere in this system yet — left blank rather
 * than guessed (flagged in DEV_NOTES).
 */
final class NbsReturnBuilder extends AbstractReturnBuilder
{
    protected function headers(): array
    {
        return [
            'S/N', 'Int Ref No.', 'CCI No.', 'CCI Date', 'NXP No.', 'NXP Issuing Bank', 'Inspection Date',
            'Exporter Name', 'Exported Products', 'HS Code', 'Shipment Date', 'Country of Destination',
            'Point of Exit', 'Gross Weight (MT)', 'Designated Bank', 'FOB Value (NGN)',
            'NESS Fee (NGN, 0.5% of FOB)', 'Service Fee (NGN, 0.35% of FOB)', 'FOB Value', 'USD', 'EUR', 'GBP',
            'FOB Currency', 'Exchange Rate', 'Repatriation Date', 'Receipt No. 1', 'Receipt No. 2', 'NEPC Number',
        ];
    }

    protected function row(int $serial, array $row): array
    {
        $currency = (string) ($row['currency'] ?? '');
        $fob = $row['declared_value'] !== null ? (float) $row['declared_value'] : null;

        return [
            (string) $serial,
            '', // Int Ref No. — not captured
            $this->str($row['document_number']),
            $this->date($row['issued_at']),
            $this->str($row['form_nxp_number']),
            $this->str($row['exporter_bank_name']),
            $this->date($row['inspection_date']),
            $this->str($row['exporter_name']),
            $this->str($row['product_category']),
            $this->str($row['hs_code']),
            $this->date($row['shipment_date']),
            $this->str($row['destination_country']),
            $this->str($row['point_of_exit']),
            $this->kgToMt($row['gross_weight_kg']),
            $this->str($row['exporter_bank_name']),
            $this->money($this->nairaValue($row)),
            $this->money($this->nessFeeNaira($row)),
            $this->money($this->serviceFeeNaira($row)),
            $fob !== null ? $this->money($fob) : '',
            $currency === 'USD' ? $this->money($fob) : '',
            $currency === 'EUR' ? $this->money($fob) : '',
            $currency === 'GBP' ? $this->money($fob) : '',
            $currency,
            $row['exchange_rate'] !== null ? $this->money($row['exchange_rate'], 4) : '',
            '', // Repatriation date — not captured
            $this->str($row['ness_receipt_no']),
            '', // Receipt No. 2 — not captured
            $this->str($row['nepc_number']),
        ];
    }
}
