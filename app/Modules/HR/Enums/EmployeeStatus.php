<?php

namespace App\Modules\HR\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Resigned = 'resigned';
    case Retired = 'retired';
    case Terminated = 'terminated';

    /**
     * The employee has left the institution; access is removed.
     */
    public function hasLeft(): bool
    {
        return in_array($this, [self::Resigned, self::Retired, self::Terminated], true);
    }
}
