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
                'identity.*',
                'institution.*',
                'communication.*',
                'admin.*',
                'hr.*',
                'admissions.*',
                'students.*',
            ],
            self::InstitutionHead => [
                'students.records.view',
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
                'identity.users.view',
                'identity.users.create',
                'identity.users.update',
                'identity.users.import',
                'identity.role_assignments.view',
                'hr.*',
            ],
            self::Hod => [
                'hr.employees.view',
                'students.records.view',
                'students.documents.verify',
                'students.changes.approve',
            ],
            self::Faculty => [
                'students.records.view',
            ],
            self::AdminStaff => [
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
