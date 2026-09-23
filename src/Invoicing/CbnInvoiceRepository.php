<?php

declare(strict_types=1);

namespace App\Invoicing;

use DateTimeImmutable;
use PDO;

/** Prepared-statement access to `cbn_invoices`, `invoice_sequences`, and the CCIs an invoice covers. */
final class CbnInvoiceRepository
{
    private const SELECT =
        'ci.id, ci.uuid, ci.invoice_number, ci.period_month, ci.status, ci.cci_count, ci.fob_ngn, ci.fee_rate,
         ci.fee_ngn, ci.`lines`, ci.file_path, ci.content_hash, ci.hmac_signature, ci.issued_at, ci.paid_at,
         ci.payment_reference, ci.voided_at, ci.void_reason, ci.created_at, u.full_name AS issued_by_name';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The CCIs issued in [start, end) — only CCIs (the sample counts "CCIs
     * issued"; an NNCI means the NESS fee was not paid and is not billed).
     *
     * @return list<array<string,mixed>>
     */
    public function ccisIssuedBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.document_number, d.issued_at, co.zone, co.currency, co.declared_value, co.form_nxp_number,
                    cl.name AS client_name, i.uuid AS inspection_uuid, sd.exchange_rate
               FROM documents d
               JOIN inspections i ON i.id = d.inspection_id
               JOIN inspection_requests ir ON ir.id = i.inspection_request_id
               JOIN consignments co ON co.id = ir.consignment_id
               JOIN clients cl ON cl.id = co.client_id
               LEFT JOIN inspection_shipment_details sd ON sd.inspection_id = i.id
              WHERE d.type = 'CCI' AND d.status = 'issued' AND d.issued_at >= :s AND d.issued_at < :e
           ORDER BY co.zone, d.document_number"
        );
        $stmt->execute(['s' => $start->format('Y-m-d H:i:s'), 'e' => $end->format('Y-m-d H:i:s')]);

        return $stmt->fetchAll();
    }

    /** The live (not void) invoice for a month, if any. */
    public function activeForMonth(string $periodMonth): ?array
    {
        return $this->one("ci.period_month = :m AND ci.status <> 'void'", ['m' => $periodMonth]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->one('ci.uuid = :u', ['u' => $uuid]);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $limit, int $offset, ?string $status): array
    {
        $where = $status !== null && $status !== '' ? 'WHERE ci.status = :s' : '';
        $params = $where !== '' ? ['s' => $status] : [];

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM cbn_invoices ci {$where}");
        $count->execute($params);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . " FROM cbn_invoices ci LEFT JOIN users u ON u.id = ci.issued_by {$where}
           ORDER BY ci.period_month DESC, ci.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** Next "{prefix}/{year}/{NNN}" — atomic, same idiom as the CCI allocator. */
    public function allocateNumber(string $prefix, int $year): string
    {
        $this->pdo->prepare(
            'INSERT INTO invoice_sequences (year, next_number, created_at, updated_at)
             VALUES (:y, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1), updated_at = UTC_TIMESTAMP()'
        )->execute(['y' => $year]);

        return sprintf('%s/%d/%03d', $prefix, $year, (int) $this->pdo->lastInsertId());
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): array
    {
        $this->pdo->prepare(
            'INSERT INTO cbn_invoices
                (uuid, invoice_number, period_month, status, cci_count, fob_ngn, fee_rate, fee_ngn, `lines`,
                 file_path, content_hash, hmac_signature, issued_by, issued_at, created_at, updated_at)
             VALUES (:uuid, :num, :month, \'issued\', :cnt, :fob, :rate, :fee, :lines,
                 :path, :hash, :sig, :by, :at, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'  => $data['uuid'],
            'num'   => $data['invoice_number'],
            'month' => $data['period_month'],
            'cnt'   => $data['cci_count'],
            'fob'   => number_format((float) $data['fob_ngn'], 2, '.', ''),
            'rate'  => number_format((float) $data['fee_rate'], 6, '.', ''),
            'fee'   => number_format((float) $data['fee_ngn'], 2, '.', ''),
            'lines' => json_encode($data['lines'], JSON_THROW_ON_ERROR),
            'path'  => $data['file_path'],
            'hash'  => $data['content_hash'],
            'sig'   => $data['hmac_signature'],
            'by'    => $data['issued_by'],
            'at'    => $data['issued_at'],
        ]);

        return $this->findByUuid((string) $data['uuid']) ?? throw new \RuntimeException('invoice vanished after insert');
    }

    public function markPaid(int $id, string $paidAt, ?string $reference): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cbn_invoices SET status = 'paid', paid_at = :d, payment_reference = :r, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = 'issued'"
        );
        $stmt->execute(['d' => $paidAt, 'r' => $reference, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function markVoid(int $id, string $reason): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cbn_invoices SET status = 'void', voided_at = UTC_TIMESTAMP(), void_reason = :r, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = 'issued'"
        );
        $stmt->execute(['r' => $reason, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $params */
    private function one(string $where, array $params): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . " FROM cbn_invoices ci LEFT JOIN users u ON u.id = ci.issued_by WHERE {$where} LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
