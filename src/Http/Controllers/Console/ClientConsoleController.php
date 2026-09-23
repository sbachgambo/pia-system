<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Office\ClientService;
use App\Office\NotFoundException;
use App\Records\RecordAttachmentService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/clients — browser CRUD over ClientService (the same service the
 * JSON API uses). Validation errors (ApiException 422) are caught and the
 * form is re-rendered with the messages and the user's input preserved.
 */
final class ClientConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ClientService $clients,
        private readonly RecordAttachmentService $attachments,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['type' => $q['type'] ?? null, 'q' => $q['q'] ?? null];
        $list = $this->clients->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/clients/index', [
            'list'    => $list,
            'filters' => $filters,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/clients/form', [
            'mode'   => 'create',
            'client' => null,
            'old'    => [],
            'errors' => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $client = $this->clients->create(Input::fromRequest($request));
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/clients/form', [
                'mode'   => 'create',
                'client' => null,
                'old'    => $body,
                'errors' => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->flash($request, 'success', "Client “{$client['name']}” created.");

        return $this->redirect($response, '/console/clients/' . $client['uuid'] . '/edit');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $client = $this->clients->get((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Client not found.');
            return $this->redirect($response, '/console/clients');
        }

        return $this->render($request, $response, 'console/clients/form', [
            'mode'        => 'edit',
            'client'      => $client,
            'old'         => $client,
            'errors'      => [],
            'attachments' => $this->attachments->list('client', $client['uuid']),
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();

        try {
            $client = $this->clients->update($uuid, Input::fromRequest($request));
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Client not found.');
            return $this->redirect($response, '/console/clients');
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/clients/form', [
                'mode'   => 'edit',
                'client' => ['uuid' => $uuid] + $body,
                'old'    => $body,
                'errors' => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->flash($request, 'success', 'Client updated.');

        return $this->redirect($response, '/console/clients/' . $client['uuid'] . '/edit');
    }

    /** @return array<string,string> field => message (or _ => message) */
    protected function errorsFrom(ApiException $e): array
    {
        $field = $e->getDetails()['field'] ?? '_';

        return [(string) $field => $e->getMessage()];
    }
}
