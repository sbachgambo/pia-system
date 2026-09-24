<?php

declare(strict_types=1);

namespace App\Returns\Builders;

use App\Documents\Fees;
use App\Returns\CsvWriter;

/** Shared formatting for the register-style builders (CBN/NBS/generic). */
abstract class AbstractReturnBuilder implements ReturnBuilderInterface
{
    public function build(array $rows): string
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = $this->row($i + 1, $row);
        }

        // writeSafe, not write: these files carry exporter-supplied names and
        // product text, and are opened in Excel by the CBN, NBS and NEPC.
        // Signed numbers pass through untouched.
        return CsvWriter::writeSafe($this->headers(), $out);
    }

    /** @return list<string> */
    abstract protected function headers(): array;

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    abstract protected function row(int $serial, array $row): array;

    protected function money(mixed $value, int $decimals = 2): string
    {
        return $value === null ? '' : number_format((float) $value, $decimals, '.', '');
    }

    /** Stored weights are kg (brief-adjacent internal unit); the real reports use metric tonnes. */
    protected function kgToMt(mixed $kg): string
    {
        return $kg === null ? '' : number_format(((float) $kg) / 1000, 3, '.', '');
    }

    protected function date(mixed $value, string $format = 'd/m/Y'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format($format);
        } catch (\Exception) {
            return '';
        }
    }

    protected function nairaValue(array $row): ?float
    {
        $fob = $row['declared_value'] !== null ? (float) $row['declared_value'] : null;
        $rate = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;

        return ($fob !== null && $rate !== null) ? $fob * $rate : null;
    }

    /** NESS fee (Fees::NESS_RATE of FOB) in Naira — the CCI's own NESS figure wins when the office recorded one. */
    protected function nessFeeNaira(array $row): ?float
    {
        if ($row['ness_actual_payable'] !== null) {
            return (float) $row['ness_actual_payable'];
        }
        $naira = $this->nairaValue($row);

        return $naira !== null ? $naira * Fees::NESS_RATE : null;
    }

    /** PIA service fee (Fees::SERVICE_FEE_RATE of FOB) in Naira — billed to the CBN, not a NESS charge. */
    protected function serviceFeeNaira(array $row): ?float
    {
        $naira = $this->nairaValue($row);

        return $naira !== null ? $naira * Fees::SERVICE_FEE_RATE : null;
    }

    protected function str(mixed $v): string
    {
        return $v === null ? '' : (string) $v;
    }
}
