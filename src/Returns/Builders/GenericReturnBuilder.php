<?php

declare(strict_types=1);

namespace App\Returns\Builders;

/**
 * Placeholder register for NEPC / MOF / CUSTOMS — no sample layout exists
 * for any of these three yet (Q6, partially answered — see DEV_NOTES). A
 * reasonable core register: every field the CCI itself carries, no
 * agency-specific derived columns invented. Swap in a real per-agency
 * builder (same pattern as Cbn/NbsReturnBuilder) once a layout is confirmed.
 */
final class GenericReturnBuilder extends AbstractReturnBuilder
{
    protected function headers(): array
    {
        return [
            'S/N', 'Document No.', 'Document Type', 'Issue Date', 'NXP No.', 'Exporter Name', 'RC No.',
            'Product', 'HS Code', 'Quantity', 'Unit', 'Origin Country', 'Destination Country', 'Zone',
            'FOB Value', 'Currency', 'Exchange Rate', 'FOB Value (NGN)',
        ];
    }

    protected function row(int $serial, array $row): array
    {
        return [
            (string) $serial,
            $this->str($row['document_number']),
            $this->str($row['document_type']),
            $this->date($row['issued_at']),
            $this->str($row['form_nxp_number']),
            $this->str($row['exporter_name']),
            $this->str($row['rc_number']),
            $this->str($row['product_category']),
            $this->str($row['hs_code']),
            $row['quantity'] !== null ? $this->money($row['quantity']) : '',
            $this->str($row['unit_of_measure']),
            $this->str($row['origin_country']),
            $this->str($row['destination_country']),
            $this->str($row['zone']),
            $row['declared_value'] !== null ? $this->money($row['declared_value']) : '',
            $this->str($row['currency']),
            $row['exchange_rate'] !== null ? $this->money($row['exchange_rate'], 4) : '',
            $this->money($this->nairaValue($row)),
        ];
    }
}
