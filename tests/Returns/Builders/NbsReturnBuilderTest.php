<?php

declare(strict_types=1);

namespace App\Tests\Returns\Builders;

use App\Returns\Builders\NbsReturnBuilder;
use PHPUnit\Framework\TestCase;

final class NbsReturnBuilderTest extends TestCase
{
    public function testHeaderMatchesTheRealReportColumns(): void
    {
        $csv = (new NbsReturnBuilder())->build([]);
        $header = str_getcsv(explode("\n", $csv)[0]);

        self::assertSame([
            'S/N', 'Int Ref No.', 'CCI No.', 'CCI Date', 'NXP No.', 'NXP Issuing Bank', 'Inspection Date',
            'Exporter Name', 'Exported Products', 'HS Code', 'Shipment Date', 'Country of Destination',
            'Point of Exit', 'Gross Weight (MT)', 'Designated Bank', 'FOB Value (NGN)',
            'NESS Fee (NGN, 0.5% of FOB)', 'Service Fee (NGN, 0.35% of FOB)', 'FOB Value', 'USD', 'EUR', 'GBP',
            'FOB Currency', 'Exchange Rate', 'Repatriation Date', 'Receipt No. 1', 'Receipt No. 2', 'NEPC Number',
        ], $header);
    }

    public function testServiceFeeAndNessFeeAreComputedFromFobAndExchangeRate(): void
    {
        $row = [
            'document_number' => '2021-00002', 'issued_at' => '2021-02-27', 'form_nxp_number' => 'NXP1',
            'exporter_bank_name' => 'Fidelity Bank Plc', 'inspection_date' => '2021-02-27',
            'exporter_name' => 'Tiger Foods', 'product_category' => 'Spices', 'hs_code' => '0910.9900.00',
            'shipment_date' => '2021-02-27', 'destination_country' => 'Liberia', 'point_of_exit' => 'Onne',
            'gross_weight_kg' => '15279.00', 'declared_value' => '10000.00', 'currency' => 'USD',
            'exchange_rate' => '400.0000', 'ness_actual_payable' => null, 'ness_receipt_no' => 'R1',
            'nepc_number' => '0001127',
        ];

        $parsed = str_getcsv(explode("\n", trim((new NbsReturnBuilder())->build([$row])))[1]);

        // FOB(NGN) = 10000 * 400 = 4,000,000
        self::assertSame('4000000.00', $parsed[15]);
        // NESS = 0.5% of 4,000,000 = 20,000
        self::assertSame('20000.00', $parsed[16]);
        // Service fee = 0.35% of 4,000,000 = 14,000
        self::assertSame('14000.00', $parsed[17]);
        self::assertSame('0001127', $parsed[27]);
    }
}
