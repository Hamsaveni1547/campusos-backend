<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Actions\Roles\RevokeRoleAssignment;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Enums\DepartmentType;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Department;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Departments
 */
class DepartmentController extends Controller
{
    /**
     * Departments. Filters: `filter[type]`, `filter[status]`, `filter[search]`.
     */
    public function index(Request $request): JsonResponse
    {
        $departments = Department::query()
            ->withCount(['programmes', 'employees' => fn ($query) => $query->where('status', EmployeeStatus::Active)])
            ->when($request->input('filter.type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();

        return ApiResponse::list($departments, fn (Department $department): array => $this->present($department));
    }

    public function show(Department $department): JsonResponse
    {
        $department->loadCount(['programmes', 'employees' => fn ($query) => $query->where('status', EmployeeStatus::Active)]);

        return ApiResponse::data($this->present($department, withHod: true));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::DepartmentsManage);

        $department = Department::query()->create($request->validate($this->rules()));

        return ApiResponse::data($this->present($department), 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::DepartmentsManage);
        $validated = $request->validate($this->rules($department));

        if (($validated['status'] ?? null) === RecordStatus::Inactive->value && $department->status === RecordStatus::Active) {
            $this->assertCanDeactivate($department);
        }

        $department->update($validated);

        return ApiResponse::data($this->present($department));
    }

    public function destroy(Department $department): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::DepartmentsManage);

        return ApiResponse::deleteUnlessUsed($department, ['programmes' => 'programmes', 'employees' => 'staff', 'rooms' => 'rooms'], 'department');
    }

    /**
     * Appoint the head of department (replaces the current one).
     * The person must be an active teaching staff member of this department.
     */
    public function assignHod(Request $request, Department $department, AssignRole $assignRole, RevokeRoleAssignment $revoke): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::DepartmentsManage);
        $validated = $request->validate(['user_id' => ['required', 'string', 'size:26']]);

        $employee = Employee::query()
            ->whereHas('user', fn ($query) => $query->where('ulid', $validated['user_id']))
            ->where('department_id', $department->id)
            ->where('staff_type', StaffType::Teaching)
            ->where('status', EmployeeStatus::Active)
            ->first();

        if ($employee === null) {
            throw new BusinessRuleException('The head of department must be an active teaching staff member of this department.', 'HOD_NOT_ELIGIBLE', 422);
        }

        /** @var User $actor */
        $actor = $request->user();
        $role = Role::query()->whereNull('institution_id')->where('name', SystemRole::Hod->value)->firstOrFail();

        DB::transaction(function () use ($department, $employee, $actor, $role, $assignRole, $revoke): void {
            $current = $department->currentHodAssignment();

            if ($current !== null && $current->user_id === $employee->user_id) {
                throw new BusinessRuleException('This person is already the head of department.', 'ALREADY_HOD', 409);
            }

            if ($current !== null) {
                $revoke->handle($current, $actor, 'Replaced as head of department');
            }

            $assignRole->handle($employee->user, $role, $actor, $department->id, reason: 'Head of '.$department->name);
        });

        return ApiResponse::data($this->present($department->refresh(), withHod: true));
    }

    /**
     * Remove the head of department without appointing a new one.
     */
    public function removeHod(Request $request, Department $department, RevokeRoleAssignment $revoke): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::DepartmentsManage);
        $current = $department->currentHodAssignment();

        if ($current === null) {
            throw new BusinessRuleException('This department has no head of department.', 'NO_HOD', 409);
        }

        $revoke->handle($current, $request->user(), $request->input('reason'));

        return ApiResponse::data($this->present($department, withHod: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Department $department = null): array
    {
        $required = $department === null ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'alpha_dash', Rule::unique('departments', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($department?->id)],
            'name' => [$required, 'string', 'max:150'],
            'type' => ['sometimes', Rule::enum(DepartmentType::class)],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'established_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:'.now()->year],
        ];
    }

    private function assertCanDeactivate(Department $department): void
    {
        if ($department->programmes()->where('status', RecordStatus::Active)->exists()) {
            throw new BusinessRuleException('Deactivate the department\'s programmes first.', 'DEPARTMENT_HAS_ACTIVE_PROGRAMMES', 409);
        }

        if ($department->employees()->where('status', EmployeeStatus::Active)->exists()) {
            throw new BusinessRuleException('Move or exit the department\'s staff first.', 'DEPARTMENT_HAS_ACTIVE_STAFF', 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Department $department, bool $withHod = false): array
    {
        $data = [
            'id' => $department->ulid,
            'code' => $department->code,
            'name' => $department->name,
            'type' => $department->type->value,
            'status' => $department->status->value,
            'description' => $department->description,
            'email' => $department->email,
            'phone' => $department->phone,
            'established_year' => $department->established_year,
            'programmes_count' => $department->programmes_count ?? null,
            'active_staff_count' => $department->employees_count ?? null,
        ];

        if ($withHod) {
            $hod = $department->currentHodAssignment();
            $data['hod'] = $hod === null ? null : ['user_id' => $hod->user->ulid, 'name' => $hod->user->name, 'since' => $hod->created_at?->toDateString()];
        }

        return $data;
    }
}
