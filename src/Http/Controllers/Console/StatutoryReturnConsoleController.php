<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Office\NotFoundException;
use App\Returns\StatutoryReturnService;
use App\Support\ApiException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/statutory-returns — admin-only (gated at the route level by
 * ConsoleRoleMiddleware, see AppFactory). Brief §4/§5/§8, Phase 7.
 */
final class StatutoryReturnConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly StatutoryReturnService $returns)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['agency' => $q['agency'] ?? null, 'status' => $q['status'] ?? null];
        $list = $this->returns->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/statutory_returns/index', [
            'list' => $list, 'filters' => $filters, 'errors' => [],
        ]);
    }

    public function generate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $start = new DateTimeImmutable((string) ($body['period_start'] ?? ''));
            $end = new DateTimeImmutable((string) ($body['period_end'] ?? ''));
            $doc = $this->returns->generate((string) ($body['agency'] ?? ''), $start, $end, (int) $user['id']);
            $this->flash($request, 'success', "{$doc['agency']} return generated — {$doc['row_count']} row(s).");
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        } catch (\Exception) {
            $this->flash($request, 'error', 'Enter valid start/end dates.');
        }

        return $this->redirect($response, '/console/statutory-returns');
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $this->returns->submit((string) $args['uuid'], (int) $user['id']);
            $this->flash($request, 'success', 'Marked submitted.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Return not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/statutory-returns');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $file = $this->returns->download((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Return not found.');
            return $this->redirect($response, '/console/statutory-returns');
        }

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
