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

    /**
     * The end user's IP. When the call comes from a trusted CampusOS frontend server,
     * the browser IP it forwards in X-Client-IP is used instead of the server's own.
     */
    public function ip(): ?string
    {
        if ($this->outsideRequest()) {
            return null;
        }

        $forwarded = request()->header('X-Client-IP');

        if ($this->fromTrustedFrontend() && is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return request()->ip();
    }

    /**
     * The end user's browser. Trusted frontends forward it in X-Client-User-Agent.
     */
    public function userAgent(): ?string
    {
        if ($this->outsideRequest()) {
            return null;
        }

        $forwarded = request()->header('X-Client-User-Agent');
        $userAgent = $this->fromTrustedFrontend() && is_string($forwarded) && $forwarded !== ''
            ? $forwarded
            : request()->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 500, '');
    }

    private function fromTrustedFrontend(): bool
    {
        $trusted = array_filter(array_map('trim', explode(',', (string) config('campusos.trusted_frontends'))));

        return in_array(request()->server('REMOTE_ADDR'), $trusted, true);
    }

    private function outsideRequest(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }
}
