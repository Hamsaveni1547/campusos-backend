<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Institution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_adds_teaching_staff_with_generated_code_and_faculty_role(): void
    {
        Notification::fake();
        $institution = $this->institution(['code' => 'RVCE']);
        $hr = $this->member($institution, ['hr_officer']);
        [$department, $designation] = $this->structure($hr);

        $employee = $this->api('POST', 'employees', $hr, [
            'name' => 'Dr. Anita Rao',
            'email' => 'anita@example.com',
            'staff_type' => 'teaching',
            'employment_type' => 'permanent',
            'department_id' => $department,
            'designation_id' => $designation,
            'joined_on' => '2024-07-15',
            'prior_experience_years' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.employee_code', 'RVCEEMP0001')
            ->assertJsonPath('data.department.code', 'CSE')
            ->assertJsonPath('data.designation.name', 'Assistant Professor')
            ->assertJsonPath('data.account_status', 'invited')
            ->json('data');

        $user = User::query()->where('email', 'anita@example.com')->firstOrFail();
        Notification::assertSentTo($user, AccountSetupNotification::class);
        $this->assertSame(['faculty'], $user->roleAssignments()->with('role')->get()->pluck('role.name')->all());
        $this->assertGreaterThan(5, $employee['total_experience_years']);

        $this->api('POST', 'employees', $hr, [
            'name' => 'Again', 'email' => 'anita@example.com', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2024-07-15',
        ])->assertStatus(409)->assertJsonPath('code', 'EMPLOYEE_EXISTS');

        $this->api('POST', 'employees', $hr, [
            'name' => 'Clerk', 'email' => 'clerk@example.com', 'staff_type' => 'non_teaching', 'employment_type' => 'contract',
            'designation_id' => $designation, 'joined_on' => '2025-01-01',
        ])->assertStatus(422)->assertJsonPath('code', 'DESIGNATION_MISMATCH');

        $this->api('GET', 'employees?filter[search]=anita', $hr)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_staff_list_permissions_and_personal_details(): void
    {
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer']);
        $hod = $this->member($institution, ['hod']);
        $faculty = $this->member($institution, ['faculty']);

        $id = $this->api('POST', 'employees', $hr, [
            'name' => 'Ravi', 'email' => 'ravi@example.com', 'staff_type' => 'non_teaching', 'employment_type' => 'permanent',
            'joined_on' => '2020-06-01', 'date_of_birth' => '1990-01-01',
        ])->json('data.id');

        $this->api('GET', 'employees', $faculty)->assertForbidden();
        $this->api('GET', "employees/{$id}", $hod)->assertOk()->assertJsonMissingPath('data.personal');
        $this->api('GET', "employees/{$id}", $hr)->assertOk()->assertJsonPath('data.personal.date_of_birth', '1990-01-01');
    }

    public function test_hod_appointment_rules(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        [$cse] = $this->structure($admin);
        $ece = $this->api('POST', 'departments', $admin, ['code' => 'ECE', 'name' => 'Electronics'])->json('data.id');

        $teacher = $this->staff($admin, 'teacher@example.com', 'teaching', $cse);
        $other = $this->staff($admin, 'other@example.com', 'teaching', $ece);
        $clerk = $this->staff($admin, 'clerk@example.com', 'non_teaching', $cse);

        $this->api('POST', "departments/{$cse}/hod", $admin, ['user_id' => $other])->assertStatus(422)->assertJsonPath('code', 'HOD_NOT_ELIGIBLE');
        $this->api('POST', "departments/{$cse}/hod", $admin, ['user_id' => $clerk])->assertStatus(422);

        $this->api('POST', "departments/{$cse}/hod", $admin, ['user_id' => $teacher])->assertOk()->assertJsonPath('data.hod.user_id', $teacher);

        $hodUser = User::query()->where('ulid', $teacher)->firstOrFail();
        $cseId = Department::query()->withoutGlobalScopes()->where('ulid', $cse)->value('id');
        $this->assertSame([$cseId], $hodUser->departmentIdsForRole('hod', $institution->id));

        // Appointing a new HOD replaces the old one.
        $second = $this->staff($admin, 'second@example.com', 'teaching', $cse);
        $this->api('POST', "departments/{$cse}/hod", $admin, ['user_id' => $second])->assertOk()->assertJsonPath('data.hod.user_id', $second);
        $this->assertSame([], $hodUser->departmentIdsForRole('hod', $institution->id));

        $this->api('DELETE', "departments/{$cse}/hod", $admin)->assertOk()->assertJsonPath('data.hod', null);
    }

    public function test_exit_removes_access_and_rejoin_restores_it(): void
    {
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer']);
        $person = $this->member($institution, ['staff']);
        $employee = Employee::query()->withoutGlobalScopes()->forceCreate([
            'institution_id' => $institution->id, 'user_id' => $person->id, 'employee_code' => 'E1',
            'staff_type' => 'non_teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-01-01', 'status' => 'active',
        ]);

        $this->api('POST', "employees/{$employee->ulid}/status", $hr, ['status' => 'resigned'])->assertStatus(422)->assertJsonPath('code', 'EXIT_REASON_REQUIRED');

        $this->api('POST', "employees/{$employee->ulid}/status", $hr, ['status' => 'resigned', 'reason' => 'Moved city', 'effective_on' => '2026-10-31'])
            ->assertOk()->assertJsonPath('data.status', 'resigned')->assertJsonPath('data.left_on', '2026-10-31');

        $this->postJson('/api/v1/auth/login', ['login' => $person->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'T'])->assertForbidden();

        $this->api('GET', 'employees', $hr)->assertJsonPath('meta.total', 0);
        $this->api('GET', 'employees?filter[status]=resigned', $hr)->assertJsonPath('meta.total', 1);

        $this->api('POST', "employees/{$employee->ulid}/status", $hr, ['status' => 'active'])->assertOk()->assertJsonPath('data.left_on', null);
        $this->login($person->fresh());
    }

    public function test_qualifications_and_my_profile(): void
    {
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer']);
        $teacher = $this->member($institution, ['faculty']);
        $employee = Employee::query()->withoutGlobalScopes()->forceCreate([
            'institution_id' => $institution->id, 'user_id' => $teacher->id, 'employee_code' => 'F1',
            'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2022-01-01', 'status' => 'active',
        ]);

        $q = $this->api('POST', "employees/{$employee->ulid}/qualifications", $hr, ['degree' => 'Ph.D.', 'specialization' => 'Machine Learning', 'university' => 'VTU', 'year_of_passing' => 2021])
            ->assertCreated()->json('data');
        $this->api('PATCH', "employee-qualifications/{$q['id']}", $hr, ['grade' => 'Awarded'])->assertOk()->assertJsonPath('data.grade', 'Awarded');

        $this->api('GET', 'me/employee-profile', $teacher)->assertOk()
            ->assertJsonPath('data.employee_code', 'F1')
            ->assertJsonPath('data.qualifications.0.degree', 'Ph.D.');

        $this->api('GET', 'me/employee-profile', $hr)->assertNotFound()->assertJsonPath('code', 'NOT_AN_EMPLOYEE');
        $this->api('DELETE', "employee-qualifications/{$q['id']}", $hr)->assertOk();
    }

    public function test_designations_and_staff_import(): void
    {
        Notification::fake();
        $institution = $this->institution(['code' => 'BMS']);
        $hr = $this->member($institution, ['hr_officer']);
        [$department] = $this->structure($hr);
        $this->api('POST', 'designations', $hr, ['name' => 'Lab Assistant', 'category' => 'non_teaching'])->assertCreated();

        $csv = "name,email,staff_type,employment_type,joined_on,department_code,designation\n"
            ."Kiran,kiran@example.com,teaching,permanent,2023-06-01,CSE,Assistant Professor\n"
            ."Leela,leela@example.com,non_teaching,contract,2024-01-10,CSE,Lab Assistant\n";

        $fileId = $this->api('POST', 'files', $hr, ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv), 'purpose' => 'import'])->json('data.id');
        $import = $this->api('POST', 'imports', $hr, ['type' => 'employees', 'file_id' => $fileId])->assertStatus(202)->json('data.id');
        $this->assertSame('validated', $this->api('GET', "imports/{$import}", $hr)->json('data.status'));

        $this->api('POST', "imports/{$import}/commit", $hr)->assertStatus(202);
        $this->assertSame('completed', $this->api('GET', "imports/{$import}", $hr)->json('data.status'));

        $this->api('GET', 'employees?filter[department]='.$department, $hr)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['BMSEMP0001', 'BMSEMP0002'], Employee::query()->withoutGlobalScopes()->orderBy('id')->pluck('employee_code')->all());

        $designation = $this->api('GET', 'designations', $hr)->json('data.0');
        $this->api('DELETE', 'designations/'.$designation['id'], $hr)->assertStatus(409);
    }

    /**
     * @return array{0: string, 1: string} department id, teaching designation id
     */
    private function structure(User $actor): array
    {
        $institutionId = $actor->defaultInstitution()->id;
        $admin = $this->member(Institution::query()->findOrFail($institutionId), ['institution_admin']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->assertCreated()->json('data.id');
        $designation = $this->api('POST', 'designations', $admin, ['name' => 'Assistant Professor', 'category' => 'teaching', 'rank' => 30])->assertCreated()->json('data.id');

        return [$department, $designation];
    }

    private function staff(User $admin, string $email, string $type, ?string $department): string
    {
        return (string) $this->api('POST', 'employees', $admin, [
            'name' => ucfirst(strtok($email, '@')), 'email' => $email, 'staff_type' => $type, 'employment_type' => 'permanent',
            'department_id' => $department, 'joined_on' => '2023-01-01', 'send_invite' => false,
        ])->assertCreated()->json('data.user_id');
    }
}
