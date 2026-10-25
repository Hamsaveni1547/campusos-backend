<?php

namespace App\Shared\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Institution\Models\Institution;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the institution for this request: X-Institution header (if the user is a
 * member), else the institution the token was issued for, else the default membership.
 */
class ResolveInstitution
{
    public function __construct(private CurrentInstitution $currentInstitution) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $institution = $this->resolve($request, $user);

        if ($institution === null && $mode === 'required' && ! $user->is_platform_admin) {
            throw new BusinessRuleException('Your account is not linked to an active institution.', 'NO_INSTITUTION', 403);
        }

        if ($institution !== null && ! $institution->isActive()) {
            throw new BusinessRuleException('Access for this institution is suspended.', 'INSTITUTION_SUSPENDED', 403);
        }

        $this->currentInstitution->set($institution);

        if ($institution === null && $mode === 'required' && $user->is_platform_admin) {
            throw new BusinessRuleException('Select an institution with the X-Institution header.', 'INSTITUTION_REQUIRED', 403);
        }

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Institution
    {
        $requested = $request->header('X-Institution');

        if (is_string($requested) && $requested !== '') {
            $institution = Institution::query()->where('ulid', $requested)->orWhere('code', strtoupper($requested))->first();

            if ($institution === null) {
                throw new BusinessRuleException('Institution not found.', 'INSTITUTION_NOT_FOUND', 404);
            }

            if (! $user->is_platform_admin && ! $user->belongsToInstitution($institution->id)) {
                throw new BusinessRuleException('You are not a member of this institution.', 'NOT_A_MEMBER', 403);
            }

            return $institution;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->institution_id !== null && $user->belongsToInstitution($token->institution_id)) {
            return Institution::query()->find($token->institution_id);
        }

        return $user->defaultInstitution();
    }
}
