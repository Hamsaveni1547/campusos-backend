<?php

namespace App\Modules\HR\Imports;

use App\Models\User;
use App\Modules\HR\Authorization\HRPermissions;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Models\Designation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Department;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Imports\Import;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRunContext;
use Illuminate\Validation\Rule;

/**
 * CSV columns: name, email, staff_type (teaching|non_teaching), employment_type, joined_on (YYYY-MM-DD) — required;
 * mobile, employee_code, department_code, designation (name) — optional.
 */
class EmployeeImporter implements Importer
{
    public function __construct(private EmployeeService $employees) {}

    public function permission(): string
    {
        return HRPermissions::EmployeesImport;
    }

    public function requiredColumns(): array
    {
        return ['name', 'email', 'staff_type', 'employment_type', 'joined_on'];
    }

    public function optionalColumns(): array
    {
        return ['mobile', 'employee_code', 'department_code', 'designation'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'staff_type' => ['required', Rule::enum(StaffType::class)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'joined_on' => ['required', 'date_format:Y-m-d'],
            'mobile' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'employee_code' => ['nullable', 'alpha_dash', 'max:30'],
            'department_code' => ['nullable', 'string', 'max:20'],
            'designation' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function check(array $row, ImportRunContext $context): array
    {
        $errors = [];

        foreach (['email', 'employee_code'] as $column) {
            if ($context->remember($column, $row[$column] ?? null, 0) !== null) {
                $errors[] = "The {$column} appears more than once in the file.";
            }
        }

        $user = User::query()->where('email', mb_strtolower((string) ($row['email'] ?? '')))->first();
        if ($user !== null && Employee::query()->where('user_id', $user->id)->exists()) {
            $errors[] = 'This person already has a staff record.';
        }

        if (filled($row['employee_code'] ?? null) && Employee::query()->where('employee_code', $row['employee_code'])->exists()) {
            $errors[] = 'The employee code is already in use.';
        }

        if (filled($row['department_code'] ?? null) && $this->department($row['department_code']) === null) {
            $errors[] = "Unknown department code \"{$row['department_code']}\".";
        }

        if (filled($row['designation'] ?? null) && $this->designation($row['designation'], $row['staff_type'] ?? null) === null) {
            $errors[] = "Unknown designation \"{$row['designation']}\" for this staff type.";
        }

        return $errors;
    }

    public function import(array $row, Import $import, User $actor): void
    {
        $department = filled($row['department_code'] ?? null) ? $this->department((string) $row['department_code']) : null;
        $designation = filled($row['designation'] ?? null) ? $this->designation((string) $row['designation'], $row['staff_type']) : null;

        if ((filled($row['department_code'] ?? null) && $department === null) || (filled($row['designation'] ?? null) && $designation === null)) {
            throw new BusinessRuleException('Department or designation not found.');
        }

        $this->employees->create(
            ['name' => $row['name'], 'email' => $row['email'], 'mobile' => $row['mobile'] ?? null],
            [
                'employee_code' => $row['employee_code'] ?? null,
                'staff_type' => $row['staff_type'],
                'employment_type' => $row['employment_type'],
                'joined_on' => $row['joined_on'],
                'department_id' => $department?->id,
                'designation_id' => $designation?->id,
            ],
            $actor,
            (bool) ($import->options['send_invite'] ?? true),
        );
    }

    private function department(string $code): ?Department
    {
        return Department::query()->where('code', $code)->where('status', RecordStatus::Active)->first();
    }

    private function designation(string $name, ?string $staffType): ?Designation
    {
        return Designation::query()
            ->where('name', $name)
            ->where('status', RecordStatus::Active)
            ->when($staffType !== null, fn ($query) => $query->where('category', $staffType))
            ->first();
    }
}
