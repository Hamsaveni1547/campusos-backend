<?php

namespace App\Shared\Audit;

use App\Shared\Http\RequestContext;
use App\Shared\Tenancy\CurrentInstitution;
use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Append-only audit record. Adds institution, IP, user agent and trace id
 * to every activity and blocks edits and deletes.
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $context = app(RequestContext::class);

            $activity->institution_id ??= app(CurrentInstitution::class)->id();
            $activity->ip_address ??= $context->ip();
            $activity->user_agent ??= $context->userAgent();
            $activity->trace_id ??= $context->traceId();
        });

        static::updating(function (): void {
            throw new LogicException('Audit records cannot be modified.');
        });

        static::deleting(function (): void {
            if (! app()->runningInConsole()) {
                throw new LogicException('Audit records cannot be deleted.');
            }
        });
    }
}
