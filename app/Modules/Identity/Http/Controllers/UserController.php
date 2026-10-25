<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\Users\AdminSecurityActions;
use App\Modules\Identity\Actions\Users\ChangeMembershipStatus;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Actions\Users\UpdateUser;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Resources\SecurityEventResource;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\Role;
use App\Shared\Audit\SecurityEvent;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Users
 */
class UserController extends Controller
{
    public function __construct(private CurrentInstitution $currentInstitution) {}

    /**
     * List users of the institution.
     *
     * Filters: `filter[search]`, `filter[status]` (membership: active|inactive),
     * `filter[account_status]`, `filter[role]` (role name), `filter[locked]`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(IdentityPermissions::UsersView);
        $institutionId = $this->currentInstitution->require()->id;

        $users = QueryBuilder::for(
            User::query()
                ->select('users.*')
                ->join('institution_user', function ($join) use ($institutionId): void {
                    $join->on('institution_user.user_id', '=', 'users.id')->where('institution_user.institution_id', $institutionId);
                })
                ->addSelect(['institution_user.status as membership_status'])
        )
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, $value): void {
                    $term = '%'.addcslashes((string) $value, '%_').'%';
                    $query->where(fn (Builder $query) => $query->where('users.name', 'like', $term)
                        ->orWhere('users.email', 'like', $term)
                        ->orWhere('users.mobile', 'like', $term)
                        ->orWhere('users.username', 'like', $term));
                }),
                AllowedFilter::callback('status', fn (Builder $query, $value) => $query->where('institution_user.status', $value)),
                AllowedFilter::exact('account_status', 'users.status'),
                AllowedFilter::callback('role', function (Builder $query, $value) use ($institutionId): void {
                    $query->whereHas('roleAssignments', fn ($query) => $query->withoutGlobalScopes()
                        ->scopes(['effective'])
                        ->where('institution_id', $institutionId)
                        ->whereHas('role', fn (Builder $query) => $query->whereIn('name', (array) $value)));
                }),
                AllowedFilter::callback('locked', fn (Builder $query, $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? $query->where('users.locked_until', '>', now())
                    : $query->where(fn (Builder $query) => $query->whereNull('users.locked_until')->orWhere('users.locked_until', '<=', now()))),
            ])
            ->allowedSorts('name', 'email', 'created_at', 'last_login_at')
            ->defaultSort('name')
            ->with(['mfaMethods', 'roleAssignments' => fn ($query) => $query->effective()->with('role')])
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Create a user (or link an existing person) and optionally assign roles.
     */
    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $institutionId = $this->currentInstitution->require()->id;

        $roles = collect($request->input('roles', []))->map(function (string $ulid) use ($institutionId, $request): Role {
            $role = Role::query()->availableTo($institutionId)->where('ulid', $ulid)->first();

            if ($role === null) {
                throw new BusinessRuleException('One of the selected roles was not found.', 'ROLE_NOT_FOUND', 422);
            }

            if (! $request->user()->can(IdentityPermissions::RoleAssignmentsManage)) {
                throw new BusinessRuleException('You do not have permission to assign roles.', 'FORBIDDEN', 403);
            }

            return $role;
        })->all();

        $result = $createUser->handle(
            $request->safe()->only(['name', 'email', 'mobile', 'username', 'locale', 'timezone']),
            $request->user(),
            $roles,
            $request->boolean('send_invite', true),
            $request->input('password'),
        );

        $user = $result['user']->load(['mfaMethods', 'roleAssignments' => fn ($query) => $query->effective()->with('role')]);

