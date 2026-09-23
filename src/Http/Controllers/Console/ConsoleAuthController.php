<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Console\ConsoleAuth;
use App\Http\Console\ConsoleLogin;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\View\Renderer;
use App\Mail\MailerInterface;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/login, /console/logout. These routes carry the session + CSRF
 * middleware but NOT the auth gate.
 */
final class ConsoleAuthController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ConsoleAuth $auth,
        private readonly MailerInterface $mailer,
        private readonly ConsoleLogin $login,
    ) {
        parent::__construct($view);
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (is_array($request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE))) {
            return $this->redirect($response, '/console');
        }

        // Already signed in? (attribute isn't set on these routes, so check session)
        if (is_string($this->session($request)->get(ConsoleAuthMiddleware::SESSION_KEY))) {
            return $this->redirect($response, '/console');
        }

        return $this->render($request, $response, 'console/login', ['email' => '', 'mail_enabled' => $this->mailer->isEnabled()]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        try {
            $user = $this->auth->attempt($email, $password);
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/login', [
                'mail_enabled' => $this->mailer->isEnabled(),
                'email' => $email,
                'error' => $e->getErrorCode() === 'invalid_credentials'
                    ? 'Email or password is incorrect.'
                    : $e->getMessage(),
            ], status: 422);
        }

        $session = $this->session($request);

        // Password correct, but this account has a second factor: NOT signed in
        // yet. Park the identity (briefly, with an attempt counter) and ask for
        // the code; only /console/two-factor can turn this into a session.
        if (!empty($user['totp_enabled_at'])) {
            $session->set(ConsoleLogin::PENDING_UUID, (string) $user['uuid']);
            $session->set(ConsoleLogin::PENDING_AT, time());
            $session->set(ConsoleLogin::PENDING_TRIES, 0);

            return $this->redirect($response, '/console/two-factor');
        }

        $next = $this->login->complete($session, $this->csrf($request), (string) $user['uuid']);
        $this->flash($request, 'success', 'Signed in.');

        return $this->redirect($response, $next);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);
        $session->remove(ConsoleAuthMiddleware::SESSION_KEY);
        $session->remove('_flash');
        session_regenerate_id(true);

        return $this->redirect($response, '/console/login');
    }
}
