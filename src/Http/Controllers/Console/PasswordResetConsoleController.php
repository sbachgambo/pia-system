<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Auth\PasswordResetService;
use App\Http\Support\ClientContext;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/forgot-password and /console/reset-password — public (no sign-in),
 * CSRF-protected, and rate-limited on the POSTs. Available only when outgoing
 * email is configured; otherwise everything bounces back to the login page.
 */
final class PasswordResetConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly PasswordResetService $resets)
    {
        parent::__construct($view);
    }

    public function showForgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->resets->isAvailable()) {
            return $this->redirect($response, '/console/login');
        }

        return $this->render($request, $response, 'console/forgot', []);
    }

    public function sendLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';

        if ($email !== '') {
            $this->resets->request($email, ClientContext::ip($request));
        }

        // Same answer whether or not the address exists.
        $this->flash($request, 'success', 'If that address belongs to an active account, we have emailed a reset link. It expires in ' . PasswordResetService::TTL_MINUTES . ' minutes.');

        return $this->redirect($response, '/console/login');
    }

    public function showReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = (string) (Query::params($request)['token'] ?? '');

        if ($this->resets->userForToken($token) === null) {
            $this->flash($request, 'error', 'This reset link is invalid or has expired. Request a new one.');
            return $this->redirect($response, $this->resets->isAvailable() ? '/console/forgot-password' : '/console/login');
        }

        return $this->noReferrer($this->render($request, $response, 'console/reset', ['token' => $token, 'error' => null]));
    }

    public function doReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirm = is_string($body['password_confirm'] ?? null) ? $body['password_confirm'] : '';

        try {
            if (!hash_equals($password, $confirm)) {
                throw new ApiException(422, 'validation_failed', 'The two passwords do not match.', ['field' => 'password']);
            }
            $this->resets->complete($token, $password, ClientContext::ip($request));
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'invalid_token') {
                $this->flash($request, 'error', $e->getMessage());
                return $this->redirect($response, $this->resets->isAvailable() ? '/console/forgot-password' : '/console/login');
            }

            return $this->noReferrer($this->render($request, $response, 'console/reset', [
                'token' => $token,
                'error' => $e->getMessage(),
            ], status: 422));
        }

        $this->flash($request, 'success', 'Password updated — you can sign in with it now. Any other sessions were signed out.');

        return $this->redirect($response, '/console/login');
    }

    /** The reset token lives in the URL, so keep it out of Referer headers and caches. */
    private function noReferrer(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Referrer-Policy', 'no-referrer')->withHeader('Cache-Control', 'no-store');
    }
}
