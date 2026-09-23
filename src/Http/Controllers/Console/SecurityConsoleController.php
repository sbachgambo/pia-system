<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\View\Renderer;
use App\Security\SecurityOverview;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /console/security — admin/super_admin only (route-gated). Read-only. */
final class SecurityConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly SecurityOverview $security)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/security/index', [
            'summary'  => $this->security->rateLimitSummary(),
            'buckets'  => $this->security->busiestBuckets(),
            'sessions' => $this->security->activeApiSessions(),
            'hygiene'  => $this->security->accountHygiene(),
            'signins'  => $this->security->recentSignIns(),
            'limit'    => ['max' => $this->security->rateLimitMax(), 'minutes' => $this->security->windowMinutes()],
        ]);
    }
}
