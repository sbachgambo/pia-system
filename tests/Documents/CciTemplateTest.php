<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\Fees;
use App\Http\View\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * What the CCI prints, checked on the rendered HTML (before mPDF):
 *  - the header carries the CLIENT's name, not the agency's logo or name;
 *  - the CCI and NXP numbers are in the header;
 *  - box 50 (NESS payable) is always filled — recorded figure first,
 *    otherwise Fees::NESS_RATE of FOB.
 */
final class CciTemplateTest extends TestCase
{
    /** @param array<string,mixed> $shipment */
    private function render(array $shipment = []): string
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $renderer = new Renderer($settings['app']['base_path'] . '/templates', []);

        $empty = array_fill_keys([
            'shipment_date', 'shipping_agent', 'carrier_vessel', 'loading_ref_no', 'container_numbers', 'packing_details',
            'quality_remark', 'gross_weight_kg', 'net_weight_kg', 'forex_exchange_date', 'exchange_rate', 'ness_charges_paid',
            'ness_receipt_no', 'ness_actual_payable', 'ness_balance_paid', 'ness_balance_receipt_no',
        ], null);

        return $renderer->renderRaw('documents/cci', [
            'document'    => ['type' => 'CCI', 'document_number' => '2026-00042', 'issued_at' => '2026-09-21', 'copy' => 'Original'],
            'company'     => ['name' => 'ADWOL Agency Name', 'address' => 'Agency Street', 'representative_title' => 'MD'],
            'inspection'  => ['uuid' => 'x', 'scheduled_at' => '2026-09-20', 'location_type' => 'port', 'location_detail' => 'Onne', 'finalized_at' => null],
            'client'      => ['name' => 'Tiger Foods Limited', 'address' => 'KM4 Onitsha Road', 'rc_number' => 'RC1'],
            'consignment' => [
                'direction' => 'export', 'product_category' => 'Spices', 'product_description' => 'x', 'hs_code' => null,
                'quantity' => 10.0, 'unit_of_measure' => 'MT', 'unit_price' => 1000.0, 'declared_value' => 10000.0,
                'currency' => 'USD', 'origin_country' => 'Nigeria', 'destination_country' => 'Ghana',
                'form_nxp_number' => 'XG2026000999', 'total_value_words' => 'Ten Thousand dollars only',
            ],
            'trade'       => array_fill_keys(['importer_name', 'importer_address', 'nepc_number', 'exporter_bank_name', 'importer_bank_name',
                'bank_reference', 'invoice_number', 'invoice_date', 'basis_of_sale', 'method_of_payment', 'freight_charges', 'insurance_charges'], null),
            'shipment'    => $shipment + $empty,
        ]);
    }

    private function header(string $html): string
    {
        $start = strpos($html, '<table class="head-table">');
        self::assertNotFalse($start);

        return substr($html, $start, (int) strpos($html, '</table>', $start) - $start);
    }

    public function testTheHeaderCarriesTheClientsNameNotTheAgencys(): void
    {
        $header = $this->header($this->render());

        self::assertStringContainsString('Tiger Foods Limited', $header);
        self::assertStringNotContainsString('ADWOL Agency Name', $header);
        self::assertStringNotContainsString('<img', $header, 'no agency logo on the certificate');
    }

    public function testTheHeaderShowsTheCciAndNxpNumbers(): void
    {
        $header = $this->header($this->render());

        self::assertStringContainsString('CCI No. 2026-00042', $header);
        self::assertStringContainsString('XG2026000999', $header);
    }

    public function testTheAgencyStillSignsAtTheFoot(): void
    {
        self::assertStringContainsString('For: ADWOL Agency Name', $this->render());
    }

    public function testNessPayableIsComputedWhenNotRecorded(): void
    {
        $computed = ['amount' => Fees::ness(10000.0, 1500.0), 'currency' => 'NGN', 'rate' => Fees::percent(Fees::NESS_RATE)];
        $html = $this->render(['ness_computed' => $computed]);

        self::assertSame(75000.0, $computed['amount'], '0.5% of USD 10,000 at 1,500');
        self::assertStringContainsString('NGN 75,000.00', $html);
        self::assertStringContainsString('(0.5% of FOB)', $html);
    }

    public function testARecordedNessFigureWinsOverTheComputedOne(): void
    {
        $html = $this->render([
            'ness_actual_payable' => '20398.74',
            'ness_computed'       => ['amount' => 75000.0, 'currency' => 'NGN', 'rate' => '0.5%'],
        ]);

        self::assertStringContainsString('20,398.74', $html);
        self::assertStringNotContainsString('NGN 75,000.00', $html);
    }
}
