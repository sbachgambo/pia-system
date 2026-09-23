<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\UserRepository;
use App\Http\Console\ConsoleAuth;
use App\Http\Support\SessionStore;
use App\Security\TwoFactorPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Gate for the authenticated console pages. Mounted inside SessionMiddleware.
 *
 * No session user  -> 302 to /console/login (remembering where they wanted to
 *                     go, so login can bounce them back).
 * Session user gone / suspended / no longer an office role -> session cleared,
 *                     302 to /console/login.
 * Otherwise the user row is attached as the `console_user` attribute.
 */
final class ConsoleAuthMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'console_user';
    public const SESSION_KEY = 'user_uuid';
    public const INTENDED_KEY = 'intended_url';
    public const LOGIN_AT_KEY = 'login_at';

    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorPolicy $twoFactor,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SessionStore $session */
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        $uuid = $session->get(self::SESSION_KEY);
        $user = is_string($uuid) && $uuid !== '' ? $this->users->findByUuid($uuid) : null;

        $ok = $user !== null
            && $user['status'] === 'active'
            && in_array($user['role'], ConsoleAuth::ROLES, true);

        // "Sign out everywhere" / password reset / suspension stamp
        // users.sessions_valid_after; a session that began before it is dead.
        if ($ok && !empty($user['sessions_valid_after'])) {
            $startedAt = (int) $session->get(self::LOGIN_AT_KEY, 0);
            $ok = $startedAt >= (int) strtotime((string) $user['sessions_valid_after'] . ' UTC');
        }

        if (!$ok) {
            $session->remove(self::SESSION_KEY);
            $session->remove(self::LOGIN_AT_KEY);
            $session->set(self::INTENDED_KEY, (string) $request->getUri()->getPath());

            return (new ResponseFactory())->createResponse(302)
                ->withHeader('Location', '/console/login');
        }

        // Two-factor is mandatory for this role and this person hasn't enrolled
        // yet: confine them to the account-security page until they have.
        if ($this->twoFactor->mustEnrol($user) && !str_starts_with($request->getUri()->getPath(), '/console/account')) {
            $flashes = $session->get('_flash', []);
            $flashes[] = ['type' => 'error', 'message' => 'Two-factor authentication is required for your role. Set it up to continue.'];
            $session->set('_flash', $flashes);

            return (new ResponseFactory())->createResponse(302)->withHeader('Location', '/console/account');
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $user));
    }
}
