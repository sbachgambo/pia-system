<?php

declare(strict_types=1);

namespace App\Returns;

/** Tiny CSV serializer — RFC 4180 via PHP's own fputcsv, in memory. */
final class CsvWriter
{
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public static function write(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not open an in-memory stream for CSV writing.');
        }

        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv === false ? '' : $csv;
    }

    /**
     * Like write(), for exports of user-entered text: any cell that a
     * spreadsheet would evaluate as a formula (leading = + - @, tab or CR) gets
     * a leading apostrophe, so a client named "=HYPERLINK(...)" opens as plain
     * text in Excel instead of executing. Not used for statutory returns,
     * whose numeric columns legitimately start with "-".
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public static function writeSafe(array $headers, array $rows): string
    {
        $neutralise = static fn (string $cell): string => $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false
            ? "'" . $cell
            : $cell;

        return self::write(
            array_map($neutralise, $headers),
            array_map(static fn (array $row): array => array_map(static fn ($c): string => $neutralise((string) $c), $row), $rows),
        );
    }
}
