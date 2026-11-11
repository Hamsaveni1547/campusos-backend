<?php

namespace App\Shared\Authorization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which modules are switched on for an institution. A permission of a switched-off module is denied
 * everywhere (Gate) and left out of the signed-in user's permission list, so its menus disappear too.
 */
class ModuleAccess
{
    /** Modules that keep the system usable; they cannot be switched off. */
    public const CORE = ['identity', 'institution', 'admin', 'communication'];

    /** @var array<string, array{0: string, 1: string}> label, description */
    public const LABELS = [
        'identity' => ['Users & access', 'Sign-in, two-step verification, users, roles and permissions'],
        'institution' => ['Institution', 'Departments, programmes, academic calendar, batches, sections and rooms'],
        'admin' => ['Administration', 'Settings, audit log, imports and monitoring'],
        'communication' => ['Communication', 'Notifications, templates and announcements'],
        'hr' => ['Staff (HR)', 'Staff records, designations and qualifications'],
        'admissions' => ['Admissions', 'Admission cycles, seats, applications and offers'],
        'students' => ['Students', 'Student records, documents and change requests'],
        'academics' => ['Curriculum', 'Courses, curricula, course offerings, faculty allocation and materials'],
        'timetable' => ['Timetable', 'Periods, section timetables and daily changes'],
        'attendance' => ['Attendance', 'Taking attendance, corrections, shortage and condonation'],
        'assessment' => ['Internal assessment', 'Assessment schemes, marks, assignments and internal marks'],
        'exams' => ['Examinations', 'Exam cycles, registration, marks, results and revaluation'],
        'qbank' => ['Question bank', 'Questions, paper setting and sealed papers'],
        'finance' => ['Fees', 'Fee structures, demands, collection and concessions'],
        'payroll' => ['Payroll', 'Salaries, monthly payroll runs and payslips'],
        'leave' => ['Leave', 'Leave types, balances, applications and approvals'],
        'library' => ['Library', 'Catalogue, circulation and fines'],
        'assets' => ['Labs & assets', 'Asset register, room bookings and repair requests'],
        'feedback' => ['Feedback & complaints', 'Surveys and grievance redressal'],
        'mentoring' => ['Mentoring', 'Mentor allocation, mentee notes, concerns and meetings'],
    ];

    /** @var array<string, string>|null permission name => module */
    private ?array $moduleOf = null;

    public function __construct(private PermissionRegistry $registry) {}

    /**
     * @return list<string> modules switched off for the institution
     */
    public function disabled(int $institutionId): array
    {
        return Cache::remember(self::cacheKey($institutionId), now()->addMinutes(10), fn (): array => DB::table('institution_modules')
            ->where('institution_id', $institutionId)->where('enabled', false)->pluck('module')->all());
    }

    /**
     * @param  array<string, bool>  $states  module => enabled
     */
    public function set(int $institutionId, array $states): void
    {
        foreach ($states as $module => $enabled) {
            if (in_array($module, self::CORE, true) || ! isset(self::LABELS[$module])) {
                continue;
            }
            DB::table('institution_modules')->updateOrInsert(['institution_id' => $institutionId, 'module' => $module], ['enabled' => (bool) $enabled, 'updated_at' => now(), 'created_at' => now()]);
        }

        Cache::forget(self::cacheKey($institutionId));
    }

    public function moduleOf(string $permission): ?string
    {
        $this->moduleOf ??= array_map(fn (array $d): string => $d['module'], $this->registry->all());

        return $this->moduleOf[$permission] ?? null;
    }

    /**
     * False when the permission belongs to a module switched off for the institution.
     */
    public function allows(string $permission, ?int $institutionId): bool
    {
        if ($institutionId === null) {
            return true;
        }

        $module = $this->moduleOf($permission);

        return $module === null || ! in_array($module, $this->disabled($institutionId), true);
    }

    private static function cacheKey(int $institutionId): string
    {
        return 'institution-modules.disabled.'.$institutionId;
    }
}
