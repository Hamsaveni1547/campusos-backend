<?php

namespace App\Modules\Identity\Authorization;

/**
 * Built-in roles shared by all institutions. Their permissions are maintained
 * in code (see permissionPatterns) and synced by `campusos:sync-permissions`.
 * Institutions can add custom roles on top.
 */
enum SystemRole: string
{
    case Applicant = 'applicant';
    case Student = 'student';
    case Faculty = 'faculty';
    case Hod = 'hod';
    case Examiner = 'examiner';
    case AdminStaff = 'admin_staff';
    case HrOfficer = 'hr_officer';
    case FinanceOfficer = 'finance_officer';
    case Librarian = 'librarian';
    case Staff = 'staff';
    case InstitutionHead = 'institution_head';
    case InstitutionAdmin = 'institution_admin';

    public function displayName(): string
    {
        return match ($this) {
            self::Applicant => 'Applicant',
            self::Student => 'Student',
            self::Faculty => 'Faculty',
            self::Hod => 'Head of Department',
            self::Examiner => 'Examiner',
            self::AdminStaff => 'Administrative Staff',
            self::HrOfficer => 'HR Officer',
            self::FinanceOfficer => 'Finance Officer',
            self::Librarian => 'Librarian',
            self::Staff => 'Non-teaching Staff',
            self::InstitutionHead => 'Institution Head (Principal / Director)',
            self::InstitutionAdmin => 'Institution Administrator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Applicant => 'Applies for admission and tracks the application.',
            self::Student => 'Enrolled student using the student portal.',
            self::Faculty => 'Teaching staff: classes, attendance, assessments.',
            self::Hod => 'Manages one department: faculty, students, curriculum, timetable.',
            self::Examiner => 'Examination duties for an exam cycle: papers, evaluation, results.',
            self::AdminStaff => 'Admissions, student records, certificates and office work.',
            self::HrOfficer => 'Employee records, recruitment, leave, appraisal.',
            self::FinanceOfficer => 'Fees, payments, refunds, payroll and financial reports.',
            self::Librarian => 'Library catalogue, circulation and fines.',
            self::Staff => 'Non-teaching staff with self-service access.',
            self::InstitutionHead => 'Monitors the institution and approves institution-level requests.',
            self::InstitutionAdmin => 'Configures the institution: users, roles, settings, security.',
        };
    }

    public function isDepartmentScoped(): bool
    {
        return in_array($this, [self::Hod, self::Faculty], true);
    }

