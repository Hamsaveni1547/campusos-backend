<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Http\RequestContext;
use App\Shared\Tenancy\CurrentInstitution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an incoming X-Trace-Id (from the frontend) or creates one, shares it
 * with logs and audit records, and returns it on the response.
 */
class AssignTraceId
{
    public function __construct(
        private RequestContext $context,
        private CurrentInstitution $currentInstitution,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->reset();
        $this->currentInstitution->set(null);

        $incoming = $request->header('X-Trace-Id');

        if (is_string($incoming) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1) {
            $this->context->setTraceId($incoming);
        }

        Log::shareContext(['trace_id' => $this->context->traceId()]);

        $response = $next($request);
        $response->headers->set('X-Trace-Id', $this->context->traceId());

        return $response;
    }
}
