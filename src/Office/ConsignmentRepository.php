<?php

declare(strict_types=1);

namespace App\Office;

use PDO;

/**
 * Prepared-statement access to `consignments`. Reads join `clients` so callers
 * get `client_uuid` without a second query; the internal `client_id` never
 * leaves this layer.
 */
final class ConsignmentRepository
{
    private const SELECT =
        'c.id, c.uuid, cl.uuid AS client_uuid, c.direction, c.product_category, c.product_description,
         c.hs_code, c.quantity, c.unit_of_measure, c.declared_value, c.currency, c.origin_country,
         c.destination_country, c.zone, c.form_nxp_number, c.created_at, c.updated_at, cl.name AS client_name';

    private const WRITABLE = [
        'direction', 'product_category', 'product_description', 'hs_code', 'quantity',
        'unit_of_measure', 'declared_value', 'currency', 'origin_country',
        'destination_country', 'zone', 'form_nxp_number',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $data column-keyed (writable columns + client_id)
     * @return array<string,mixed>
     */
    public function create(string $uuid, int $clientId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO consignments
                (uuid, client_id, direction, product_category, product_description, hs_code, quantity,
                 unit_of_measure, declared_value, currency, origin_country, destination_country, zone,
                 form_nxp_number, created_at, updated_at)
             VALUES
                (:uuid, :client_id, :direction, :product_category, :product_description, :hs_code, :quantity,
                 :unit_of_measure, :declared_value, :currency, :origin_country, :destination_country, :zone,
                 :form_nxp_number, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid'                => $uuid,
            'client_id'           => $clientId,
            'direction'           => $data['direction'],
            'product_category'    => $data['product_category'],
            'product_description' => $data['product_description'],
            'hs_code'             => $data['hs_code'],
            'quantity'            => $data['quantity'],
            'unit_of_measure'     => $data['unit_of_measure'],
            'declared_value'      => $data['declared_value'],
            'currency'            => $data['currency'],
            'origin_country'      => $data['origin_country'],
            'destination_country' => $data['destination_country'],
            'zone'                => $data['zone'],
            'form_nxp_number'     => $data['form_nxp_number'],
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('consignment vanished after insert');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data): array
    {
        $sets = [];
        $params = ['id' => $id];

        foreach (self::WRITABLE as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('client_id', $data)) {
            $sets[] = 'client_id = :client_id';
            $params['client_id'] = $data['client_id'];
        }

        if ($sets !== []) {
            $sets[] = 'updated_at = UTC_TIMESTAMP()';
            $this->pdo->prepare('UPDATE consignments SET ' . implode(', ', $sets) . ' WHERE id = :id')
                ->execute($params);
        }

        return $this->findById($id) ?? throw new \RuntimeException('consignment not found after update');
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('c.uuid = :v', ['v' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('c.id = :v', ['v' => $id]);
    }

    public function findIdByUuid(string $uuid): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM consignments WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Everything that has happened under one NXP record, newest first: each
     * inspection request, its inspection, and any certificate (CCI/NNCI) issued.
     * One row per request × document (a request with no document yet still
     * appears once, with NULL document columns).
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.uuid AS request_uuid, r.status AS request_status, r.requested_at,
                    i.uuid AS inspection_uuid, i.status AS inspection_status,
                    d.uuid AS document_uuid, d.type AS document_type, d.document_number, d.issued_at, d.status AS document_status
               FROM inspection_requests r
               LEFT JOIN inspections i ON i.inspection_request_id = r.id
               LEFT JOIN documents d ON d.inspection_id = i.id
              WHERE r.consignment_id = :c
           ORDER BY r.requested_at DESC, r.id DESC, d.id DESC'
        );
        $stmt->execute(['c' => $id]);

        return $stmt->fetchAll();
    }

    /** Whether another record already carries this NXP number (case-insensitive, via the column collation). */
    public function nxpNumberTaken(string $nxp, ?int $exceptId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM consignments WHERE form_nxp_number = :n LIMIT 1');
        $stmt->execute(['n' => trim($nxp)]);
        $id = $stmt->fetchColumn();

        return $id !== false && (int) $id !== $exceptId;
    }

    /**
     * @param array{direction?:string, zone?:string, client_uuid?:string, q?:string, missing_nxp?:bool} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run(
            "SELECT COUNT(*) FROM consignments c JOIN clients cl ON cl.id = c.client_id {$where}",
            $params,
        )->fetchColumn();

        $rows = $this->run(
            'SELECT ' . self::SELECT . " FROM consignments c JOIN clients cl ON cl.id = c.client_id
             {$where} ORDER BY c.created_at DESC, c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $condition, array $params): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . " FROM consignments c JOIN clients cl ON cl.id = c.client_id WHERE {$condition} LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (['direction' => 'c.direction', 'zone' => 'c.zone'] as $key => $column) {
            if (!empty($filters[$key])) {
                $clauses[] = "{$column} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }
        if (!empty($filters['client_uuid'])) {
            $clauses[] = 'cl.uuid = :client_uuid';
            $params['client_uuid'] = $filters['client_uuid'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $clauses[] = '(c.form_nxp_number LIKE :q1 OR c.product_category LIKE :q2 OR c.product_description LIKE :q3 OR c.hs_code LIKE :q4 OR cl.name LIKE :q5)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }
        if (!empty($filters['missing_nxp'])) {
            // Exports saved before the NXP number became mandatory.
            $clauses[] = "c.direction = 'export' AND c.form_nxp_number IS NULL";
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
