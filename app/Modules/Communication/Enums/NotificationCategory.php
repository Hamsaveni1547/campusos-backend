<?php

namespace App\Modules\Communication\Enums;

enum NotificationCategory: string
{
    case Security = 'security';
    case Account = 'account';
    case Academic = 'academic';
    case Examination = 'examination';
    case Finance = 'finance';
    case HumanResources = 'hr';
    case Admissions = 'admissions';
    case Library = 'library';
    case General = 'general';
    case System = 'system';

    /**
     * Mandatory categories cannot be switched off by the user.
     */
    public function isMandatory(): bool
    {
        return in_array($this, [self::Security, self::Account], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security alerts',
            self::Account => 'Account updates',
            self::Academic => 'Academics',
            self::Examination => 'Examinations and results',
            self::Finance => 'Fees and payments',
            self::HumanResources => 'HR and payroll',
            self::Admissions => 'Admissions',
            self::Library => 'Library',
            self::General => 'General announcements',
            self::System => 'System notices',
        };
    }
}
