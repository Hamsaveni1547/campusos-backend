<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Models\Designation;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Actions\Users\ChangeMembershipStatus;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Department;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff onboarding, updates and exits. Adding a staff member creates (or links)
 * their login account, invites them by email and gives them the Faculty or
 * Non-teaching Staff role.
 */
class EmployeeService
{
    public function __construct(
        private CreateUser $createUser,
        private ChangeMembershipStatus $membership,
        private NumberGenerator $numbers,
        private SettingsRepository $settings,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * @param  array<string, mixed>  $account  name, email, mobile
     * @param  array<string, mixed>  $data  employee fields (department/designation as ids already resolved)
     */
    public function create(array $account, array $data, User $actor, bool $sendInvite = true): Employee
    {
        $institution = $this->currentInstitution->require();

        $existing = User::query()->where('email', mb_strtolower(trim((string) $account['email'])))->first();
        if ($existing !== null && Employee::query()->where('user_id', $existing->id)->exists()) {
            throw new BusinessRuleException('This person already has a staff record in this institution.', 'EMPLOYEE_EXISTS', 409);
        }

        $code = $data['employee_code'] ?? null;
        if ($code !== null && Employee::query()->where('employee_code', $code)->exists()) {
            throw new BusinessRuleException('This employee code is already in use.', 'EMPLOYEE_CODE_TAKEN', 409);
        }

        return DB::transaction(function () use ($account, $data, $actor, $sendInvite, $institution, $code, $existing): Employee {
            $baseRole = Role::query()
                ->whereNull('institution_id')
                ->where('name', $data['staff_type'] === StaffType::Teaching->value ? SystemRole::Faculty->value : SystemRole::Staff->value)
                ->firstOrFail();

            $hasRole = $existing !== null && $existing->roleAssignments()->effective()->where('role_id', $baseRole->id)->exists();

            $user = $this->createUser->handle(
                ['name' => $account['name'], 'email' => $account['email'], 'mobile' => $account['mobile'] ?? null],
                $actor,
                $hasRole ? [] : [$baseRole],
                $sendInvite,
            )['user'];

            $data['employee_code'] = $code ?? $this->numbers->next(
                'employee_code',
                (string) $this->settings->get('hr', 'employee_code_format'),
                $institution->id,
                tokens: ['INST' => $institution->code],
            );

            return Employee::query()->create(array_merge($data, [
                'user_id' => $user->id,
                'status' => EmployeeStatus::Active,
            ]));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        if (isset($data['employee_code']) && $data['employee_code'] !== $employee->employee_code
            && Employee::query()->where('employee_code', $data['employee_code'])->whereKeyNot($employee->id)->exists()) {
            throw new BusinessRuleException('This employee code is already in use.', 'EMPLOYEE_CODE_TAKEN', 409);
        }

        if (isset($data['reports_to_id']) && $data['reports_to_id'] === $employee->id) {
            throw new BusinessRuleException('An employee cannot report to themselves.', 'INVALID_REPORTING');
        }

        $employee->update($data);

        return $employee;
    }

    /**
     * Change employment status. Leaving (resigned/retired/terminated) removes access
     * to this institution; returning to active restores it.
     */
    public function changeStatus(Employee $employee, EmployeeStatus $status, User $actor, ?string $reason, ?Carbon $effectiveOn): Employee
    {
        if ($employee->status === $status) {
            throw new BusinessRuleException('The employee already has this status.', 'STATUS_UNCHANGED', 409);
        }

        if ($status->hasLeft() && blank($reason)) {
            throw new BusinessRuleException('Give a reason for the exit.', 'EXIT_REASON_REQUIRED', 422);
        }

        DB::transaction(function () use ($employee, $status, $actor, $reason, $effectiveOn): void {
            $wasGone = $employee->status->hasLeft();

            $employee->update([
                'status' => $status,
                'left_on' => $status->hasLeft() ? ($effectiveOn ?? now())->toDateString() : null,
                'exit_reason' => $status->hasLeft() ? $reason : null,
            ]);

            if ($status->hasLeft() && ! $wasGone) {
                $this->membership->deactivate($employee->user, $actor, 'Staff exit: '.$status->value.' - '.$reason);
            } elseif (! $status->hasLeft() && $wasGone) {
                $this->membership->activate($employee->user, $actor);
            }
        });

        return $employee->refresh();
    }

    public function resolveDepartmentId(?string $ulid): ?int
    {
        if ($ulid === null) {
            return null;
        }

        $department = Department::query()->where('ulid', $ulid)->first();

        if ($department === null || $department->status !== RecordStatus::Active) {
            throw new BusinessRuleException('Choose an active department.', 'DEPARTMENT_INVALID', 422);
        }

        return $department->id;
    }

    public function resolveDesignationId(?string $ulid, ?string $staffType): ?int
    {
        if ($ulid === null) {
            return null;
        }

        $designation = Designation::query()->where('ulid', $ulid)->first();

        if ($designation === null || $designation->status !== RecordStatus::Active) {
            throw new BusinessRuleException('Choose an active designation.', 'DESIGNATION_INVALID', 422);
        }

        if ($staffType !== null && $designation->category->value !== $staffType) {
            throw new BusinessRuleException('This designation is for '.str_replace('_', '-', $designation->category->value).' staff.', 'DESIGNATION_MISMATCH', 422);
        }

        return $designation->id;
    }

    public function resolveEmployeeId(?string $ulid): ?int
    {
        if ($ulid === null) {
            return null;
        }

        return Employee::query()->where('ulid', $ulid)->value('id')
            ?? throw new BusinessRuleException('Reporting manager not found.', 'EMPLOYEE_INVALID', 422);
    }
}
