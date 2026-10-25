<?php

namespace App\Modules\Identity\Http\Resources;

use App\Shared\Audit\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SecurityEvent
 */
class SecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'event' => $this->event->value,
            'severity' => $this->severity,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->ulid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->ulid,
                'name' => $this->actor->name,
            ]),
            'identifier' => $this->identifier,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'context' => $this->context,
            'trace_id' => $this->trace_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
