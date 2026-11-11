<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Models\Institution;
use App\Shared\Authorization\ModuleAccess;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Platform admin: modules (on/off per institution), the permission catalogue, built-in roles and
 * which permissions each built-in role has (role × module × permission).
 *
 * @tags Platform access
 */
class PlatformAccessController extends Controller
{
    public function __construct(private PermissionRegistry $registry, private ModuleAccess $modules, private PermissionRegistrar $registrar) {}

    /**
     * Every module with its permission counts.
     */
    public function modules(): JsonResponse
    {
        $byModule = collect($this->registry->all())->groupBy('module', preserveKeys: true);

        return ApiResponse::data(collect(ModuleAccess::LABELS)->map(fn (array $label, string $key): array => [
            'key' => $key,
            'label' => $label[0],
            'description' => $label[1],
            'core' => in_array($key, ModuleAccess::CORE, true),
            'permissions' => $byModule->get($key)?->count() ?? 0,
            'sensitive' => $byModule->get($key)?->where('sensitive', true)->count() ?? 0,
        ])->values());
    }

    /**
     * Modules switched on or off for an institution.
     */
    public function institutionModules(Institution $institution): JsonResponse
    {
        $disabled = $this->modules->disabled($institution->id);

        return ApiResponse::data(collect(ModuleAccess::LABELS)->map(fn (array $label, string $key): array => [
            'key' => $key, 'label' => $label[0], 'core' => in_array($key, ModuleAccess::CORE, true), 'enabled' => ! in_array($key, $disabled, true),
        ])->values());
    }

    /**
     * `modules`: { key: true|false }. Core modules stay on.
     */
    public function updateInstitutionModules(Request $request, Institution $institution): JsonResponse
    {
        $validated = $request->validate(['modules' => ['required', 'array'], 'modules.*' => ['boolean']]);
        $this->modules->set($institution->id, array_intersect_key($validated['modules'], ModuleAccess::LABELS));
        $this->registrar->forgetCachedPermissions();
        activity('platform')->performedOn($institution)->withProperties(['modules' => $validated['modules']])->log('modules updated');

        return ApiResponse::message('Modules updated.');
    }

    /**
     * The permission catalogue, grouped by module, with the built-in roles that have each permission.
     */
    public function permissions(): JsonResponse
    {
        $holders = DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereNull('roles.institution_id')->select('permissions.name as permission', 'roles.name as role')->get()->groupBy('permission');

        return ApiResponse::data(collect($this->registry->all())->groupBy('module', preserveKeys: true)->map(fn ($items, string $module): array => [
            'module' => $module,
            'label' => ModuleAccess::LABELS[$module][0] ?? ucfirst($module),
            'permissions' => $items->map(fn (array $d, string $name): array => [
                'name' => $name, 'description' => $d['description'], 'sensitive' => $d['sensitive'],
                'roles' => $holders->get($name)?->pluck('role')->values()->all() ?? [],
            ])->values(),
        ])->sortBy('label')->values());
    }

    /**
     * Built-in roles.
     */
    public function roles(): JsonResponse
    {
        $users = RoleAssignment::query()->withoutGlobalScopes()->whereNull('revoked_at')->selectRaw('role_id, count(distinct user_id) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return ApiResponse::list(Role::query()->whereNull('institution_id')->where('is_system', true)->withCount('permissions')->orderBy('id')->get(), fn (Role $r): array => [
            ...$this->role($r), 'users' => (int) ($users[$r->id] ?? 0), 'permissions_count' => (int) $r->getAttribute('permissions_count'),
        ]);
    }

    /**
     * One built-in role with its permissions, and the defaults it would get on reset.
     */
    public function show(string $role): JsonResponse
    {
        $model = $this->systemRole($role);
        $defaults = ($case = SystemRole::tryFrom($model->name)) ? $this->registry->expand($case->permissionPatterns()) : [];

        return ApiResponse::data([...$this->role($model), 'permissions' => $model->permissions()->pluck('name')->sort()->values(), 'defaults' => array_values($defaults)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function role(Role $role): array
    {
        return [
            'id' => $role->ulid, 'name' => $role->name, 'display_name' => $role->display_name, 'description' => $role->description,
            'is_department_scoped' => $role->is_department_scoped, 'is_customised' => $role->is_customised,
        ];
    }

    /**
     * `display_name`, `description`.
     */
    public function updateRole(Request $request, string $role): JsonResponse
    {
        $model = $this->systemRole($role);
        $model->forceFill($request->validate(['display_name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:300']]) + ['is_customised' => true])->save();

        return ApiResponse::message('Role updated.');
    }

    /**
     * Replace the role's permissions: `permissions[]` (names). Takes effect immediately for every institution.
     */
    public function updatePermissions(Request $request, string $role): JsonResponse
    {
        $model = $this->systemRole($role);
        $names = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::in($this->registry->names())]])['permissions'];

        if ($model->name === SystemRole::InstitutionAdmin->value && ! in_array('identity.roles.view', $names, true)) {
            throw new BusinessRuleException('The institution admin must keep access to roles, or nobody could fix permissions.', 'LOCKOUT', 422);
        }

        $before = $model->permissions()->pluck('name')->all();
        DB::transaction(function () use ($model, $names): void {
            $model->syncPermissions(Permission::query()->whereIn('name', $names)->get());
            $model->forceFill(['is_customised' => true])->save();
        });
        $this->registrar->forgetCachedPermissions();
        activity('platform')->performedOn($model)->withProperties(['added' => array_values(array_diff($names, $before)), 'removed' => array_values(array_diff($before, $names))])->log('role permissions changed');

        return ApiResponse::message('Permissions saved.');
    }

    /**
     * Back to the built-in defaults (name, description and permissions).
     */
    public function reset(string $role): JsonResponse
    {
        $model = $this->systemRole($role);
        $case = SystemRole::from($model->name);

        $model->forceFill(['display_name' => $case->displayName(), 'description' => $case->description(), 'is_customised' => false])->save();
        $model->syncPermissions($this->registry->expand($case->permissionPatterns()));
        $this->registrar->forgetCachedPermissions();
        activity('platform')->performedOn($model)->log('role reset to defaults');

        return ApiResponse::message('Role reset to its defaults.');
    }

    private function systemRole(string $ulid): Role
    {
        return Role::query()->whereNull('institution_id')->where('is_system', true)->where('ulid', $ulid)->firstOrFail();
    }
}
