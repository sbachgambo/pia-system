<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/**
 * Read-only snapshot for /console/security: what the auth rate limiter has
 * been doing, how many API sessions are live, and account-hygiene counts.
 * Pure reads over tables the app already maintains — no new data is collected.
 */
final class SecurityOverview
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $rateLimitMax,
        private readonly int $rateLimitWindowSeconds,
    ) {
    }

    /**
     * Busiest limiter buckets in the last 24h. A bucket key is
     * "auth:{path}:{ip}"; `blocked` means the window went past the limit
     * (i.e. the client actually received a 429).
     *
     * @return list<array{path:string,ip:string,hits:int,window:string,blocked:bool}>
     */
    public function busiestBuckets(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bucket_key, window_started_at, hits FROM rate_limits
              WHERE window_started_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)
           ORDER BY hits DESC, window_started_at DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            // "auth:/console/login:203.0.113.9" — the IP may itself contain ':' (IPv6).
            $parts = explode(':', (string) $r['bucket_key'], 3);
            $out[] = [
                'path'    => $parts[1] ?? (string) $r['bucket_key'],
                'ip'      => $parts[2] ?? '—',
                'hits'    => (int) $r['hits'],
                'window'  => (string) $r['window_started_at'],
                'blocked' => (int) $r['hits'] > $this->rateLimitMax,
            ];
        }

        return $out;
    }

    /** @return array{blocked_windows:int,distinct_ips:int,total_hits:int} last 24h */
    public function rateLimitSummary(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(hits > :max), 0) AS blocked_windows,
                COUNT(DISTINCT SUBSTRING(bucket_key, LOCATE(\':\', bucket_key, LOCATE(\':\', bucket_key) + 1) + 1)) AS distinct_ips,
                COALESCE(SUM(hits), 0) AS total_hits
               FROM rate_limits WHERE window_started_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        );
        $stmt->execute(['max' => $this->rateLimitMax]);
        $r = $stmt->fetch() ?: [];

        return [
            'blocked_windows' => (int) ($r['blocked_windows'] ?? 0),
            'distinct_ips'    => (int) ($r['distinct_ips'] ?? 0),
            'total_hits'      => (int) ($r['total_hits'] ?? 0),
        ];
    }

    /** Live (unused, unrevoked, unexpired) refresh tokens = signed-in API/PWA sessions. */
    public function activeApiSessions(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM refresh_tokens
              WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        )->fetchColumn();
    }

    /** @return array{active:int,suspended:int,never_signed_in:int,admins:int} */
    public function accountHygiene(): array
    {
        $r = $this->pdo->query(
            "SELECT
                COALESCE(SUM(status = 'active'), 0) AS active,
                COALESCE(SUM(status = 'suspended'), 0) AS suspended,
                COALESCE(SUM(last_login_at IS NULL AND status = 'active'), 0) AS never_signed_in,
                COALESCE(SUM(role IN ('admin', 'super_admin') AND status = 'active'), 0) AS admins
               FROM users"
        )->fetch() ?: [];

        return [
            'active'          => (int) ($r['active'] ?? 0),
            'suspended'       => (int) ($r['suspended'] ?? 0),
            'never_signed_in' => (int) ($r['never_signed_in'] ?? 0),
            'admins'          => (int) ($r['admins'] ?? 0),
        ];
    }

    /** @return list<array{uuid:string,full_name:string,role:string,last_login_at:string}> */
    public function recentSignIns(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uuid, full_name, role, last_login_at FROM users
              WHERE last_login_at IS NOT NULL ORDER BY last_login_at DESC LIMIT ' . max(1, min(50, $limit))
        );
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'uuid'          => (string) $r['uuid'],
            'full_name'     => (string) $r['full_name'],
            'role'          => (string) $r['role'],
            'last_login_at' => (string) $r['last_login_at'],
        ], $stmt->fetchAll());
    }

    public function rateLimitMax(): int
    {
        return $this->rateLimitMax;
    }

    public function windowMinutes(): int
    {
        return intdiv($this->rateLimitWindowSeconds, 60);
    }
}
