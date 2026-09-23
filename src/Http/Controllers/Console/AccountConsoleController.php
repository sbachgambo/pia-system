<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Auth\PasswordHasher;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Input;
use App\Http\View\Renderer;
use App\Security\QrCode;
use App\Security\TwoFactorPolicy;
use App\Security\TwoFactorService;
use App\Support\ApiException;
use App\Users\UserAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/account — "My account": the signed-in person's own security — change
 * password and manage two-factor. Open to every console role; anything that
 * weakens or replaces a credential (disable 2FA, new recovery codes, new
 * password) re-asks for the current password, so a left-open session can't be
 * used to quietly take the account over.
 */
final class AccountConsoleController extends AbstractConsoleController
{
    private const PENDING_SECRET = 'totp_pending_secret';

    public function __construct(
        Renderer $view,
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorPolicy $policy,
        private readonly PasswordHasher $hasher,
        private readonly UserAdminService $users,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->overview($request, $response);
    }

    public function startTwoFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->me($request);
        if ($this->twoFactor->isEnabled((int) $user['id'])) {
            return $this->redirect($response, '/console/account');
        }

        $this->session($request)->set(self::PENDING_SECRET, $this->twoFactor->newSecret());

        return $this->redirect($response, '/console/account/two-factor/setup');
    }

    public function setup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->setupPage($request, $response, null) ?? $this->redirect($response, '/console/account');
    }

    public function confirmTwoFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->me($request);
        $secret = $this->session($request)->get(self::PENDING_SECRET);
        if (!is_string($secret)) {
            return $this->redirect($response, '/console/account');
        }

        $code = (string) (((array) $request->getParsedBody())['code'] ?? '');

        try {
            $codes = $this->twoFactor->enable((int) $user['id'], $secret, $code);
        } catch (ApiException $e) {
            return $this->setupPage($request, $response, $e->getMessage(), 422) ?? $this->redirect($response, '/console/account');
        }

        $this->session($request)->remove(self::PENDING_SECRET);
        $this->record($request, '2fa.enable', $user);

        return $this->recoveryPage($request, $response, $codes, 'Two-factor authentication is now on.');
    }

    public function regenerateRecoveryCodes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->me($request);

        if (!$this->twoFactor->isEnabled((int) $user['id']) || !$this->passwordOk($request, $user)) {
            $this->flash($request, 'error', 'Your current password is required, and two-factor must be on.');
            return $this->redirect($response, '/console/account');
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes((int) $user['id']);
        $this->record($request, '2fa.recovery_codes_regenerated', $user);

        return $this->recoveryPage($request, $response, $codes, 'New recovery codes generated — the old ones no longer work.');
    }

    public function disableTwoFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->me($request);

        if ($this->policy->requiredForRole($user['role'] ?? null)) {
            $this->flash($request, 'error', 'Two-factor is required for your role by the administrator and cannot be turned off.');
            return $this->redirect($response, '/console/account');
        }
        if (!$this->passwordOk($request, $user)) {
            $this->flash($request, 'error', 'Your current password is required to turn two-factor off.');
            return $this->redirect($response, '/console/account');
        }

        $this->twoFactor->disable((int) $user['id']);
        $this->record($request, '2fa.disable', $user);
        $this->flash($request, 'success', 'Two-factor authentication is off.');

        return $this->redirect($response, '/console/account');
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->me($request);
        $body = (array) $request->getParsedBody();

        if (!$this->passwordOk($request, $user, 'current_password')) {
            return $this->overview($request, $response, 'Your current password is not right.', 422);
        }

        try {
            $new = Input::fromRequest($request)->requiredString('new_password', 100);
            if (!hash_equals($new, (string) ($body['new_password_confirm'] ?? ''))) {
                throw new ApiException(422, 'validation_failed', 'The two new passwords do not match.');
            }
            // Also ends every session, including this one…
            $this->users->resetPassword((string) $user['uuid'], $new);
        } catch (ApiException $e) {
            return $this->overview($request, $response, $e->getMessage(), 422);
        }

        // …so re-stamp this session as starting now: the person changing their
        // own password stays signed in, everything else is signed out.
        $this->session($request)->set(ConsoleAuthMiddleware::LOGIN_AT_KEY, time());
        $this->record($request, 'user.change_password_self', $user);
        $this->flash($request, 'success', 'Password changed. Any other devices were signed out.');

        return $this->redirect($response, '/console/account');
    }

    private function overview(ServerRequestInterface $request, ResponseInterface $response, ?string $passwordError = null, int $status = 200): ResponseInterface
    {
        $user = $this->me($request);
        $id = (int) $user['id'];

        return $this->render($request, $response, 'console/account/index', [
            'twoFactorOn'    => $this->twoFactor->isEnabled($id),
            'recoveryLeft'   => $this->twoFactor->isEnabled($id) ? $this->twoFactor->remainingRecoveryCodes($id) : 0,
            'twoFactorRequired' => $this->policy->requiredForRole($user['role'] ?? null),
            'mustEnrol'      => $this->policy->mustEnrol($user),
            'passwordError'  => $passwordError,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    private function setupPage(ServerRequestInterface $request, ResponseInterface $response, ?string $error, int $status = 200): ?ResponseInterface
    {
        $user = $this->me($request);
        $secret = $this->session($request)->get(self::PENDING_SECRET);
        if (!is_string($secret)) {
            return null;
        }

        return $this->render($request, $response, 'console/account/two_factor_setup', [
            'qr'     => QrCode::svg($this->twoFactor->provisioningUri($secret, (string) $user['email'])),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error'  => $error,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param list<string> $codes */
    private function recoveryPage(ServerRequestInterface $request, ResponseInterface $response, array $codes, string $message): ResponseInterface
    {
        // Rendered directly in the POST response (never redirected through the
        // session or a flash) and marked no-store: these are shown exactly once.
        return $this->render($request, $response, 'console/account/recovery_codes', [
            'codes'   => $codes,
            'message' => $message,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $user */
    private function passwordOk(ServerRequestInterface $request, array $user, string $field = 'password'): bool
    {
        $given = ((array) $request->getParsedBody())[$field] ?? '';

        return is_string($given) && $given !== '' && $this->hasher->verify($given, (string) ($user['password_hash'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function me(ServerRequestInterface $request): array
    {
        return (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
    }

    /** @param array<string,mixed> $user */
    private function record(ServerRequestInterface $request, string $action, array $user): void
    {
        $this->audit->record((int) $user['id'], $action, 'user', (int) $user['id'], null, null, ClientContext::ip($request));
    }
}
