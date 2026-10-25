<?php

namespace App\Shared\Http;

use Illuminate\Support\Str;

/**
 * Request metadata (trace id, IP, user agent) shared with audit logging.
 * Works outside HTTP too (queues, console) by returning null values.
 */
class RequestContext
{
    private ?string $traceId = null;

    public function traceId(): string
    {
        return $this->traceId ??= (string) Str::uuid();
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * Start a fresh context (new trace id) for a new request or job.
     */
    public function reset(): void
    {
        $this->traceId = null;
    }

    public function ip(): ?string
    {
        return app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip();
    }

    public function userAgent(): ?string
    {
        $userAgent = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 500, '');
    }
}
