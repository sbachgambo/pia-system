<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office;

use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Office\ClientService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /api/office/clients — office CRUD (JWT + office roles).
 */
final class ClientController
{
    public function __construct(private readonly ClientService $clients)
    {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::write($response, $this->clients->create(Input::fromRequest($request)), 201);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->clients->get((string) $args['uuid']));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->clients->update((string) $args['uuid'], Input::fromRequest($request)));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'type' => $q['type'] ?? null,
            'q'    => $q['q'] ?? null,
        ];

        return Json::write($response, $this->clients->list(Pagination::fromRequest($request), $filters));
    }
}
