<?php

namespace App\Modules\HR\Enums;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Probation = 'probation';
    case Contract = 'contract';
    case Visiting = 'visiting';
    case Guest = 'guest';
}
