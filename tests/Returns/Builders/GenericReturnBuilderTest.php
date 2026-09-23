<?php

declare(strict_types=1);

namespace App\Tests\Returns\Builders;

use App\Returns\Builders\GenericReturnBuilder;
use PHPUnit\Framework\TestCase;

final class GenericReturnBuilderTest extends TestCase
{
    public function testProducesACoreRegisterWithNoInventedAgencySpecificColumns(): void
    {
        $row = [
            'document_number' => '2021-00003', 'document_type' => 'CCI', 'issued_at' => '2021-03-01',
            'form_nxp_number' => 'NXP2', 'exporter_name' => 'Acme Exports', 'rc_number' => 'RC1',
            'product_category' => 'Cashew', 'hs_code' => '0801.3100.00', 'quantity' => '10.00',
            'unit_of_measure' => 'MT', 'origin_country' => 'Nigeria', 'destination_country' => 'Vietnam',
            'zone' => 'North East', 'declared_value' => '5000.00', 'currency' => 'USD',
            'exchange_rate' => '400.0000',
        ];

        $csv = (new GenericReturnBuilder())->build([$row]);
        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);
        $parsed = str_getcsv($lines[1]);

        self::assertContains('Document No.', $header);
        self::assertContains('FOB Value (NGN)', $header);
        self::assertSame('2021-00003', $parsed[1]);
        self::assertSame('Acme Exports', $parsed[5]);
        self::assertSame('2000000.00', $parsed[array_search('FOB Value (NGN)', $header, true)]); // 5000 * 400
    }
}
