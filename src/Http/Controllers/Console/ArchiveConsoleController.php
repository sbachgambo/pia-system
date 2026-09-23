<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Archive\ArchiveRepository;
use App\Archive\ArchiveService;
use App\Audit\AuditLog;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/archive — every generated document in one searchable register,
 * with a bulk ZIP download. Open to every office role; invoices and statutory
 * returns only appear for admins (who are the only ones who can see them
 * anywhere else). Bulk downloads are audit-logged.
 */
final class ArchiveConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ArchiveRepository $archive,
        private readonly ArchiveService $service,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $isAdmin = $this->isAdmin($request);
        $filters = $this->filters($request, $isAdmin);
        $page = Pagination::fromRequest($request, 50);
        $result = $this->archive->search($filters, $isAdmin, $page->perPage, $page->offset());

        return $this->render($request, $response, 'console/archive/index', [
            'list'     => $page->envelope($result['rows'], $result['total']),
            'filters'  => $filters,
            'counts'   => $this->archive->counts($isAdmin),
            'isAdmin'  => $isAdmin,
            'maxFiles' => ArchiveService::MAX_FILES,
        ]);
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $isAdmin = $this->isAdmin($request);
        $filters = $this->filters($request, $isAdmin);

        try {
            $zip = $this->service->zip($filters, $isAdmin);
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
            $qs = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== ''));

            return $this->redirect($response, '/console/archive' . ($qs !== '' ? '?' . $qs : ''));
        }

        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $this->audit->record((int) $actor['id'], 'archive.download', 'export', 0, null, [
            'filters' => array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
            'files'   => $zip['included'],
            'skipped' => $zip['skipped'],
        ], ClientContext::ip($request));

        $bytes = (string) file_get_contents($zip['path']);
        @unlink($zip['path']);
        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $zip['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array{q:?string,kind:?string,type:?string,from:?string,to:?string} */
    private function filters(ServerRequestInterface $request, bool $isAdmin): array
    {
        $q = Query::params($request);
        $str = static fn (string $k): ?string => is_string($q[$k] ?? null) && trim($q[$k]) !== '' ? trim($q[$k]) : null;

        $kind = $str('kind');
        $allowed = $isAdmin ? ArchiveRepository::KINDS : ['certificate'];

        return [
            'q'    => $str('q') !== null ? mb_substr((string) $str('q'), 0, 100) : null,
            'kind' => in_array($kind, $allowed, true) ? $kind : null,
            'type' => in_array($str('type'), ['CCI', 'NNCI'], true) ? $str('type') : null,
            'from' => $str('from'),
            'to'   => $str('to'),
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        // The board sees everything an admin sees (read-only).
        return in_array($actor['role'] ?? null, ['admin', 'super_admin', 'board'], true);
    }
}
