<?php

declare(strict_types=1);

namespace App\Returns\Builders;

/**
 * CBN monthly return — columns match the client's real "Compliant Report"
 * appendix (sample-docs, the October 2021 report + CBN Report - June
 * Analsysis.xlsx) exactly, in the same order. Two columns are best-available
 * proxies rather than exact matches (documented at the field): "NXP Issuing
 * Bank" and "Designated Bank" are distinct concepts on the real form but this
 * system only captures one bank per consignment (`exporter_bank_name`).
 */
final class CbnReturnBuilder extends AbstractReturnBuilder
{
    protected function headers(): array
    {
        return [
            'S/N', 'CCI No.', 'CCI Date', 'NXP No.', 'NXP Issuing Bank', 'Inspection Date',
            'Exporter Name', 'Exported Products', 'HS Code', 'Shipment Date', 'Destination',
            'Point of Exit', 'Gross Weight (MT)', 'Net Weight (MT)', 'Unit Price', 'Designated Bank',
            'Receipt No.', 'FOB Currency', 'FOB Value (US$)', 'FOB Value (EUR)', 'FOB Value (GBP)',
            'Exchange Rate', 'FOB Value (NGN)', 'NESS Fee Payable (NGN)',
        ];
    }

    protected function row(int $serial, array $row): array
    {
        $currency = (string) ($row['currency'] ?? '');
        $fob = $row['declared_value'] !== null ? (float) $row['declared_value'] : null;
        $qty = $row['quantity'] !== null ? (float) $row['quantity'] : null;
        $unitPrice = ($fob !== null && $qty !== null && $qty > 0) ? $fob / $qty : null;

        return [
            (string) $serial,
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
            $this->kgToMt($row['net_weight_kg']),
            $unitPrice !== null ? $this->money($unitPrice) : '',
            $this->str($row['exporter_bank_name']),
            $this->str($row['ness_receipt_no']),
            $currency,
            $currency === 'USD' ? $this->money($fob) : '',
            $currency === 'EUR' ? $this->money($fob) : '',
            $currency === 'GBP' ? $this->money($fob) : '',
            $row['exchange_rate'] !== null ? $this->money($row['exchange_rate'], 4) : '',
            $this->money($this->nairaValue($row)),
            $this->money($this->nessFeeNaira($row)),
        ];
    }
}
