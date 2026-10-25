<?php

namespace App\Shared\Audit;

use App\Models\User;
use App\Shared\Http\RequestContext;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\Auth;

/**
 * Writes security events. Never pass passwords, codes or tokens in context.
 */
class SecurityLogger
{
    public function __construct(
        private RequestContext $context,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        SecurityEventType $event,
        ?User $user = null,
        array $context = [],
        ?string $identifier = null,
        ?int $institutionId = null,
    ): SecurityEvent {
        $actorId = Auth::id();

        return SecurityEvent::query()->create([
            'institution_id' => $institutionId ?? $this->currentInstitution->id(),
            'user_id' => $user?->id,
            'actor_id' => $actorId !== $user?->id ? $actorId : null,
            'event' => $event,
            'severity' => $event->severity(),
            'identifier' => $identifier,
            'ip_address' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'trace_id' => $this->context->traceId(),
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
