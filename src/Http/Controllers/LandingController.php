<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET / — a human-facing front door. Office staff and field inspectors sign
 * in through two completely different apps (server-rendered console vs. the
 * offline PWA); this page just points each at the right one instead of
 * making people guess a URL (or ask).
 */
final class LandingController
{
    public function __construct(private readonly Renderer $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = $this->view->renderRaw('landing');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
