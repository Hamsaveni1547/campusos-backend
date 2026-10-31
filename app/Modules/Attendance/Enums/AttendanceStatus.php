<?php

namespace App\Modules\Attendance\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case OnDuty = 'on_duty';
    case Medical = 'medical';

    public function label(): string
    {
        return match ($this) {
            self::OnDuty => 'On duty',
            default => ucfirst($this->value),
        };
    }
}
