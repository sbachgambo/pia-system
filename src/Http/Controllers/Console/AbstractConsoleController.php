<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\AttentionCountMiddleware;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Support\Csrf;
use App\Http\Support\SessionStore;
use App\Http\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for console pages: rendering into the layout with the
 * standard context (CSRF token, signed-in user, flash messages), redirects,
 * and one-shot flash storage.
 */
abstract class AbstractConsoleController
{
    public function __construct(protected readonly Renderer $view)
    {
    }

    /** @param array<string,mixed> $data */
    protected function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $html = $this->view->withShared([
            'csrf_token'   => $this->csrf($request)->token(),
            'current_user' => $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE),
            'flashes'      => $this->takeFlashes($request),
            'path'         => $request->getUri()->getPath(),
            'attention_count' => (int) $request->getAttribute(AttentionCountMiddleware::ATTRIBUTE, 0),
        ])->render($template, $data);

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    protected function redirect(ResponseInterface $response, string $to, int $status = 302): ResponseInterface
    {
        return $response->withHeader('Location', $to)->withStatus($status);
    }

    protected function session(ServerRequestInterface $request): SessionStore
    {
        $s = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return $s instanceof SessionStore ? $s : new SessionStore();
    }

    protected function csrf(ServerRequestInterface $request): Csrf
    {
        $c = $request->getAttribute(CsrfMiddleware::ATTRIBUTE);

        return $c instanceof Csrf ? $c : new Csrf($this->session($request));
    }

    protected function flash(ServerRequestInterface $request, string $type, string $message): void
    {
        $session = $this->session($request);
        $flashes = $session->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $session->set('_flash', $flashes);
    }

    /**
     * @return list<array{type:string,message:string}>
     */
    protected function takeFlashes(ServerRequestInterface $request): array
    {
        $session = $this->session($request);
        $flashes = $session->get('_flash', []);
        $session->remove('_flash');

        return is_array($flashes) ? $flashes : [];
    }
}
