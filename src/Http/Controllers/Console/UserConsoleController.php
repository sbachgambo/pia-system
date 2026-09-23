<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Security\TwoFactorService;
use App\Users\UserAdminRepository;
use App\Users\UserAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/users — admin/super_admin only (route-gated, see AppFactory).
 * Console-side CRUD for office/inspector accounts, which previously had to be
 * created directly against the database (DEV_NOTES gap).
 */
final class UserConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly UserAdminService $users,
        private readonly UserAdminRepository $userRepo,
        private readonly AuditLog $audit,
        private readonly TwoFactorService $twoFactor,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['role' => $q['role'] ?? null, 'status' => $q['status'] ?? null, 'q' => $q['q'] ?? null];
        $list = $this->users->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/users/index', [
            'list'    => $list,
            'filters' => $filters,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/users/form', [
            'mode'   => 'create',
            'user'   => null,
            'old'    => [],
            'errors' => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $user = $this->users->create(Input::fromRequest($request));
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/users/form', [
                'mode'   => 'create',
                'user'   => null,
                'old'    => $body,
                'errors' => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->record($request, 'user.create', $user['uuid'], null, $this->snapshot($user));
        $this->flash($request, 'success', "User “{$user['full_name']}” created.");

        return $this->redirect($response, '/console/users/' . $user['uuid'] . '/edit');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->users->get((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'User not found.');
            return $this->redirect($response, '/console/users');
        }

        return $this->render($request, $response, 'console/users/form', [
            'mode'   => 'edit',
            'user'   => $user,
            'old'    => $user,
            'errors' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $before = $this->users->get($uuid);
            if (($actor['uuid'] ?? null) === $uuid && isset($body['role']) && $body['role'] !== $before['role']) {
                throw new ApiException(422, 'validation_failed', 'You cannot change your own role — ask another admin.', ['field' => 'role']);
            }
            $user = $this->users->update($uuid, Input::fromRequest($request));
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'User not found.');
            return $this->redirect($response, '/console/users');
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/users/form', [
                'mode'   => 'edit',
                'user'   => ['uuid' => $uuid] + $body,
                'old'    => $body,
                'errors' => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->record($request, 'user.update', $user['uuid'], $this->snapshot($before), $this->snapshot($user));
        $this->flash($request, 'success', 'User updated.');

        return $this->redirect($response, '/console/users/' . $user['uuid'] . '/edit');
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            // requiredString() trims and rejects empty/whitespace-only input —
            // same validation the create-user password field gets via
            // UserAdminService::create(), so both entry points hold a new
            // password to the same bar (an all-whitespace password would
            // otherwise pass a raw strlen() check).
            $password = Input::fromRequest($request)->requiredString('password', 100);
            $this->users->resetPassword($uuid, $password);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'User not found.');
            return $this->redirect($response, '/console/users');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect($response, '/console/users/' . $uuid . '/edit');
        }

        $this->record($request, 'user.reset_password', $uuid, null, ['sessions_revoked' => true]);
        $this->flash($request, 'success', 'Password reset and every active session ended. Share the new password out of band.');

        return $this->redirect($response, '/console/users/' . $uuid . '/edit');
    }

    public function toggleStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        if (($actor['uuid'] ?? null) === $uuid) {
            $this->flash($request, 'error', 'You cannot suspend your own account.');
            return $this->redirect($response, '/console/users');
        }

        try {
            $current = $this->users->get($uuid);
            $next = $current['status'] === 'active' ? 'suspended' : 'active';
            $this->users->setStatus($uuid, $next);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'User not found.');
            return $this->redirect($response, '/console/users');
        }

        $this->record($request, 'user.' . ($next === 'suspended' ? 'suspend' : 'activate'), $uuid, ['status' => $current['status']], ['status' => $next]);
        $this->flash($request, 'success', $next === 'suspended' ? 'User suspended and signed out everywhere.' : 'User re-activated.');

        return $this->redirect($response, '/console/users');
    }

    /**
     * A person lost the phone with their authenticator: switch their 2FA off (they
     * can enrol again) and end every live session, since whoever holds that phone
     * may also hold the password. Not available on your own account — use My account.
     */
    public function resetTwoFactor(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        if (($actor['uuid'] ?? null) === $uuid) {
            $this->flash($request, 'error', 'Manage your own two-factor from My account.');

            return $this->redirect($response, '/console/users/' . $uuid . '/edit');
        }

        $id = $this->userRepo->findIdByUuid($uuid);
        if ($id === null) {
            $this->flash($request, 'error', 'User not found.');

            return $this->redirect($response, '/console/users');
        }

        $this->twoFactor->disable($id);
        $this->users->signOutEverywhere($uuid);
        $this->record($request, '2fa.admin_reset', $uuid, null, ['sessions_revoked' => true]);
        $this->flash($request, 'success', 'Two-factor reset and every session ended. They can set it up again after signing in.');

        return $this->redirect($response, '/console/users/' . $uuid . '/edit');
    }

    public function signOutEverywhere(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            $this->users->signOutEverywhere($uuid);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'User not found.');
            return $this->redirect($response, '/console/users');
        }

        $this->record($request, 'user.sign_out_everywhere', $uuid, null, null);
        $this->flash($request, 'success', 'Every active session for this user has been ended.');

        return $this->redirect($response, '/console/users/' . $uuid . '/edit');
    }

    /**
     * Audit entry for a user-management action. Only non-secret profile fields
     * are ever put in before/after — never hashes.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    private function record(ServerRequestInterface $request, string $action, string $targetUuid, ?array $before, ?array $after): void
    {
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $targetId = $this->userRepo->findIdByUuid($targetUuid);
        if ($targetId === null) {
            return;
        }

        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            $action,
            'user',
            $targetId,
            $before,
            $after,
            ClientContext::ip($request),
        );
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function snapshot(array $user): array
    {
        return array_intersect_key($user, array_flip(['full_name', 'email', 'phone', 'role', 'zone', 'status']));
    }

    /** @return array<string,string> field => message (or _ => message) */
    private function errorsFrom(ApiException $e): array
    {
        $field = $e->getDetails()['field'] ?? '_';

        return [(string) $field => $e->getMessage()];
    }
}
