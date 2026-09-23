<?php

declare(strict_types=1);

namespace App\Security;

use App\Settings\OperationalSettingsRepository;

/**
 * Is two-factor *mandatory* for this person? A setting an admin controls
 * (Settings → Security): when on, admins and super admins who haven't enrolled
 * are confined to the account-security page until they do. Anyone may enrol
 * voluntarily regardless of the setting.
 */
final class TwoFactorPolicy
{
    public const ROLES = ['admin', 'super_admin'];

    public function __construct(private readonly OperationalSettingsRepository $settings)
    {
    }

    public function requiredForRole(?string $role): bool
    {
        return in_array($role, self::ROLES, true) && $this->settings->get()['require_2fa_admins'];
    }

    /** @param array<string,mixed> $user a users row including totp_enabled_at */
    public function mustEnrol(array $user): bool
    {
        return empty($user['totp_enabled_at']) && $this->requiredForRole($user['role'] ?? null);
    }
}
