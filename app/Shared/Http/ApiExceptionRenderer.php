<?php

namespace App\Shared\Http;

use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API error in the shared envelope:
 * `{ message, code, errors?, context?, trace_id }`.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $message, $extra] = $this->describe($exception);

        $payload = array_merge([
            'message' => $message,
            'code' => $code,
        ], $extra, [
            'trace_id' => app(RequestContext::class)->traceId(),
        ]);

        if ($status >= 500 && config('app.debug')) {
            $payload['debug'] = [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile().':'.$exception->getLine(),
            ];
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof BusinessRuleException => [
                $exception->status,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->context === [] ? [] : ['context' => $exception->context],
            ],
            $exception instanceof ValidationException => [
                422, 'VALIDATION_FAILED', $exception->getMessage(), ['errors' => $exception->errors()],
            ],
            $exception instanceof AuthenticationException => [
                401, 'UNAUTHENTICATED', 'You are not signed in or your session has expired.', [],
            ],
            $exception instanceof AuthorizationException,
            $exception instanceof UnauthorizedException,
            $exception instanceof AccessDeniedHttpException => [
                403, 'FORBIDDEN', 'You do not have permission to perform this action.', [],
            ],
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [
                404, 'NOT_FOUND', 'The requested resource was not found.', [],
            ],
            $exception instanceof ThrottleRequestsException => [
                429, 'TOO_MANY_REQUESTS', 'Too many requests. Please try again later.', [],
            ],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                'HTTP_'.$exception->getStatusCode(),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request could not be completed.',
                [],
            ],
            default => [500, 'SERVER_ERROR', 'Something went wrong on our side. Please try again.', []],
        };
    }
}
