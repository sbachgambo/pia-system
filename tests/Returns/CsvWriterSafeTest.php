<?php

declare(strict_types=1);

namespace App\Tests\Returns;

use App\Returns\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterSafeTest extends TestCase
{
    public function testFormulaLeadingCellsAreNeutralised(): void
    {
        $csv = CsvWriter::writeSafe(['Name'], [['=1+1'], ['+234'], ['-5'], ['@SUM(A1)'], ['plain']]);
        $lines = array_map('trim', explode("\n", trim($csv)));

        self::assertSame("'=1+1", $lines[1]);
        self::assertSame("'+234", $lines[2]);
        self::assertSame("'-5", $lines[3]);
        self::assertSame("'@SUM(A1)", $lines[4]);
        self::assertSame('plain', $lines[5]);
    }

    public function testTabLeadingCellIsNeutralisedToo(): void
    {
        self::assertStringContainsString("'\tx", CsvWriter::writeSafe(['h'], [["\tx"]]));
    }

    public function testEmptyAndSafeCellsAreUntouchedAndQuotingStillWorks(): void
    {
        $csv = CsvWriter::writeSafe(['a', 'b'], [['', 'has, comma "q"']]);

        self::assertSame("a,b\n,\"has, comma \"\"q\"\"\"\n", $csv);
    }

    public function testPlainWriteStillPreservesNegativeNumbersForReturns(): void
    {
        self::assertStringContainsString("-5\n", CsvWriter::write(['n'], [['-5']]));
    }
}
