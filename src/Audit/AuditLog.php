<?php

declare(strict_types=1);

namespace App\Audit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Append-only writer for `audit_log` (brief §4 / §7). The ONLY class that
 * touches this table, and it only ever INSERTs — there is no update/delete
 * path anywhere in the app.
 *
 * `before` / `after` are stored as JSON snapshots; pass associative arrays.
 */
final class AuditLog
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $ipAddress = null,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO audit_log
                (actor_id, action, entity_type, entity_id, before_state, after_state, ip_address, created_at)
             VALUES (:actor, :action, :etype, :eid, :before, :after, :ip, :now)'
        )->execute([
            'actor'  => $actorId,
            'action' => $action,
            'etype'  => $entityType,
            'eid'    => $entityId,
            'before' => $before === null ? null : $this->encode($before),
            'after'  => $after === null ? null : $this->encode($after),
            'ip'     => $ipAddress,
            'now'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array<string,mixed>> newest first
     */
    public function forEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.action, a.before_state, a.after_state, a.ip_address, a.created_at, u.uuid AS actor_uuid,
                    u.full_name AS actor_name
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.actor_id
              WHERE a.entity_type = :etype AND a.entity_id = :eid
           ORDER BY a.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute(['etype' => $entityType, 'eid' => $entityId]);

        return array_map(static function (array $r): array {
            return [
                'action'     => (string) $r['action'],
                'actor_uuid' => $r['actor_uuid'] !== null ? (string) $r['actor_uuid'] : null,
                'actor_name' => $r['actor_name'] !== null ? (string) $r['actor_name'] : null,
                'before'     => $r['before_state'] !== null ? json_decode((string) $r['before_state'], true) : null,
                'after'      => $r['after_state'] !== null ? json_decode((string) $r['after_state'], true) : null,
                'ip_address' => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
                'at'         => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * System-wide browse for the console's audit log screen (admin/super_admin
     * only) — every other read path here is scoped to one entity.
     *
     * @param array{action?:string, entity_type?:string, actor_uuid?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id {$where}", $params)
            ->fetchColumn();

        $stmt = $this->run(
            'SELECT a.action, a.entity_type, a.entity_id, a.before_state, a.after_state, a.ip_address, a.created_at,
                    u.uuid AS actor_uuid, u.full_name AS actor_name
               FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
               ' . $where . "
           ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $rows = array_map(static function (array $r): array {
            return [
                'action'      => (string) $r['action'],
                'entity_type' => (string) $r['entity_type'],
                'entity_id'   => (int) $r['entity_id'],
                'actor_uuid'  => $r['actor_uuid'] !== null ? (string) $r['actor_uuid'] : null,
                'actor_name'  => $r['actor_name'] !== null ? (string) $r['actor_name'] : null,
                'before'      => $r['before_state'] !== null ? json_decode((string) $r['before_state'], true) : null,
                'after'       => $r['after_state'] !== null ? json_decode((string) $r['after_state'], true) : null,
                'ip_address'  => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
                'at'          => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<string> distinct actions seen, for a filter dropdown */
    public function distinctActions(): array
    {
        return array_map('strval', $this->pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['action'])) {
            $clauses[] = 'a.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $clauses[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['actor_uuid'])) {
            $clauses[] = 'u.uuid = :actor_uuid';
            $params['actor_uuid'] = $filters['actor_uuid'];
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

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
