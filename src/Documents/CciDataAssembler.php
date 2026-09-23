<?php

declare(strict_types=1);

namespace App\Documents;

use App\Inspections\InspectionRepository;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use RuntimeException;

/**
 * Gathers everything the CCI/NNCI template needs into one flat structure,
 * matching the real sample form field-by-field (sample-docs/2021-00002.pdf).
 * Every "CCI field N" reference in the doc-blocks of the two Phase 6 detail
 * tables is realised here.
 */
final class CciDataAssembler
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly ConsignmentRepository $consignments,
        private readonly ClientRepository $clients,
        private readonly TradeDetailsRepository $trade,
        private readonly ShipmentDetailsRepository $shipment,
    ) {
    }

    /** @return array<string,mixed> */
    public function assemble(string $inspectionUuid): array
    {
        $inspection = $this->inspections->findByUuid($inspectionUuid)
            ?? throw new RuntimeException("Inspection {$inspectionUuid} not found");

        $consignment = $this->consignments->findByUuid((string) $inspection['consignment_uuid'])
            ?? throw new RuntimeException('Consignment for inspection not found');
        $consignmentId = (int) $this->consignments->findIdByUuid((string) $consignment['uuid']);

        $client = $this->clients->findByUuid((string) $consignment['client_uuid'])
            ?? throw new RuntimeException('Client for consignment not found');

        $tradeRow = $this->trade->forConsignment($consignmentId) ?? [];
        $shipRow  = $this->shipment->forInspection((int) $inspection['id']) ?? [];

        $quantity = (float) $consignment['quantity'];
        $declaredValue = (float) $consignment['declared_value'];
        $unitPrice = $quantity > 0.0 ? $declaredValue / $quantity : 0.0;

        // NESS payable is always printed: the office's recorded figure when there
        // is one, otherwise Fees::NESS_RATE of FOB — in naira when the exchange
        // rate is known, else in the FOB currency.
        $exchangeRate = isset($shipRow['exchange_rate']) && $shipRow['exchange_rate'] !== null ? (float) $shipRow['exchange_rate'] : null;
        $nessComputed = [
            'amount'   => Fees::ness($declaredValue, $exchangeRate),
            'currency' => $exchangeRate !== null ? 'NGN' : (string) $consignment['currency'],
            'rate'     => Fees::percent(Fees::NESS_RATE),
        ];

        return [
            'inspection' => [
                'uuid'             => (string) $inspection['uuid'],
                'scheduled_at'     => $inspection['scheduled_at'],
                'location_type'    => (string) $inspection['location_type'],
                'location_detail'  => (string) $inspection['location_detail'],
                'finalized_at'     => $inspection['finalized_at'],
            ],
            'client' => [
                'name'       => (string) $client['name'],
                'address'    => (string) $client['address'],
                'rc_number'  => $client['rc_number'] !== null ? (string) $client['rc_number'] : null,
            ],
            'consignment' => [
                'direction'            => (string) $consignment['direction'],
                'product_category'     => (string) $consignment['product_category'],
                'product_description'  => (string) $consignment['product_description'],
                'hs_code'              => $consignment['hs_code'] !== null ? (string) $consignment['hs_code'] : null,
                'quantity'             => $quantity,
                'unit_of_measure'      => (string) $consignment['unit_of_measure'],
                'unit_price'           => $unitPrice,
                'declared_value'       => $declaredValue,
                'currency'             => (string) $consignment['currency'],
                'origin_country'       => (string) $consignment['origin_country'],
                'destination_country'  => (string) $consignment['destination_country'],
                'form_nxp_number'      => $consignment['form_nxp_number'] !== null ? (string) $consignment['form_nxp_number'] : null,
                'total_value_words'    => NumberToWords::amount($declaredValue, self::currencyWord((string) $consignment['currency'])),
            ],
            'trade' => [
                'importer_name'       => $tradeRow['importer_name'] ?? null,
                'importer_address'    => $tradeRow['importer_address'] ?? null,
                'nepc_number'         => $tradeRow['nepc_number'] ?? null,
                'exporter_bank_name'  => $tradeRow['exporter_bank_name'] ?? null,
                'importer_bank_name'  => $tradeRow['importer_bank_name'] ?? null,
                'bank_reference'      => $tradeRow['bank_reference'] ?? null,
                'invoice_number'      => $tradeRow['invoice_number'] ?? null,
                'invoice_date'        => $tradeRow['invoice_date'] ?? null,
                'basis_of_sale'       => $tradeRow['basis_of_sale'] ?? null,
                'method_of_payment'   => $tradeRow['method_of_payment'] ?? null,
                'freight_charges'     => $tradeRow['freight_charges'] ?? null,
                'insurance_charges'   => $tradeRow['insurance_charges'] ?? null,
            ],
            'shipment' => [
                'shipment_date'            => $shipRow['shipment_date'] ?? null,
                'shipping_agent'           => $shipRow['shipping_agent'] ?? null,
                'carrier_vessel'           => $shipRow['carrier_vessel'] ?? null,
                'loading_ref_no'           => $shipRow['loading_ref_no'] ?? null,
                'container_numbers'        => $shipRow['container_numbers'] ?? null,
                'packing_details'          => $shipRow['packing_details'] ?? null,
                'quality_remark'           => $shipRow['quality_remark'] ?? null,
                'gross_weight_kg'          => $shipRow['gross_weight_kg'] ?? null,
                'net_weight_kg'            => $shipRow['net_weight_kg'] ?? null,
                'forex_exchange_date'      => $shipRow['forex_exchange_date'] ?? null,
                'exchange_rate'            => $shipRow['exchange_rate'] ?? null,
                'ness_charges_paid'        => $shipRow['ness_charges_paid'] ?? null,
                'ness_receipt_no'          => $shipRow['ness_receipt_no'] ?? null,
                'ness_actual_payable'      => $shipRow['ness_actual_payable'] ?? null,
                'ness_balance_paid'        => $shipRow['ness_balance_paid'] ?? null,
                'ness_balance_receipt_no'  => $shipRow['ness_balance_receipt_no'] ?? null,
                'ness_computed'            => $nessComputed,
            ],
        ];
    }

    private static function currencyWord(string $currency): string
    {
        return match ($currency) {
            'USD' => 'dollars',
            'EUR' => 'euros',
            'GBP' => 'pounds',
            'NGN' => 'naira',
            default => strtolower($currency),
        };
    }
}
