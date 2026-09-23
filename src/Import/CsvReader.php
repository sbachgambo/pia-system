<?php

declare(strict_types=1);

namespace App\Import;

use App\Support\ApiException;

/**
 * Reads an uploaded CSV into header-keyed rows.
 *
 * Deliberately forgiving about the things people's spreadsheets do and strict
 * about everything else: a UTF-8 BOM is stripped, headers are matched
 * case-insensitively and ignoring spaces/underscores, blank lines are skipped,
 * and a row with more or fewer cells than the header is an error on that row
 * rather than a failed file.
 */
final class CsvReader
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 1048576; // 1 MiB

    /**
     * @return array{headers:list<string>, rows:list<array{line:int,data:array<string,string>,error:string|null}>}
     */
    public static function parse(string $csv): array
    {
        if ($csv === '') {
            throw new ApiException(422, 'validation_failed', 'That file is empty.');
        }
        if (strlen($csv) > self::MAX_BYTES) {
            throw new ApiException(422, 'validation_failed', 'That file is larger than 1 MB. Split it into smaller batches.');
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw new ApiException(422, 'validation_failed', 'That file is not UTF-8 text. Re-save it from your spreadsheet as "CSV UTF-8".');
        }

        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not open an in-memory stream for CSV reading.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        $headers = fgetcsv($fh);
        if ($headers === false || $headers === [null]) {
            fclose($fh);

            throw new ApiException(422, 'validation_failed', 'That file has no header row.');
        }

        $headers = array_map(static fn ($h): string => self::normalise((string) $h), $headers);
        if (array_filter($headers) === []) {
            fclose($fh);

            throw new ApiException(422, 'validation_failed', 'That file has no usable column names in its first row.');
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($fh)) !== false) {
            $line++;
            if ($cells === [null] || $cells === [] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($fh);

                throw new ApiException(422, 'validation_failed', 'That file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller batches.');
            }

            if (count($cells) !== count($headers)) {
                $rows[] = [
                    'line'  => $line,
                    'data'  => [],
                    'error' => 'This row has ' . count($cells) . ' values but the header has ' . count($headers) . '.',
                ];
                continue;
            }

            /** @var array<string,string> $data */
            $data = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $data[$header] = trim((string) $cells[$i]);
                }
            }

            $rows[] = ['line' => $line, 'data' => $data, 'error' => null];
        }
        fclose($fh);

        if ($rows === []) {
            throw new ApiException(422, 'validation_failed', 'That file has a header row but no data rows.');
        }

        return ['headers' => array_values(array_filter($headers)), 'rows' => $rows];
    }

    /** "Contact Email", "contact_email" and "CONTACT EMAIL" are all the same column. */
    private static function normalise(string $header): string
    {
        $header = strtolower(trim($header));

        return (string) preg_replace('/[\s\-]+/', '_', $header);
    }
}
