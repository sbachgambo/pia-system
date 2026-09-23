<?php

declare(strict_types=1);

namespace App\Export;

use PDO;

/**
 * Read-only queries behind the console's "Export CSV" buttons. Each method
 * mirrors the filters of the matching list screen, so an export is "what you
 * see, unpaginated", capped at MAX_ROWS to keep one request bounded.
 *
 * @phpstan-type Export array{headers:list<string>, rows:list<list<string>>}
 */
final class ExportRepository
{
    public const MAX_ROWS = 20000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $f type, q
     * @return array{headers:list<string>, rows:list<list<string>>}
     */
    public function clients(array $f): array
    {
        $where = [];
        $p = [];
        if (!empty($f['type'])) {
            $where[] = 'type = :type';
            $p['type'] = $f['type'];
        }
        if (!empty($f['q'])) {
            $like = self::like((string) $f['q']);
            $where[] = '(name LIKE :q1 OR contact_name LIKE :q2 OR contact_email LIKE :q3)';
            $p += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }

        return $this->build(
            ['UUID', 'Name', 'Type', 'RC number', 'Address', 'Contact name', 'Contact phone', 'Contact email', 'Created (UTC)'],
            'SELECT uuid, name, type, rc_number, address, contact_name, contact_phone, contact_email, created_at
               FROM clients' . self::wh($where) . ' ORDER BY name ASC',
            $p,
        );
    }

    /**
     * @param array<string,mixed> $f direction, zone, client_uuid, q
     * @return array{headers:list<string>, rows:list<list<string>>}
     */
    public function consignments(array $f): array
    {
        $where = [];
        $p = [];
        foreach (['direction' => 'c.direction', 'zone' => 'c.zone'] as $key => $col) {
            if (!empty($f[$key])) {
                $where[] = "{$col} = :{$key}";
                $p[$key] = $f[$key];
            }
        }
        if (!empty($f['client_uuid'])) {
            $where[] = 'cl.uuid = :client_uuid';
            $p['client_uuid'] = $f['client_uuid'];
        }
        if (!empty($f['q'])) {
            $like = self::like((string) $f['q']);
            $where[] = '(c.form_nxp_number LIKE :q1 OR c.product_category LIKE :q2 OR c.product_description LIKE :q3 OR c.hs_code LIKE :q4 OR cl.name LIKE :q5)';
            $p += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }

        return $this->build(
            ['NXP no.', 'Client', 'Direction', 'Product category', 'Description', 'HS code', 'Quantity', 'Unit',
             'Declared value', 'Currency', 'Origin', 'Destination', 'Zone', 'Created (UTC)', 'Record reference'],
            'SELECT c.form_nxp_number, cl.name, c.direction, c.product_category, c.product_description, c.hs_code, c.quantity,
                    c.unit_of_measure, c.declared_value, c.currency, c.origin_country, c.destination_country, c.zone,
                    c.created_at, c.uuid
               FROM consignments c JOIN clients cl ON cl.id = c.client_id'
            . self::wh($where) . ' ORDER BY c.created_at DESC',
            $p,
        );
    }

    /**
     * @param array<string,mixed> $f status (null = the review queue's default set)
     * @return array{headers:list<string>, rows:list<list<string>>}
     */
    public function inspections(array $f): array
    {
        $allowed = ['scheduled', 'in_progress', 'synced', 'amended', 'rejected', 'finalized'];
        $status = (string) ($f['status'] ?? '');
        $p = [];

        if ($status !== '' && in_array($status, $allowed, true)) {
            $where = 'WHERE i.status = :status';
            $p['status'] = $status;
        } else {
            $where = "WHERE i.status IN ('synced', 'amended', 'rejected', 'finalized')";
        }

        return $this->build(
            ['NXP no.', 'CCI no.', 'Client', 'Product', 'Inspector', 'Scheduled (UTC)', 'Status', 'Synced (UTC)', 'Finalised (UTC)', 'UUID'],
            "SELECT c.form_nxp_number,
                    (SELECT d.document_number FROM documents d WHERE d.inspection_id = i.id AND d.status = 'issued' ORDER BY d.id DESC LIMIT 1),
                    cl.name AS client_name, c.product_category, u.full_name AS inspector_name,
                    i.scheduled_at, i.status, i.synced_at, i.finalized_at, i.uuid
               FROM inspections i
               JOIN inspection_requests r ON r.id = i.inspection_request_id
               JOIN consignments c ON c.id = r.consignment_id
               JOIN clients cl ON cl.id = c.client_id
               JOIN users u ON u.id = i.inspector_id
               {$where} ORDER BY i.scheduled_at DESC",
            $p,
        );
    }

    /**
     * Never selects password_hash / pin_hash.
     *
     * @param array<string,mixed> $f role, status, q
     * @return array{headers:list<string>, rows:list<list<string>>}
     */
    public function users(array $f): array
    {
        $where = [];
        $p = [];
        foreach (['role', 'status'] as $key) {
            if (!empty($f[$key])) {
                $where[] = "{$key} = :{$key}";
                $p[$key] = $f[$key];
            }
        }
        if (!empty($f['q'])) {
            $like = self::like((string) $f['q']);
            $where[] = '(full_name LIKE :q1 OR email LIKE :q2)';
            $p += ['q1' => $like, 'q2' => $like];
        }

        return $this->build(
            ['UUID', 'Name', 'Email', 'Phone', 'Role', 'Zone', 'Status', 'Last login (UTC)', 'Created (UTC)'],
            'SELECT uuid, full_name, email, phone, role, zone, status, last_login_at, created_at
               FROM users' . self::wh($where) . ' ORDER BY full_name ASC',
            $p,
        );
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed> $params
     * @return array{headers:list<string>, rows:list<list<string>>}
     */
    private function build(array $headers, string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql . ' LIMIT ' . self::MAX_ROWS);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = array_map(static fn ($v): string => $v === null ? '' : (string) $v, $row);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param list<string> $clauses */
    private static function wh(array $clauses): string
    {
        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }
}
