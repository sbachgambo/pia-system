<?php

declare(strict_types=1);

namespace App\Tests\Returns\Builders;

use App\Returns\Builders\CbnReturnBuilder;
use PHPUnit\Framework\TestCase;

final class CbnReturnBuilderTest extends TestCase
{
    /** A row shaped like ReturnRowAssembler::rowsForPeriod(), using the real sample's own values. */
    private function sampleRow(): array
    {
        return [
            'document_number' => '2021-00002', 'document_type' => 'CCI', 'issued_at' => '2021-02-27 10:00:00',
            'inspection_date' => '2021-02-27 09:00:00', 'point_of_exit' => 'Onne',
            'exporter_name' => 'Tiger Foods Limited', 'rc_number' => '291714',
            'product_category' => 'Various Food Spices', 'hs_code' => '0910.9900.00',
            'quantity' => '8930.00', 'declared_value' => '10708.00', 'currency' => 'USD',
            'destination_country' => 'Liberia', 'origin_country' => 'Nigeria', 'zone' => 'South East',
            'form_nxp_number' => 'XG20210007033042', 'nepc_number' => '0001127',
            'exporter_bank_name' => 'Fidelity Bank Plc', 'invoice_number' => 'TFL/EX/002-2021',
            'invoice_date' => '2021-02-27', 'shipment_date' => '2021-02-27',
            'gross_weight_kg' => '15279.00', 'net_weight_kg' => '13890.00', 'exchange_rate' => null,
            'ness_receipt_no' => '026328', 'ness_actual_payable' => '20398.74', 'forex_exchange_date' => null,
        ];
    }

    public function testHeaderMatchesTheRealAppendixColumnsInOrder(): void
    {
        $csv = (new CbnReturnBuilder())->build([]);
        $header = str_getcsv(explode("\n", $csv)[0]);

        self::assertSame([
            'S/N', 'CCI No.', 'CCI Date', 'NXP No.', 'NXP Issuing Bank', 'Inspection Date',
            'Exporter Name', 'Exported Products', 'HS Code', 'Shipment Date', 'Destination',
            'Point of Exit', 'Gross Weight (MT)', 'Net Weight (MT)', 'Unit Price', 'Designated Bank',
            'Receipt No.', 'FOB Currency', 'FOB Value (US$)', 'FOB Value (EUR)', 'FOB Value (GBP)',
            'Exchange Rate', 'FOB Value (NGN)', 'NESS Fee Payable (NGN)',
        ], $header);
    }

    public function testRowValuesMatchTheRealSampleWithinFormatting(): void
    {
        $csv = (new CbnReturnBuilder())->build([$this->sampleRow()]);
        $lines = explode("\n", trim($csv));
        $row = str_getcsv($lines[1]);

        self::assertSame('1', $row[0]);
        self::assertSame('2021-00002', $row[1]);
        self::assertSame('XG20210007033042', $row[3]); // NXP No.
        self::assertSame('Tiger Foods Limited', $row[6]);
        self::assertSame('Various Food Spices', $row[7]);
        self::assertSame('15.279', $row[12]); // 15279 kg -> 15.279 MT
        self::assertSame('13.890', $row[13]);
        self::assertSame('1.20', $row[14]); // unit price = 10708 / 8930
        self::assertSame('USD', $row[17]);
        self::assertSame('10708.00', $row[18]); // FOB Value (US$)
        self::assertSame('', $row[19]); // FOB Value (EUR) blank
        self::assertSame('20398.74', $row[23]); // NESS fee — the office-recorded figure wins over the 0.5% fallback
    }

    public function testNessFeeFallsBackTo05PercentWhenNotRecorded(): void
    {
        $row = $this->sampleRow();
        $row['ness_actual_payable'] = null;
        $row['exchange_rate'] = '400.0000';

        $csv = (new CbnReturnBuilder())->build([$row]);
        $parsed = str_getcsv(explode("\n", trim($csv))[1]);

        // 10708 * 400 * 0.005 = 21416.00
        self::assertSame('21416.00', $parsed[23]);
    }
}