    /**
     * Permission patterns granted to this role. Later phases extend these
     * as modules add permissions.
     *
     * @return array<int, string>
     */
    public function permissionPatterns(): array
    {
        return match ($this) {
            self::InstitutionAdmin => [
                'ai.*',
                'identity.*',
                'institution.*',
                'communication.*',
                'admin.*',
                'hr.*',
                'admissions.*',
                'students.*',
                'academics.*',
                'timetable.*',
                'attendance.*',
                'assessment.*',
                'exams.*',
                'qbank.*',
                'finance.*',
                'payroll.*',
                'leave.*',
                'library.*',
                'assets.*',
                'feedback.*',
                'mentoring.*',
            ],
            self::Librarian => [
                'ai.assistant.use',
                'communication.announcements.publish',
                'library.*',
            ],
            self::FinanceOfficer => [
                'ai.assistant.use',
                'communication.announcements.publish',
                'students.records.view',
                'hr.employees.view',
                'finance.*',
                'payroll.*',
            ],
            self::Examiner => [
                'ai.assistant.use',
                'communication.announcements.publish',
                'academics.catalogue.view',
                'students.records.view',
                'exams.cycles.manage',
                'exams.eligibility.override',
                'exams.evaluation.enter',
                'exams.scripts.decode',
                'exams.results.process',
                'exams.results.verify',
                'exams.results.publish',
                'exams.revaluation.manage',
                'exams.reports.view',
                'qbank.*',
            ],
            self::InstitutionHead => [
                'ai.assistant.use',
                'ai.usage.view',
                'communication.announcements.publish_all',
                'exams.cycles.approve',
                'mentoring.reports.view',
                'mentoring.notes.confidential',
                'assets.reports.view',
                'feedback.*',
                'leave.approve',
                'leave.reports.view',
                'library.reports.view',
                'payroll.runs.approve',
                'payroll.reports.view',
                'finance.reports.view',
                'finance.concessions.approve',
                'qbank.logs.view',
                'exams.reports.view',
                'exams.results.verify',
                'exams.results.publish',
                'students.records.view',
                'academics.catalogue.view',
                'academics.curriculum.approve',
                'timetable.view',
                'attendance.reports.view',
                'assessment.reports.view',
                'admissions.applications.view',
                'admissions.management.approve',
                'identity.users.view',
                'identity.roles.view',
                'identity.role_assignments.view',
                'communication.deliveries.view',
                'admin.audit.view',
                'hr.employees.view',
            ],
            self::HrOfficer => [
                'ai.assistant.use',
                'identity.users.view',
                'identity.users.create',
                'identity.users.update',
                'identity.users.import',
                'identity.role_assignments.view',
                'hr.*',
                'leave.*',
                'payroll.reports.view',
                'communication.announcements.publish',
            ],
            self::Hod => [
                'ai.assistant.use',
                'communication.announcements.publish',
                'mentoring.allocate',
                'mentoring.mentor',
                'mentoring.reports.view',
                'assets.reports.view',
                'assets.rooms.book',
                'feedback.results.view',
                'feedback.complaints.handle',
                'leave.approve',
                'leave.reports.view',
                'hr.employees.view',
                'students.records.view',
                'students.documents.verify',
                'students.changes.approve',
                'academics.catalogue.view',
                'academics.curriculum.manage',
                'academics.offerings.manage',
                'academics.allocations.manage',
                'academics.syllabus.track',
                'academics.materials.manage',
                'academics.registrations.approve',
                'timetable.view',
                'timetable.manage',
                'timetable.publish',
                'timetable.adjust',
                'attendance.records.mark',
                'attendance.corrections.approve',
                'attendance.condonation.manage',
                'attendance.reports.view',
                'assessment.*',
                'exams.reports.view',
                'qbank.questions.contribute',
                'qbank.questions.manage',
                'qbank.papers.view',
            ],
            self::Faculty => [
                'ai.assistant.use',
                'communication.announcements.publish',
                'mentoring.mentor',
                'assets.rooms.book',
                'exams.evaluation.enter',
                'qbank.questions.contribute',
                'timetable.view',
                'attendance.records.mark',
                'assessment.marks.enter',
                'students.records.view',
                'academics.catalogue.view',
                'academics.syllabus.track',
                'academics.materials.manage',
            ],
            self::AdminStaff => [
                'ai.assistant.use',
                'communication.announcements.publish_all',
                'identity.users.view',
                'institution.batches.manage',
                'institution.calendar.manage',
                'institution.rooms.manage',
                'hr.employees.view',
                'admissions.cycles.manage',
                'admissions.applications.view',
                'admissions.applications.verify',
                'admissions.applications.process',
                'admissions.seats.allocate',
                'admissions.confirm',
                'students.*',
                'academics.catalogue.view',
                'academics.offerings.manage',
                'academics.registrations.approve',
                'timetable.view',
                'timetable.manage',
                'timetable.adjust',
                'attendance.records.edit',
                'attendance.corrections.approve',
                'attendance.reports.view',
                'assessment.marks.lock',
                'assessment.reports.view',
                'exams.cycles.manage',
                'exams.reports.view',
                'assets.manage',
                'assets.maintenance.manage',
                'assets.reports.view',
                'assets.rooms.book',
                'feedback.complaints.handle',
                'finance.payments.collect',
                'finance.reports.view',
            ],
            self::Student, self::Staff => [
                'ai.assistant.use',
            ],
            default => [],
        };
    }

    /**
     * Roles that must use MFA unless an institution changes the security setting.
     *
     * @return array<int, string>
     */
    public static function mfaRequiredByDefault(): array
    {
        return [
            self::InstitutionAdmin->value,
            self::InstitutionHead->value,
            self::HrOfficer->value,
            self::FinanceOfficer->value,
            self::Examiner->value,
            self::Hod->value,
        ];
    }
}
