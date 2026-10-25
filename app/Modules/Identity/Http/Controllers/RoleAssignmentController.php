<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Actions\Roles\RevokeRoleAssignment;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Http\Resources\RoleAssignmentResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Models\Department;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Roles & permissions
 */
class RoleAssignmentController extends Controller
{
    public function __construct(private CurrentInstitution $currentInstitution) {}

    /**
     * Role assignments. Filters: `filter[role]` (role id), `filter[user]` (user id), `filter[status]` (active|revoked|expired|scheduled).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(IdentityPermissions::RoleAssignmentsView);

        $assignments = QueryBuilder::for(RoleAssignment::query()->with(['role', 'user']))
            ->allowedFilters(...[
                AllowedFilter::callback('role', fn (Builder $query, $value) => $query->whereHas('role', fn (Builder $query) => $query->where('ulid', $value))),
                AllowedFilter::callback('user', fn (Builder $query, $value) => $query->whereHas('user', fn (Builder $query) => $query->where('ulid', $value))),
                AllowedFilter::callback('department', fn (Builder $query, $value) => $query->whereIn('department_id', Department::query()->where('ulid', $value)->select('id'))),
                AllowedFilter::callback('status', function ($query, $value): void {
                    match ($value) {
                        'active' => $query->scopes(['effective']),
                        'revoked' => $query->whereNotNull('revoked_at'),
                        'expired' => $query->whereNull('revoked_at')->where('valid_until', '<=', now()),
                        'scheduled' => $query->whereNull('revoked_at')->where('valid_from', '>', now()),
                        default => null,
                    };
                }),
            ])
            ->allowedSorts('created_at', 'valid_until')
            ->defaultSort('-created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        return RoleAssignmentResource::collection($assignments);
    }

    /**
     * Assign a role to a user.
     */
    public function store(Request $request, User $user, AssignRole $assignRole): JsonResponse
    {
        Gate::authorize(IdentityPermissions::RoleAssignmentsManage);

        $validated = $request->validate([
            'role_id' => ['required', 'string', 'size:26'],
            /** Limits the role to one department (only for department-scoped roles such as HOD). */
            'department_id' => ['nullable', 'string', 'size:26'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $role = Role::query()->availableTo($this->currentInstitution->require()->id)->where('ulid', $validated['role_id'])->first();

        if ($role === null) {
            throw new BusinessRuleException('Role not found.', 'ROLE_NOT_FOUND', 422);
        }

        $assignment = $assignRole->handle(
            $user,
            $role,
            $request->user(),
            isset($validated['department_id']) ? (Department::query()->where('ulid', $validated['department_id'])->value('id') ?? throw new BusinessRuleException('Department not found.', 'DEPARTMENT_INVALID', 422)) : null,
            isset($validated['valid_from']) ? Carbon::parse($validated['valid_from']) : null,
            isset($validated['valid_until']) ? Carbon::parse($validated['valid_until']) : null,
            $validated['reason'] ?? null,
        );

        return (new RoleAssignmentResource($assignment->load('user')))->response()->setStatusCode(201);
    }

    /**
     * Revoke a role assignment.
     */
    public function destroy(Request $request, RoleAssignment $assignment, RevokeRoleAssignment $revoke): RoleAssignmentResource
    {
        Gate::authorize(IdentityPermissions::RoleAssignmentsManage);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new RoleAssignmentResource($revoke->handle($assignment->load(['role', 'user']), $request->user(), $validated['reason'] ?? null)->load(['role', 'user']));
    }
}
