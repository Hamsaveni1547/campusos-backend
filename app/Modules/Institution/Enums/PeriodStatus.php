<?php

namespace App\Modules\Institution\Enums;

enum PeriodStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';
}
