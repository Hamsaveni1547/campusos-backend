<?php

namespace App\Modules\HR\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Authorization\HRPermissions;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Http\EmployeePresenter;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeQualification;
use App\Modules\HR\Services\EmployeeService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Staff
 */
class EmployeeController extends Controller
{
    private const RELATIONS = ['user', 'department', 'designation', 'reportsTo.user', 'qualifications.certificate'];

    public function __construct(private EmployeeService $employees) {}

    /**
     * Staff list. Filters: `filter[search]` (name, email, code), `filter[department]` (id), `filter[designation]` (id),
     * `filter[staff_type]`, `filter[employment_type]`, `filter[status]` (default: active). Sort: `sort=name|code|joined_on|-joined_on`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesView);

        $sort = (string) $request->input('sort', 'name');

        $employees = Employee::query()
            ->select('employees.*')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->with(['user', 'department', 'designation'])
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('users.name', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")
                ->orWhere('employees.employee_code', 'like', "%{$search}%")))
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.designation'), fn ($query, $id) => $query->whereHas('designation', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.staff_type'), fn ($query, $type) => $query->where('employees.staff_type', $type))
            ->when($request->input('filter.employment_type'), fn ($query, $type) => $query->where('employees.employment_type', $type))
            ->where('employees.status', $request->input('filter.status', EmployeeStatus::Active->value))
            ->orderBy(match (ltrim($sort, '-')) {
                'code' => 'employees.employee_code',
                'joined_on' => 'employees.joined_on',
                default => 'users.name',
            }, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($employees, fn (Employee $employee): array => EmployeePresenter::summary($employee));
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesView);

        return ApiResponse::data(EmployeePresenter::detail($employee->load(self::RELATIONS), $request->user()->can(HRPermissions::EmployeesManage)));
    }

    /**
     * Add a staff member. Creates or links their account, sends an invitation email and
     * gives the Faculty (teaching) or Non-teaching Staff role. The code is generated when omitted.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);

        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'send_invite' => ['sometimes', 'boolean'],
        ], $this->rules(true)));

        $employee = $this->employees->create(
            $validated,
            $this->employeeAttributes($validated),
            $this->actor($request),
            $request->boolean('send_invite', true),
        );

        return ApiResponse::data(EmployeePresenter::detail($employee->load(self::RELATIONS)), 201);
    }

    /**
     * Update a staff record. Account details (name, email) are edited through /users.
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);
        $validated = $request->validate($this->rules(false));

        $staffType = $validated['staff_type'] ?? $employee->staff_type->value;
        $attributes = $this->employeeAttributes($validated, $staffType);

        return ApiResponse::data(EmployeePresenter::detail($this->employees->update($employee, $attributes)->load(self::RELATIONS)));
    }

    /**
     * Change status: active, on_leave, or an exit (resigned, retired, terminated — removes access).
     */
    public function changeStatus(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'effective_on' => ['nullable', 'date'],
        ]);

        $employee = $this->employees->changeStatus(
            $employee->load('user'),
            EmployeeStatus::from($validated['status']),
            $this->actor($request),
            $validated['reason'] ?? null,
            isset($validated['effective_on']) ? Carbon::parse($validated['effective_on']) : null,
        );

        return ApiResponse::data(EmployeePresenter::detail($employee->load(self::RELATIONS)));
    }

    public function storeQualification(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);
        $qualification = $employee->qualifications()->create($this->qualificationAttributes($request));

        return ApiResponse::data(EmployeePresenter::qualification($qualification->load('certificate')), 201);
    }

    public function updateQualification(Request $request, EmployeeQualification $qualification): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);
        $qualification->update($this->qualificationAttributes($request, $qualification));

        return ApiResponse::data(EmployeePresenter::qualification($qualification->load('certificate')));
    }

    public function destroyQualification(EmployeeQualification $qualification): JsonResponse
    {
        Gate::authorize(HRPermissions::EmployeesManage);
        $qualification->delete();

        return ApiResponse::message('Qualification removed.');
    }

    /**
     * My own staff record.
     */
    public function me(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $this->actor($request)->id)->with(self::RELATIONS)->first();

        if ($employee === null) {
            throw new BusinessRuleException('You do not have a staff record in this institution.', 'NOT_AN_EMPLOYEE', 404);
        }

        return ApiResponse::data(EmployeePresenter::detail($employee));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            /** Generated from the institution's format when omitted. */
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:30', 'alpha_dash'],
            'staff_type' => [$required, Rule::enum(StaffType::class)],
            'employment_type' => [$required, Rule::enum(EmploymentType::class)],
            'department_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'designation_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'reports_to_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'joined_on' => [$required, 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
            'contract_ends_on' => ['sometimes', 'nullable', 'date', 'after:joined_on'],
            'gender' => ['sometimes', 'nullable', Rule::in(['female', 'male', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:-16 years'],
            'highest_qualification' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:150'],
            'prior_experience_years' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function employeeAttributes(array $validated, ?string $staffType = null): array
    {
        $staffType ??= $validated['staff_type'] ?? null;
        $attributes = collect($validated)->except(['name', 'email', 'mobile', 'send_invite'])->all();

        if (array_key_exists('department_id', $attributes)) {
            $attributes['department_id'] = $this->employees->resolveDepartmentId($attributes['department_id']);
        }

        if (array_key_exists('designation_id', $attributes)) {
            $attributes['designation_id'] = $this->employees->resolveDesignationId($attributes['designation_id'], $staffType);
        }

        if (array_key_exists('reports_to_id', $attributes)) {
            $attributes['reports_to_id'] = $this->employees->resolveEmployeeId($attributes['reports_to_id']);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationAttributes(Request $request, ?EmployeeQualification $qualification = null): array
    {
        $required = $qualification === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'degree' => [$required, 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:150'],
            'university' => ['sometimes', 'nullable', 'string', 'max:200'],
            'year_of_passing' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'grade' => ['sometimes', 'nullable', 'string', 'max:30'],
            /** Upload with purpose "document" first. */
            'certificate_file_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        if (array_key_exists('certificate_file_id', $validated) && $validated['certificate_file_id'] !== null) {
            $file = StoredFile::query()
                ->where('ulid', $validated['certificate_file_id'])
                ->where('uploaded_by', $this->actor($request)->id)
                ->where('purpose', 'document')
                ->first();

            if ($file === null) {
                throw new BusinessRuleException('Upload the certificate with purpose "document" first.', 'CERTIFICATE_FILE_INVALID', 422);
            }

            $validated['certificate_file_id'] = $file->id;
        }

        return $validated;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
