<?php

declare(strict_types=1);

namespace App\Archive;

use PDO;

/**
 * The document archive: one register of every file the system has generated.
 *
 * Nothing has to be "sent to" the archive — every certificate, invoice and
 * statutory return is recorded the moment it is generated, and this reads
 * those records together. So the archive is automatic and complete by
 * construction, and it keeps voided / superseded documents too: an archive
 * that forgets what was cancelled isn't one.
 *
 * Certificates (CCI/NNCI) are visible to every office role, exactly as they
 * are on the review screens. CBN invoices and statutory returns are admin-only
 * elsewhere, so they only join the union when `$includeAdmin` is true.
 */
final class ArchiveRepository
{
    public const KINDS = ['certificate', 'invoice', 'return'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{q?:?string, kind?:?string, type?:?string, from?:?string, to?:?string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $filters, bool $includeAdmin, int $limit, int $offset): array
    {
        [$sql, $params] = $this->build($filters, $includeAdmin);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ({$sql}) a");
        $count->execute($params);

        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare("{$sql} ORDER BY issued_at DESC, number DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array{certificate:int,invoice:int,return:int} */
    public function counts(bool $includeAdmin): array
    {
        $out = ['certificate' => 0, 'invoice' => 0, 'return' => 0];
        $out['certificate'] = (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
        if ($includeAdmin) {
            $out['invoice'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cbn_invoices')->fetchColumn();
            $out['return'] = (int) $this->pdo->query('SELECT COUNT(*) FROM statutory_returns')->fetchColumn();
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function build(array $filters, bool $includeAdmin): array
    {
        $lit = static fn (string $s): string => "_utf8mb4'{$s}' COLLATE utf8mb4_unicode_ci";

        $branches = [
            'certificate' => "SELECT {$lit('certificate')} AS kind, d.type AS type, d.document_number AS number, d.issued_at,
                    d.status, cl.name AS client_name, co.form_nxp_number AS nxp_number, d.uuid, i.uuid AS parent_uuid,
                    d.file_path, {$lit('pdf')} AS format, d.content_hash, d.hmac_signature
               FROM documents d
               JOIN inspections i ON i.id = d.inspection_id
               JOIN inspection_requests r ON r.id = i.inspection_request_id
               JOIN consignments co ON co.id = r.consignment_id
               JOIN clients cl ON cl.id = co.client_id",
        ];
        if ($includeAdmin) {
            // Every branch names its columns: when a filter leaves a single
            // branch, that branch alone defines the result's column names.
            $branches['invoice'] = "SELECT {$lit('invoice')} AS kind, {$lit('CBN invoice')} AS type, ci.invoice_number AS number,
                    ci.issued_at AS issued_at, ci.status AS status, {$lit('Central Bank of Nigeria')} AS client_name,
                    NULL AS nxp_number, ci.uuid AS uuid, NULL AS parent_uuid, ci.file_path AS file_path, {$lit('pdf')} AS format,
                    ci.content_hash AS content_hash, ci.hmac_signature AS hmac_signature
               FROM cbn_invoices ci";
            $branches['return'] = "SELECT {$lit('return')} AS kind, CONCAT(sr.agency, ' return') AS type,
                    CONCAT(sr.agency, ' ', DATE_FORMAT(sr.period_start, '%Y-%m-%d'), ' to ', DATE_FORMAT(sr.period_end, '%Y-%m-%d')) AS number,
                    sr.generated_at AS issued_at, sr.status AS status, sr.agency AS client_name, NULL AS nxp_number,
                    sr.uuid AS uuid, NULL AS parent_uuid, sr.file_path AS file_path, sr.format AS format,
                    NULL AS content_hash, NULL AS hmac_signature
               FROM statutory_returns sr";
        }

        // A kind this viewer can't see yields nothing — never a different kind.
        $kind = $filters['kind'] ?? null;
        $nothing = false;
        if (is_string($kind) && in_array($kind, self::KINDS, true)) {
            if (isset($branches[$kind])) {
                $branches = [$kind => $branches[$kind]];
            } else {
                $branches = ['certificate' => $branches['certificate']];
                $nothing = true;
            }
        }

        $where = $nothing ? ['1 = 0'] : [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(a.number LIKE :q1 OR a.client_name LIKE :q2 OR a.nxp_number LIKE :q3)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (!empty($filters['type']) && in_array($filters['type'], ['CCI', 'NNCI'], true)) {
            $where[] = 'a.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['from']) && self::isDate((string) $filters['from'])) {
            $where[] = 'a.issued_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to']) && self::isDate((string) $filters['to'])) {
            $where[] = 'a.issued_at < DATE_ADD(:to, INTERVAL 1 DAY)';
            $params['to'] = $filters['to'];
        }

        $union = implode("\n UNION ALL \n", $branches);
        $sql = "SELECT a.* FROM ({$union}) a" . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        return [$sql, $params];
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
