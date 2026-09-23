<?php

declare(strict_types=1);

namespace App\Users;

use App\Auth\PasswordHasher;
use App\Auth\RefreshTokenService;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Office\NotFoundException;
use App\Support\ApiException;
use Ramsey\Uuid\Uuid;

/**
 * Business rules + validation for console-managed `users` (office staff
 * accounts, not the JWT/API auth path — that stays App\Auth\UserRepository).
 * Roles are the fixed 4-value enum from the brief; this is deliberately NOT a
 * generic permission system (see DEV_NOTES).
 */
final class UserAdminService
{
    public const ROLES = ['inspector', 'office_reviewer', 'admin', 'super_admin', 'board'];
    public const STATUSES = ['active', 'suspended'];
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly UserAdminRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RefreshTokenService $refreshTokens,
    ) {
    }

    /** @return array<string,mixed> */
    public function create(Input $in): array
    {
        $email = $in->requiredEmail('email');
        if ($this->users->existsWithEmail($email)) {
            throw new ApiException(422, 'validation_failed', 'A user with this email already exists.', ['field' => 'email']);
        }

        $password = $in->requiredString('password', 100);
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ApiException(
                422,
                'validation_failed',
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
                ['field' => 'password'],
            );
        }

        $data = [
            'full_name'     => $in->requiredString('full_name', 150),
            'email'         => $email,
            'phone'         => $in->optionalString('phone', 20),
            'password_hash' => $this->hasher->hash($password),
            'role'          => $in->requiredEnum('role', self::ROLES),
            'zone'          => $in->optionalString('zone', 100),
            'status'        => $in->optionalEnum('status', self::STATUSES) ?? 'active',
        ];

        return UserRepresentation::admin($this->users->create(Uuid::uuid4()->toString(), $data));
    }

    /** @return array<string,mixed> */
    public function update(string $uuid, Input $in): array
    {
        $id = $this->users->findIdByUuid($uuid) ?? throw new NotFoundException('User');

        $data = [];
        if ($in->has('full_name')) { $data['full_name'] = $in->requiredString('full_name', 150); }
        if ($in->has('email')) {
            $email = $in->requiredEmail('email');
            if ($this->users->existsWithEmail($email, $id)) {
                throw new ApiException(422, 'validation_failed', 'A user with this email already exists.', ['field' => 'email']);
            }
            $data['email'] = $email;
        }
        if ($in->has('phone')) { $data['phone'] = $in->optionalString('phone', 20); }
        if ($in->has('role')) { $data['role'] = $in->requiredEnum('role', self::ROLES); }
        if ($in->has('zone')) { $data['zone'] = $in->optionalString('zone', 100); }
        if ($in->has('status')) { $data['status'] = $in->requiredEnum('status', self::STATUSES); }
        if ($in->has('receive_digest')) {
            // The form posts a hidden "0" before the checkbox's "1".
            $data['receive_digest'] = in_array($in->raw('receive_digest'), [1, '1', true, 'on'], true) ? 1 : 0;
        }

        return UserRepresentation::admin($this->users->update($id, $data));
    }

    public function resetPassword(string $uuid, string $newPassword): void
    {
        $id = $this->users->findIdByUuid($uuid) ?? throw new NotFoundException('User');

        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new ApiException(
                422,
                'validation_failed',
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
                ['field' => 'password'],
            );
        }

        $this->users->updatePasswordHash($id, $this->hasher->hash($newPassword));

        // A reset usually means the old password may be compromised: end every
        // session that was opened with it.
        $this->endAllSessions($id);
    }

    /** Kill the user's console sessions and API/PWA refresh tokens. */
    public function signOutEverywhere(string $uuid): void
    {
        $this->endAllSessions($this->users->findIdByUuid($uuid) ?? throw new NotFoundException('User'));
    }

    private function endAllSessions(int $id): void
    {
        $this->users->revokeSessions($id);
        $this->refreshTokens->revokeAllForUser($id);
    }

    public function setStatus(string $uuid, string $status): array
    {
        $id = $this->users->findIdByUuid($uuid) ?? throw new NotFoundException('User');

        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException(422, 'validation_failed', 'Invalid status.', ['field' => 'status']);
        }

        $row = $this->users->update($id, ['status' => $status]);
        if ($status === 'suspended') {
            $this->endAllSessions($id);
        }

        return UserRepresentation::admin($row);
    }

    /** @return array<string,mixed> */
    public function get(string $uuid): array
    {
        $row = $this->users->findByUuid($uuid) ?? throw new NotFoundException('User');

        return UserRepresentation::admin($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $result = $this->users->paginate($page->perPage, $page->offset(), $filters);

        return $page->envelope(
            array_map([UserRepresentation::class, 'admin'], $result['rows']),
            $result['total'],
        );
    }
}
