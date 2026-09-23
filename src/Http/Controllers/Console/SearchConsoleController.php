<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Search\GlobalSearch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /console/search — every office role; user results are admin-only. */
final class SearchConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly GlobalSearch $search)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = trim((string) (Query::params($request)['q'] ?? ''));
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $isAdmin = in_array($user['role'] ?? null, ['admin', 'super_admin'], true);

        return $this->render($request, $response, 'console/search/index', [
            'q'         => $q,
            'tooShort'  => $q !== '' && mb_strlen($q) < GlobalSearch::MIN_LENGTH,
            'results'   => $this->search->search($q, $isAdmin),
        ]);
    }
}
