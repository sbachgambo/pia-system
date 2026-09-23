<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Auth\UserRepository;
use App\Http\Console\ConsoleLogin;
use App\Http\Support\ClientContext;
use App\Http\View\Renderer;
use App\Security\TwoFactorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/two-factor — the second step of sign-in for accounts that have a
 * second factor. The user is NOT signed in until this succeeds: the password
 * step only parks their identity in the session for a few minutes, and this is
 * the only route that can turn that into a real session.
 *
 * Bounded three ways: the pending state expires (5 minutes), it dies after 5
 * wrong codes, and the POST also sits behind the per-IP sign-in rate limiter.
 */
final class TwoFactorChallengeController extends AbstractConsoleController
{
    private const PENDING_TTL = 300;
    private const MAX_TRIES = 5;

    public function __construct(
        Renderer $view,
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
        private readonly ConsoleLogin $login,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->pendingUser($request) === null) {
            return $this->redirect($response, '/console/login');
        }

        return $this->page($request, $response, null);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return $this->redirect($response, '/console/login');
        }

        $session = $this->session($request);
        $body = (array) $request->getParsedBody();
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $usedRecovery = preg_match('/^\d{6}$/', str_replace([' ', '-'], '', trim($code))) !== 1;

        if (!$this->twoFactor->verifyLogin((int) $user['id'], $code)) {
            $tries = (int) $session->get(ConsoleLogin::PENDING_TRIES, 0) + 1;
            $session->set(ConsoleLogin::PENDING_TRIES, $tries);

            if ($tries >= self::MAX_TRIES) {
                $this->clearPending($session);
                $this->flash($request, 'error', 'Too many wrong codes. Please sign in again.');

                return $this->redirect($response, '/console/login');
            }

            return $this->page($request, $response, 'That code is not right. Try the next code your app shows, or use a recovery code.', 422);
        }

        if ($usedRecovery) {
            $this->audit->record((int) $user['id'], '2fa.recovery_code_used', 'user', (int) $user['id'], null, null, ClientContext::ip($request));
        }

        $this->users->updateLastLogin((int) $user['id'], new \DateTimeImmutable());
        $next = $this->login->complete($session, $this->csrf($request), (string) $user['uuid']);
        $this->flash($request, 'success', 'Signed in.');
        if ($usedRecovery) {
            $left = $this->twoFactor->remainingRecoveryCodes((int) $user['id']);
            $this->flash($request, 'error', "You signed in with a recovery code — {$left} left. If you lost your phone, set up two-factor again from My account.");
        }

        return $this->redirect($response, $next);
    }

    /** @return array<string,mixed>|null the user awaiting their second factor, if the pending state is still valid */
    private function pendingUser(ServerRequestInterface $request): ?array
    {
        $session = $this->session($request);
        $uuid = $session->get(ConsoleLogin::PENDING_UUID);
        $at = (int) $session->get(ConsoleLogin::PENDING_AT, 0);

        if (!is_string($uuid) || $uuid === '' || time() - $at > self::PENDING_TTL) {
            $this->clearPending($session);

            return null;
        }

        $user = $this->users->findByUuid($uuid);
        if ($user === null || $user['status'] !== 'active' || empty($user['totp_enabled_at'])) {
            $this->clearPending($session);

            return null;
        }

        return $user;
    }

    private function clearPending(\App\Http\Support\SessionStore $session): void
    {
        $session->remove(ConsoleLogin::PENDING_UUID);
        $session->remove(ConsoleLogin::PENDING_AT);
        $session->remove(ConsoleLogin::PENDING_TRIES);
    }

    private function page(ServerRequestInterface $request, ResponseInterface $response, ?string $error, int $status = 200): ResponseInterface
    {
        return $this->render($request, $response, 'console/two_factor', ['error' => $error], $status)
            ->withHeader('Cache-Control', 'no-store');
    }
}
