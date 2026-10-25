<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\Roles\ManageCustomRoles;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Roles & permissions
 */
class RoleController extends Controller
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private ManageCustomRoles $roles,
    ) {}

    /**
     * Built-in and custom roles of the institution.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(IdentityPermissions::RolesView);
        $institutionId = $this->currentInstitution->require()->id;

        $roles = Role::query()
            ->availableTo($institutionId)
            ->with('permissions')
            ->withCount(['assignments' => fn ($query) => $query->effective()])
            ->when($request->filled('filter.is_system'), fn ($query) => $query->where('is_system', $request->boolean('filter.is_system')))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        Gate::authorize(IdentityPermissions::RolesView);

        return new RoleResource($role->load('permissions')->loadCount(['assignments' => fn ($query) => $query->effective()]));
    }

    /**
     * Create a custom role.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(IdentityPermissions::RolesManage);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            /** Machine name (snake_case). Generated from display_name when omitted. */
            'name' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_department_scoped' => ['sometimes', 'boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = $this->roles->create($validated, $validated['permissions'], $request->user());

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    /**
     * Update a custom role. Built-in roles are read-only.
     */
    public function update(Request $request, Role $role): RoleResource
    {
        Gate::authorize(IdentityPermissions::RolesManage);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_department_scoped' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        return new RoleResource($this->roles->update($role, $validated, $validated['permissions'] ?? null, $request->user()));
    }

    /**
     * Delete a custom role that nobody holds.
     */
    public function destroy(Role $role): JsonResponse
    {
        Gate::authorize(IdentityPermissions::RolesManage);
        $this->roles->delete($role);

        return response()->json(['message' => 'The role has been deleted.']);
    }

    /**
     * Every permission, grouped by module.
     */
    public function permissions(): JsonResponse
    {
        Gate::authorize(IdentityPermissions::RolesView);

        $grouped = Permission::query()
            ->orderBy('name')
            ->get(['name', 'module', 'description', 'is_sensitive'])
            ->groupBy('module')
            ->map(fn ($permissions, $module): array => [
                'module' => $module,
                'permissions' => $permissions->map(fn (Permission $permission): array => [
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'is_sensitive' => $permission->is_sensitive,
                ])->values()->all(),
            ])
            ->values();

        return response()->json(['data' => $grouped]);
    }
}
