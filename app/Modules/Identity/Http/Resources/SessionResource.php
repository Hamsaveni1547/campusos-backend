<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signed-in device (API token).
 *
 * @mixin PersonalAccessToken
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => (string) $this->id,
            'device_name' => $this->name,
            'ip_address' => $this->ip_address,
            'last_used_ip' => $this->last_used_ip,
            'user_agent' => $this->user_agent,
            'is_current' => $current instanceof PersonalAccessToken && $current->getKey() === $this->id,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
