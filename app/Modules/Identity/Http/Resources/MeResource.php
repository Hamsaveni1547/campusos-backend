<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Identity\Http\Middleware\EnsureAccountReady;
use App\Modules\Identity\Services\MfaRequirement;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Institution\Http\Resources\InstitutionSummaryResource;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user with everything the frontend needs to build navigation.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentInstitution = app(CurrentInstitution::class);
        $institutionId = $currentInstitution->id();
        $pending = EnsureAccountReady::pendingRequirement($this->resource, $institutionId, app(PasswordPolicy::class), app(MfaRequirement::class));
        $mfaRequired = app(MfaRequirement::class)->isRequired($this->resource, $institutionId);

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'username' => $this->username,
            'status' => $this->status->value,
            'is_platform_admin' => $this->is_platform_admin,
            'email_verified' => $this->email_verified_at !== null,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'avatar_file_id' => $this->avatar?->ulid,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'mfa' => [
                'enabled' => $this->hasMfaEnabled(),
                'required' => $mfaRequired,
                'methods' => $this->mfaMethods()->confirmed()->pluck('type')->map(fn ($type) => $type->value)->values(),
            ],
            'pending_requirement' => $pending['code'] ?? null,
            'institution' => $currentInstitution->has() ? new InstitutionSummaryResource($currentInstitution->get()) : null,
            'institutions' => InstitutionSummaryResource::collection(
                $this->institutions()->wherePivot('status', 'active')->orderBy('name')->get()
            ),
            'roles' => $institutionId === null ? [] : $this->getRoleNames()->values(),
            'permissions' => $institutionId === null ? [] : $this->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
