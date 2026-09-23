<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Backup\BackupService;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/backups — super_admin ONLY (route-gated). A backup holds every
 * password hash and all client data, so it is a step above ordinary admin
 * access. Creating, downloading and deleting are all audit-logged. There is
 * intentionally no restore button.
 */
final class BackupConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly BackupService $backups,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/backups/index', [
            'backups'  => $this->backups->list(),
            'hasZip'   => class_exists(\ZipArchive::class),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        @set_time_limit(300);

        try {
            $b = $this->backups->create();
        } catch (\Throwable $e) {
            $this->flash($request, 'error', 'Backup failed: ' . $e->getMessage());
            return $this->redirect($response, '/console/backups');
        }

        $this->record($request, 'backup.create', ['name' => $b['name'], 'bytes' => $b['bytes'], 'rows' => $b['rows'], 'files' => $b['files']]);
        $this->flash($request, 'success', "Backup created: {$b['name']} ({$b['tables']} tables, {$b['rows']} rows, {$b['files']} files). Download it and keep a copy off this server.");

        return $this->redirect($response, '/console/backups');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $name = (string) (Query::params($request)['name'] ?? '');
        $path = $this->backups->path($name);

        if ($path === null) {
            $this->flash($request, 'error', 'Backup not found.');
            return $this->redirect($response, '/console/backups');
        }

        $this->record($request, 'backup.download', ['name' => $name]);

        $stream = fopen($path, 'rb');
        $response = $response
            ->withHeader('Content-Type', str_ends_with($name, '.zip') ? 'application/zip' : 'application/gzip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // Slim PSR-7 streams straight from the file handle — no full read into memory.
        return $response->withBody(new \Slim\Psr7\Stream($stream));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';

        if ($this->backups->delete($name)) {
            $this->record($request, 'backup.delete', ['name' => $name]);
            $this->flash($request, 'success', 'Backup deleted.');
        } else {
            $this->flash($request, 'error', 'Backup not found.');
        }

        return $this->redirect($response, '/console/backups');
    }

    /** @param array<string,mixed> $after */
    private function record(ServerRequestInterface $request, string $action, array $after): void
    {
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            $action,
            'backup',
            0,
            null,
            $after,
            ClientContext::ip($request),
        );
    }
}
