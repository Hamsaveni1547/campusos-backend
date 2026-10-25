<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\HR\Models\Designation;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Enums\InstitutionType;
use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\CalendarEvent;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Term;
use App\Modules\Institution\Services\AcademicCalendar;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local/demo data for frontend development: one institution and one user per role.
 *
 * Every demo account uses the password in DEMO_PASSWORD below. MFA is switched
 * off for the demo institution so sign-in is quick; never use this in production.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Campus@Demo2026';

    public const INSTITUTION_CODE = 'DEMO';

    public function run(CurrentInstitution $currentInstitution, AssignRole $assignRole, SettingsRepository $settings): void
    {
        $platformAdmin = $this->user('Platform Admin', 'platform.admin@campusos.test', isPlatformAdmin: true);

        $institution = Institution::query()->firstOrCreate(
            ['code' => self::INSTITUTION_CODE],
            [
                'name' => 'CampusOS Demo College of Engineering',
                'short_name' => 'CDCE',
                'type' => InstitutionType::College,
                'email' => 'office@demo.campusos.test',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'country' => 'IN',
                'timezone' => 'Asia/Kolkata',
                'currency' => 'INR',
                'established_year' => 2001,
            ],
        );

        $currentInstitution->runAs($institution, function () use ($institution, $assignRole, $settings, $platformAdmin): void {
            $settings->update('security', ['mfa_required_roles' => []], $institution->id);

            foreach (SystemRole::cases() as $systemRole) {
                $email = str_replace('_', '.', $systemRole->value).'@demo.campusos.test';
                $user = $this->user($systemRole->displayName().' (Demo)', $email);

                $user->institutions()->syncWithoutDetaching([$institution->id => ['status' => 'active', 'is_default' => true, 'joined_at' => now()]]);

                $role = Role::query()->whereNull('institution_id')->where('name', $systemRole->value)->firstOrFail();

                if (! $user->roleAssignments()->effective()->where('role_id', $role->id)->exists()) {
                    $assignRole->handle($user, $role, $platformAdmin, reason: 'Demo data');
                }
            }
        });

        $currentInstitution->runAs($institution, fn () => $this->seedStructure($institution));

        $this->command?->info('Demo institution "'.self::INSTITUTION_CODE.'" seeded. Accounts: {role}@demo.campusos.test (e.g. institution.admin@demo.campusos.test), platform.admin@campusos.test.');
    }

    /**
     * Departments, programmes, current year/terms, batches, rooms, designations and staff records.
     */
    private function seedStructure(Institution $institution): void
    {
        if (Department::query()->exists()) {
            return;
        }

        $cse = Department::query()->create(['code' => 'CSE', 'name' => 'Computer Science and Engineering']);
        $ece = Department::query()->create(['code' => 'ECE', 'name' => 'Electronics and Communication Engineering']);
        Department::query()->create(['code' => 'ADM', 'name' => 'Administration', 'type' => 'administrative']);

        foreach ([[$cse, 'BE-CSE', 'B.E. Computer Science and Engineering'], [$ece, 'BE-ECE', 'B.E. Electronics and Communication']] as [$department, $code, $name]) {
            $programme = Programme::query()->create([
                'department_id' => $department->id, 'code' => $code, 'name' => $name, 'level' => 'ug',
                'duration_years' => 4, 'total_terms' => 8, 'intake_capacity' => 120, 'regulation' => '2022 Scheme',
            ]);

            foreach ([2023, 2024, 2025, 2026] as $year) {
                $batch = Batch::query()->create(['programme_id' => $programme->id, 'admission_year' => $year, 'name' => "{$code} {$year}"]);
                $batch->sections()->create(['name' => 'A', 'capacity' => 60]);
                $batch->sections()->create(['name' => 'B', 'capacity' => 60]);
            }
        }

        $calendar = app(AcademicCalendar::class);
        $year = AcademicYear::query()->create(['name' => '2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $odd = Term::query()->create(['academic_year_id' => $year->id, 'type' => 'odd', 'name' => 'Odd Semester 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
        Term::query()->create(['academic_year_id' => $year->id, 'type' => 'even', 'name' => 'Even Semester 2027', 'starts_on' => '2027-01-15', 'ends_on' => '2027-06-15']);
        $calendar->makeTermCurrent($odd);

        CalendarEvent::query()->create(['title' => 'Deepavali', 'type' => 'holiday', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-08', 'is_holiday' => true, 'academic_year_id' => $year->id]);
        CalendarEvent::query()->create(['title' => 'Internal Assessment 1', 'type' => 'examination', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-19', 'audience' => 'students', 'academic_year_id' => $year->id]);

        foreach ([['LH-101', 'classroom', 120, null], ['LH-102', 'classroom', 120, null], ['CS-LAB1', 'laboratory', 40, $cse], ['EC-LAB1', 'laboratory', 40, $ece], ['SH-1', 'seminar_hall', 200, null]] as [$code, $type, $capacity, $department]) {
            Room::query()->create(['code' => $code, 'type' => $type, 'capacity' => $capacity, 'building' => 'Main Block', 'department_id' => $department?->id]);
        }

        $designations = collect([
            ['Professor', 'teaching', 10], ['Associate Professor', 'teaching', 20], ['Assistant Professor', 'teaching', 30],
            ['Lab Assistant', 'non_teaching', 50], ['Office Superintendent', 'non_teaching', 40], ['Clerk', 'non_teaching', 60],
        ])->mapWithKeys(fn (array $row): array => [$row[0] => Designation::query()->create(['name' => $row[0], 'category' => $row[1], 'rank' => $row[2]])]);

        $teaching = [SystemRole::Faculty, SystemRole::Hod, SystemRole::Examiner, SystemRole::InstitutionHead];
        $sequence = 0;

        foreach (SystemRole::cases() as $systemRole) {
            if (in_array($systemRole, [SystemRole::Applicant, SystemRole::Student], true)) {
                continue;
            }

            $user = User::query()->where('email', str_replace('_', '.', $systemRole->value).'@demo.campusos.test')->firstOrFail();
            $isTeaching = in_array($systemRole, $teaching, true);

            Employee::query()->create([
                'user_id' => $user->id,
                'employee_code' => sprintf('%sEMP%04d', $institution->code, ++$sequence),
                'staff_type' => $isTeaching ? 'teaching' : 'non_teaching',
                'employment_type' => 'permanent',
                'department_id' => $isTeaching ? $cse->id : null,
                'designation_id' => $designations[$isTeaching ? ($systemRole === SystemRole::Faculty ? 'Assistant Professor' : 'Professor') : 'Office Superintendent']->id,
                'joined_on' => '2018-06-01',
            ]);

            if ($systemRole === SystemRole::Hod) {
                RoleAssignment::query()->where('user_id', $user->id)->whereHas('role', fn ($query) => $query->where('name', 'hod'))->update(['department_id' => $cse->id]);
            }
        }

        app(NumberGenerator::class)->nextValue('employee_code', $institution->id);
        DB::table('number_sequences')->where('institution_id', $institution->id)->where('sequence_key', 'employee_code')->update(['last_value' => $sequence]);
    }

    private function user(string $name, string $email, bool $isPlatformAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill(['name' => $name]);
            $user->forceFill([
                'password' => self::DEMO_PASSWORD,
                'status' => UserStatus::Active,
                'is_platform_admin' => $isPlatformAdmin,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ])->save();
        }

        return $user;
    }
}
