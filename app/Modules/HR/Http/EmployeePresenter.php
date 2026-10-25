<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Models\Designation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeQualification;
use App\Modules\Institution\Http\Resources\AcademicResources;

/**
 * JSON shapes for staff records.
 */
final class EmployeePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Employee $employee): array
    {
        return [
            'id' => $employee->ulid,
            'employee_code' => $employee->employee_code,
            'name' => $employee->user->name,
            'email' => $employee->user->email,
            'mobile' => $employee->user->mobile,
            'user_id' => $employee->user->ulid,
            'staff_type' => $employee->staff_type->value,
            'employment_type' => $employee->employment_type->value,
            'status' => $employee->status->value,
            'department' => AcademicResources::department($employee->department),
            'designation' => self::designationRef($employee->designation),
            'joined_on' => $employee->joined_on->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Employee $employee, bool $withPersonal = true): array
    {
        $data = array_merge(self::summary($employee), [
            'account_status' => $employee->user->status->value,
            'reports_to' => $employee->reportsTo === null ? null : ['id' => $employee->reportsTo->ulid, 'name' => $employee->reportsTo->user->name],
            'contract_ends_on' => $employee->contract_ends_on?->toDateString(),
            'left_on' => $employee->left_on?->toDateString(),
            'exit_reason' => $employee->exit_reason,
            'highest_qualification' => $employee->highest_qualification,
            'specialization' => $employee->specialization,
            'prior_experience_years' => (float) $employee->prior_experience_years,
            'total_experience_years' => $employee->totalExperienceYears(),
            'qualifications' => $employee->qualifications->map(fn (EmployeeQualification $qualification): array => self::qualification($qualification))->values()->all(),
        ]);

        if ($withPersonal) {
            $data['personal'] = [
                'gender' => $employee->gender,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'address' => $employee->address,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function qualification(EmployeeQualification $qualification): array
    {
        return [
            'id' => $qualification->ulid,
            'degree' => $qualification->degree,
            'specialization' => $qualification->specialization,
            'university' => $qualification->university,
            'year_of_passing' => $qualification->year_of_passing,
            'grade' => $qualification->grade,
            'certificate_file_id' => $qualification->certificate?->ulid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function designation(Designation $designation): array
    {
        return [
            'id' => $designation->ulid,
            'name' => $designation->name,
            'category' => $designation->category->value,
            'rank' => $designation->rank,
            'status' => $designation->status->value,
            'employees_count' => $designation->employees_count ?? null,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function designationRef(?Designation $designation): ?array
    {
        return $designation === null ? null : ['id' => $designation->ulid, 'name' => $designation->name];
    }
}
