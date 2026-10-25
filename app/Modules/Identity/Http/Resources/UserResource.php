<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User as seen by administrators of the current institution.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'username' => $this->username,
            'status' => $this->status->value,
            'membership_status' => $this->resource->getAttribute('membership_status') ?? $this->membershipStatus(),
            'email_verified' => $this->email_verified_at !== null,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'mfa_enabled' => $this->whenLoaded('mfaMethods', fn (): bool => $this->mfaMethods->whereNotNull('confirmed_at')->isNotEmpty()),
            'is_locked' => $this->isLocked(),
            'locked_until' => $this->locked_until?->toIso8601String(),
            'must_change_password' => $this->must_change_password,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'role_assignments' => RoleAssignmentResource::collection($this->whenLoaded('roleAssignments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
