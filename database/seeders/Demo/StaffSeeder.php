<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Designation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Institution\Models\Department;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Platform admin, institution admin, principal, controller of examinations, HR, accounts, library,
 * office staff, and per department a head plus faculty (one on leave, one resigned).
 */
class StaffSeeder extends CampusSeeder
{
    /**
     * Department code => [HOD, faculty...]. Each entry: [name, designation, gender].
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public const FACULTY = [
        'CSE' => [
            ['Dr. Girija J', 'Professor', 'female'], ['Dr. Ramesh Babu D R', 'Professor', 'male'], ['Dr. Kavya Shetty', 'Associate Professor', 'female'],
            ['Prof. Raghavendra Kulkarni', 'Assistant Professor', 'male'], ['Prof. Nandini Rao', 'Assistant Professor', 'female'], ['Prof. Suhas Gowda', 'Assistant Professor', 'male'],
        ],
        'AIDS' => [
            ['Dr. Shankar Murthy', 'Professor', 'male'], ['Dr. Meghana Iyer', 'Associate Professor', 'female'], ['Prof. Arjun Hegde', 'Assistant Professor', 'male'],
            ['Prof. Pooja Nayak', 'Assistant Professor', 'female'],
        ],
        'ISE' => [
            ['Dr. Asha T', 'Professor', 'female'], ['Dr. Vinay Kumar M', 'Associate Professor', 'male'], ['Prof. Divya Prakash', 'Assistant Professor', 'female'],
            ['Prof. Karthik Bhat', 'Assistant Professor', 'male'],
        ],
        'MCA' => [
            ['Dr. Shobha Rani', 'Professor', 'female'], ['Prof. Manjunath S', 'Associate Professor', 'male'], ['Prof. Lakshmi Narayan', 'Assistant Professor', 'female'],
        ],
        'MBA' => [
            ['Dr. Prakash Reddy', 'Professor', 'male'], ['Prof. Sneha Kulkarni', 'Associate Professor', 'female'], ['Prof. Rohit Menon', 'Assistant Professor', 'male'],
        ],
    ];

    protected function seed(): void
    {
        if (Employee::query()->exists()) {
            return;
        }

        $this->actor = $this->account('Platform Admin', 'platform.admin@campusos.test', platformAdmin: true);

        $this->as($this->actor, function (): void {
            $department = fn (string $code): int => Department::query()->where('code', $code)->value('id');
            $designation = fn (string $name): int => Designation::query()->where('name', $name)->value('id');

            $admin = $this->employee('BIT Administrator', 'admin@'.self::DOMAIN, 'non_teaching', $department('ADM'), $designation('Registrar'), '2012-06-01', 'male')->user;
            $this->grant($admin, 'institution_admin');

            // Leadership and offices, each with a predictable sign-in.
            foreach ([
                ['Dr. Ashwath M U', 'principal', 'teaching', 'CSE', 'Principal', ['institution_head'], 'male', '2001-07-15'],
                ['Dr. Harish Chandra', 'coe', 'teaching', 'EXAM', 'Professor', ['examiner'], 'male', '2005-08-01'],
                ['Revathi Srinivas', 'hr', 'non_teaching', 'ADM', 'Office Superintendent', ['hr_officer'], 'female', '2010-03-01'],
                ['Mohan Kumar B', 'accounts', 'non_teaching', 'ADM', 'Accounts Officer', ['finance_officer'], 'male', '2011-06-01'],
                ['Saraswathi K', 'library', 'non_teaching', 'LIB', 'Librarian', ['librarian'], 'female', '2008-09-01'],
                ['Naveen Raj', 'office', 'non_teaching', 'ADM', 'First Division Assistant', ['admin_staff'], 'male', '2015-01-05'],
                ['Bhavya M', 'admissions', 'non_teaching', 'ADM', 'First Division Assistant', ['admin_staff'], 'female', '2018-06-11'],
                ['Prakash Lab', 'lab.cse', 'non_teaching', 'CSE', 'Lab Instructor', [], 'male', '2016-07-01'],
            ] as [$name, $login, $type, $dept, $title, $roles, $gender, $joined]) {
                $user = $this->employee($name, $login.'@'.self::DOMAIN, $type, $department($dept), $designation($title), $joined, $gender)->user;

                foreach ($roles as $role) {
                    $this->grant($user, $role);
                }
            }

            // Departments: the first person is the head (also signs in as hod.<dept>).
            foreach (self::FACULTY as $code => $people) {
                foreach ($people as $index => [$name, $title, $gender]) {
                    $email = $index === 0 ? 'hod.'.strtolower($code).'@'.self::DOMAIN : $this->email(preg_replace('/^(Dr|Prof)\.\s*/', '', $name) ?? $name);
                    $joined = Carbon::create(2004 + ($index * 3) % 18, 6 + $index % 4, 1)->toDateString();
                    $employee = $this->employee($name, $email, 'teaching', $department($code), $designation($title), $joined, $gender);

                    if ($index === 0) {
                        $this->grant($employee->user, 'hod', $department($code));
                    }
                }
            }

            // A few staff changes so the HR screens have history.
            $service = app(EmployeeService::class);
            $service->changeStatus(Employee::query()->whereHas('user', fn ($q) => $q->where('email', $this->email('Suhas Gowda')))->firstOrFail(), EmployeeStatus::OnLeave, $admin, 'Higher studies (sabbatical)', now()->subWeeks(3));
            $service->changeStatus(Employee::query()->whereHas('user', fn ($q) => $q->where('email', $this->email('Karthik Bhat')))->firstOrFail(), EmployeeStatus::Resigned, $admin, 'Joined industry', now()->subMonths(2));
            $this->employee('Prof. Anil Joshi', $this->email('Anil Joshi'), 'teaching', $department('CSE'), $designation('Assistant Professor'), now()->subMonths(2)->toDateString(), 'male', 'visiting');
        });

        $this->command?->info('Staff seeded. Sign in with '.self::DEMO_PASSWORD.' as admin@, principal@, coe@, hr@, office@, hod.cse@ (… '.self::DOMAIN.').');
    }

    private User $actor;

    private function employee(string $name, string $email, string $type, ?int $departmentId, int $designationId, string $joined, string $gender, string $employment = 'permanent'): Employee
    {
        $employee = app(EmployeeService::class)->create(
            ['name' => $name, 'email' => $email, 'mobile' => '+9198'.substr((string) crc32($email), 0, 8)],
            [
                'staff_type' => $type, 'employment_type' => $employment, 'department_id' => $departmentId, 'designation_id' => $designationId,
                'joined_on' => $joined, 'gender' => $gender, 'highest_qualification' => str_starts_with($name, 'Dr.') ? 'Ph.D.' : ($type === 'teaching' ? 'M.Tech' : 'B.Com'),
            ],
            $this->actor,
            sendInvite: false,
        );

        $this->activate($employee->user);

        return $employee;
    }

    private function account(string $name, string $email, bool $platformAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $name]);
        $user->forceFill(['password' => self::DEMO_PASSWORD, 'status' => UserStatus::Active, 'is_platform_admin' => $platformAdmin, 'email_verified_at' => now(), 'password_changed_at' => now()])->save();

        return $user;
    }
}
