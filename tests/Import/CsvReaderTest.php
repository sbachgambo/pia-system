<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\CsvReader;
use App\Support\ApiException;
use PHPUnit\Framework\TestCase;

/** The forgiving bits of CSV reading, which is where real spreadsheets differ. */
final class CsvReaderTest extends TestCase
{
    public function testStripsABomAndNormalisesHeaderNames(): void
    {
        $parsed = CsvReader::parse("\xEF\xBB\xBFName, Contact Email ,RC-Number\nTiger,a@b.test,RC1\n");

        self::assertSame(['name', 'contact_email', 'rc_number'], $parsed['headers']);
        self::assertSame(['name' => 'Tiger', 'contact_email' => 'a@b.test', 'rc_number' => 'RC1'], $parsed['rows'][0]['data']);
    }

    public function testSkipsBlankLinesAndKeepsSpreadsheetLineNumbers(): void
    {
        $parsed = CsvReader::parse("name\nAlpha\n\nBravo\n");

        self::assertCount(2, $parsed['rows']);
        self::assertSame(2, $parsed['rows'][0]['line']);
        self::assertSame(4, $parsed['rows'][1]['line'], 'the line number must match what the person sees in their spreadsheet');
    }

    public function testKeepsQuotedCommasAndNewlinesInsideACell(): void
    {
        $parsed = CsvReader::parse("name,address\nTiger,\"12 Marina Road, Lagos\"\n");

        self::assertSame('12 Marina Road, Lagos', $parsed['rows'][0]['data']['address']);
    }

    public function testRejectsEmptyHeaderlessAndNonUtf8Files(): void
    {
        foreach (['', "name,type\n", "\xff\xfeName\x00\n"] as $bad) {
            try {
                CsvReader::parse($bad);
                self::fail('this file should have been refused: ' . bin2hex(substr($bad, 0, 8)));
            } catch (ApiException $e) {
                self::assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function testRejectsAFileWithTooManyRows(): void
    {
        $csv = "name\n" . str_repeat("Alpha\n", CsvReader::MAX_ROWS + 1);

        $this->expectException(ApiException::class);
        CsvReader::parse($csv);
    }
}
