<?php

declare(strict_types=1);

namespace App\Users;

use PDO;

/**
 * Prepared-statement access to `users` for the console's user-management
 * screen (admin/super_admin only). Deliberately separate from
 * `App\Auth\UserRepository`, which is the auth/login read path — this class
 * is the admin CRUD path and never selects `password_hash`/`pin_hash` back
 * out to a template.
 */
final class UserAdminRepository
{
    private const COLUMNS = 'id, uuid, full_name, email, phone, role, zone, status, receive_digest, totp_enabled_at, last_login_at, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> the inserted row
     */
    public function create(string $uuid, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (uuid, full_name, email, phone, password_hash, role, zone, status, created_at, updated_at)
             VALUES (:uuid, :full_name, :email, :phone, :password_hash, :role, :zone, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid'          => $uuid,
            'full_name'     => $data['full_name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'],
            'password_hash' => $data['password_hash'],
            'role'          => $data['role'],
            'zone'          => $data['zone'],
            'status'        => $data['status'],
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('user vanished after insert');
    }

    /**
     * @param array<string,mixed> $data only the columns present are updated
     * @return array<string,mixed> the updated row
     */
    public function update(int $id, array $data): array
    {
        $allowed = ['full_name', 'email', 'phone', 'role', 'zone', 'status', 'receive_digest'];
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
            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->pdo->prepare($sql)->execute($params);
        }

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: throw new \RuntimeException('user not found after update');
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = :h, updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['h' => $hash, 'id' => $id]);
    }

    /** Every console session that began before now stops being valid (see ConsoleAuthMiddleware). */
    public function revokeSessions(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET sessions_valid_after = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findIdByUuid(string $uuid): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function existsWithEmail(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{role?:string, status?:string, q?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run("SELECT COUNT(*) FROM users {$where}", $params)->fetchColumn();

        $rows = $this->run(
            'SELECT ' . self::COLUMNS . " FROM users {$where} ORDER BY full_name ASC, id ASC LIMIT {$limit} OFFSET {$offset}",
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

        if (!empty($filters['role'])) {
            $clauses[] = 'role = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(full_name LIKE :q OR email LIKE :q)';
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