        return (new UserResource($user))
            ->additional(['meta' => [
                'temporary_password' => $result['temporary_password'],
                'linked_existing_account' => $result['linked_existing'],
            ]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a user.
     */
    public function show(User $user): UserResource
    {
        Gate::authorize(IdentityPermissions::UsersView);

        return new UserResource($user->load(['mfaMethods', 'roleAssignments' => fn ($query) => $query->with('role')->latest()]));
    }

    /**
     * Update a user.
     */
    public function update(Request $request, User $user, UpdateUser $updateUser): UserResource
    {
        Gate::authorize(IdentityPermissions::UsersUpdate);
        $this->guardPlatformAdmin($request->user(), $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/', Rule::unique('users', 'mobile')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'alpha_dash', 'min:3', 'max:64', Rule::unique('users', 'username')->ignore($user->id)],
            'locale' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ]);

        return new UserResource($updateUser->handle($user, $validated)->load('mfaMethods'));
    }

    /**
     * Deactivate the user's access to this institution.
     */
    public function deactivate(Request $request, User $user, ChangeMembershipStatus $action): UserResource
    {
        Gate::authorize(IdentityPermissions::UsersDeactivate);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new UserResource($action->deactivate($user, $request->user(), $validated['reason']));
    }

    /**
     * Re-activate the user's access to this institution.
     */
    public function activate(Request $request, User $user, ChangeMembershipStatus $action): UserResource
    {
        Gate::authorize(IdentityPermissions::UsersDeactivate);

        return new UserResource($action->activate($user, $request->user()));
    }

    /**
     * Unlock an account locked by failed sign-ins.
     */
    public function unlock(User $user, AdminSecurityActions $actions): UserResource
    {
        Gate::authorize(IdentityPermissions::UsersUnlock);

        return new UserResource($actions->unlock($user));
    }

    /**
     * Reset a user's password: email a link (default) or return a temporary password once.
     */
    public function resetPassword(Request $request, User $user, AdminSecurityActions $actions): JsonResponse
    {
        Gate::authorize(IdentityPermissions::UsersResetPassword);
        $validated = $request->validate(['mode' => ['sometimes', Rule::in(['link', 'temporary_password'])]]);

        $result = $actions->resetPassword($user, $request->user(), ($validated['mode'] ?? 'link') === 'temporary_password');

        return response()->json([
            'message' => $result['temporary_password'] === null ? 'A password reset email has been sent.' : 'A temporary password has been set. The user must change it at next sign-in.',
            'data' => ['temporary_password' => $result['temporary_password']],
        ]);
    }

    /**
     * Remove all two-step verification methods (lost phone).
     */
    public function resetMfa(Request $request, User $user, AdminSecurityActions $actions): JsonResponse
    {
        Gate::authorize(IdentityPermissions::UsersResetPassword);
        $actions->resetMfa($user, $request->user());

        return response()->json(['message' => 'Two-step verification has been reset. The user will set it up again at next sign-in.']);
    }

    /**
     * Resend the invitation email to a user who has not set a password yet.
     */
    public function resendInvitation(User $user, CreateUser $createUser): JsonResponse
    {
        Gate::authorize(IdentityPermissions::UsersCreate);

        if ($user->status !== UserStatus::Invited) {
            throw new BusinessRuleException('This user has already set up the account.', 'ACCOUNT_ALREADY_SET_UP', 409);
        }

        $createUser->sendInvitation($user);

        return response()->json(['message' => 'The invitation has been sent again.']);
    }

    /**
     * Security events of a user in this institution.
     */
    public function securityEvents(Request $request, User $user): AnonymousResourceCollection
    {
        Gate::authorize(IdentityPermissions::SecurityEventsView);

        return SecurityEventResource::collection(
            SecurityEvent::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->where('institution_id', $this->currentInstitution->id())->orWhereNull('institution_id'))
                ->with('actor')
                ->latest('id')
                ->cursorPaginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    private function guardPlatformAdmin(User $actor, User $target): void
    {
        if ($target->is_platform_admin && ! $actor->is_platform_admin) {
            throw new BusinessRuleException('Platform administrators can only be managed at platform level.', 'PLATFORM_ADMIN_PROTECTED', 403);
        }
    }
}
