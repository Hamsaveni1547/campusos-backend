<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Support\SecuritySettings;

/**
 * Decides whether a user must use two-step verification.
 * Platform administrators always must; others depend on their roles and the
 * institution's "mfa_required_roles" setting.
 */
class MfaRequirement
{
    public function __construct(private SecuritySettings $settings) {}

    public function isRequired(User $user, ?int $institutionId): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        if ($institutionId === null) {
            return false;
        }

        $requiredRoles = $this->settings->mfaRequiredRoles($institutionId);

        if ($requiredRoles === []) {
            return false;
        }

        return RoleAssignment::query()
            ->withoutGlobalScopes()
            ->effective()
            ->where('institution_id', $institutionId)
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($query) => $query->whereIn('name', $requiredRoles))
            ->exists();
    }

    public function needsEnrollment(User $user, ?int $institutionId): bool
    {
        return $this->isRequired($user, $institutionId) && ! $user->hasMfaEnabled();
    }
}
