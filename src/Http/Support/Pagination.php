<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Offset pagination for the office list endpoints. Simple and predictable for
 * an internal console; `?page=` is 1-based, `?per_page=` is clamped to
 * [1, 100] with a default of 25.
 */
final class Pagination
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request, int $default = 25, int $max = 100): self
    {
        $params  = Query::params($request);
        $page    = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? $default);

        return new self(
            page: max(1, $page),
            perPage: max(1, min($max, $perPage)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function envelope(array $rows, int $total): array
    {
        return [
            'data' => $rows,
            'pagination' => [
                'page'        => $this->page,
                'per_page'    => $this->perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $this->perPage),
            ],
        ];
    }
}
