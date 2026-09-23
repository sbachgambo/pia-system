<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\View\Renderer;
use App\Notifications\AttentionFeed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /console/attention — everything that currently needs someone's action. */
final class NotificationsConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly AttentionFeed $feed)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $isAdmin = in_array($user['role'] ?? null, ['admin', 'super_admin'], true);

        return $this->render($request, $response, 'console/attention/index', [
            'counts' => $this->feed->counts($isAdmin),
            'items'  => $this->feed->items($isAdmin),
            'isAdmin' => $isAdmin,
        ]);
    }
}
