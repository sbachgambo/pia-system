<?php

declare(strict_types=1);

namespace App\Office;

use PDO;

/**
 * Prepared-statement access to `clients`. Returns raw DB rows; the service
 * layer shapes them for the API. Internal `id` is used only for foreign keys
 * and never leaves the service layer.
 */
final class ClientRepository
{
    /** Columns safe to read back. */
    private const COLUMNS = 'id, uuid, name, type, rc_number, address, contact_name, contact_phone, contact_email, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $data keyed by column name (no id/uuid/timestamps)
     * @return array<string,mixed> the inserted row
     */
    public function create(string $uuid, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (uuid, name, type, rc_number, address, contact_name, contact_phone, contact_email, created_at, updated_at)
             VALUES
                (:uuid, :name, :type, :rc_number, :address, :contact_name, :contact_phone, :contact_email,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid'          => $uuid,
            'name'          => $data['name'],
            'type'          => $data['type'],
            'rc_number'     => $data['rc_number'],
            'address'       => $data['address'],
            'contact_name'  => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'contact_email' => $data['contact_email'],
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('client vanished after insert');
    }

    /**
     * @param array<string,mixed> $data only the columns present are updated
     * @return array<string,mixed> the updated row
     */
    public function update(int $id, array $data): array
    {
        $allowed = ['name', 'type', 'rc_number', 'address', 'contact_name', 'contact_phone', 'contact_email'];
        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if ($sets !== []) {
            $sets[] = 'updated_at = UTC_TIMESTAMP()';
            $sql = 'UPDATE clients SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->pdo->prepare($sql)->execute($params);
        }

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM clients WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: throw new \RuntimeException('client not found after update');
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM clients WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findIdByUuid(string $uuid): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function existsWithName(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM clients WHERE name = :name';
        $params = ['name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{type?:string, q?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run("SELECT COUNT(*) FROM clients {$where}", $params)->fetchColumn();

        $rows = $this->run(
            'SELECT ' . self::COLUMNS . " FROM clients {$where} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['type'])) {
            $clauses[] = 'type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(name LIKE :q OR contact_name LIKE :q OR contact_email LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
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
