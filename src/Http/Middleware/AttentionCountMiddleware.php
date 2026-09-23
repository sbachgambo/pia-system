<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Notifications\AttentionFeed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Puts the topbar bell's count on the request (`attention_count`) for every
 * authenticated console page. Runs INSIDE ConsoleAuthMiddleware. A failure to
 * count must never take a page down, so it degrades to "no badge".
 */
final class AttentionCountMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'attention_count';

    public function __construct(private readonly AttentionFeed $feed)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $isAdmin = is_array($user) && in_array($user['role'] ?? null, ['admin', 'super_admin'], true);

        try {
            $count = $this->feed->counts($isAdmin)['total'];
        } catch (\Throwable) {
            $count = 0;
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $count));
    }
}
