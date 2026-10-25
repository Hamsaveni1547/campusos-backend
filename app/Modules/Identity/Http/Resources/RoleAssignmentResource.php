<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoleAssignment
 */
class RoleAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status(),
            'role' => $this->whenLoaded('role', fn (): array => [
                'id' => $this->role->ulid,
                'name' => $this->role->name,
                'display_name' => $this->role->label(),
                'is_system' => $this->role->is_system,
            ]),
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->ulid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'department' => $this->department === null ? null : ['id' => $this->department->ulid, 'code' => $this->department->code, 'name' => $this->department->name],
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'reason' => $this->reason,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
