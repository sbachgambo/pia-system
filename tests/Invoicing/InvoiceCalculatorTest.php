<?php

declare(strict_types=1);

namespace App\Tests\Invoicing;

use App\Documents\Fees;
use App\Invoicing\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The invoice arithmetic, checked against the client's own sample invoice
 * (sample-docs "PIA Invoice June 2023").
 */
final class InvoiceCalculatorTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function sampleCcis(): array
    {
        return [
            ['document_number' => 'A', 'zone' => 'South-East', 'currency' => 'USD', 'declared_value' => '2001953.30', 'exchange_rate' => '481.7013'],
            ['document_number' => 'B', 'zone' => 'North-East', 'currency' => 'USD', 'declared_value' => '9039882.34', 'exchange_rate' => '449.6325'],
        ];
    }

    public function testReproducesEveryLineOfTheSampleInvoice(): void
    {
        $r = InvoiceCalculator::calculate($this->sampleCcis());
        $byRegion = array_column($r['lines'], null, 'region');

        self::assertSame(964343507.15, $byRegion['South-East']['fob_ngn']);
        self::assertSame(3375202.28, $byRegion['South-East']['fee_ngn']);
        self::assertSame(4064624896.24, $byRegion['North-East']['fob_ngn']);
        self::assertSame(14226187.14, $byRegion['North-East']['fee_ngn']);
        self::assertSame(2, $r['cci_count']);
    }

    public function testTheTotalIsTheSumOfThePrintedLines(): void
    {
        // The sample prints 17,601,389.41 — 0.35% of the combined naira total —
        // while its own two lines add up to .42. We print the sum of the lines
        // so an invoice always adds up on its face.
        $r = InvoiceCalculator::calculate($this->sampleCcis());

        self::assertSame(17601389.42, $r['fee_ngn']);
        self::assertSame(round(array_sum(array_column($r['lines'], 'fee_ngn')), 2), $r['fee_ngn']);
    }

    public function testGroupsByRegionAndCurrencyWithAWeightedAverageRate(): void
    {
        $r = InvoiceCalculator::calculate([
            ['document_number' => '1', 'zone' => 'Lagos', 'currency' => 'USD', 'declared_value' => '1000', 'exchange_rate' => '1500'],
            ['document_number' => '2', 'zone' => 'lagos', 'currency' => 'USD', 'declared_value' => '3000', 'exchange_rate' => '1600'],
            ['document_number' => '3', 'zone' => 'Lagos', 'currency' => 'EUR', 'declared_value' => '500', 'exchange_rate' => '1700'],
        ]);

        self::assertCount(2, $r['lines'], 'same region, different currency = separate lines; region matched ignoring case');
        $usd = array_values(array_filter($r['lines'], static fn ($l) => $l['currency'] === 'USD'))[0];
        self::assertSame(2, $usd['cci_count']);
        self::assertSame(4000.0, $usd['fob']);
        self::assertSame(6300000.0, $usd['fob_ngn'], 'each CCI converted at its own rate');
        self::assertSame(1575.0, $usd['exchange_rate'], 'shown rate = FOB naira / FOB');
    }

    public function testACciWithoutAnExchangeRateIsReportedNotGuessed(): void
    {
        $r = InvoiceCalculator::calculate([
            ['document_number' => '2026-00010', 'zone' => 'Kano', 'currency' => 'USD', 'declared_value' => '1000', 'exchange_rate' => null],
            ['document_number' => '2026-00011', 'zone' => 'Kano', 'currency' => 'USD', 'declared_value' => '1000', 'exchange_rate' => '1500'],
        ]);

        self::assertSame(['2026-00010'], $r['missing_rate']);
        self::assertSame(1, $r['cci_count']);
    }

    public function testUsesTheSharedServiceFeeRate(): void
    {
        self::assertSame(Fees::SERVICE_FEE_RATE, InvoiceCalculator::calculate([])['fee_rate']);
        self::assertSame(0.0035, Fees::SERVICE_FEE_RATE);
        self::assertSame(0.005, Fees::NESS_RATE, 'NESS is 0.5% of FOB (confirmed by the client)');
        self::assertSame('0.35%', Fees::percent(Fees::SERVICE_FEE_RATE));
        self::assertSame('0.5%', Fees::percent(Fees::NESS_RATE));
    }

    public function testAmountInWordsMatchesTheSampleWording(): void
    {
        self::assertSame(
            'Seventeen Million Six Hundred and One Thousand Three Hundred and Eighty-Nine Naira and Forty-One Kobo Only.',
            InvoiceCalculator::nairaInWords(17601389.41),
        );
        self::assertSame('Five Hundred Naira Only.', InvoiceCalculator::nairaInWords(500.00));
    }
}
