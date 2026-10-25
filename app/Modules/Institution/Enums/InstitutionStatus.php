<?php

namespace App\Modules\Institution\Enums;

enum InstitutionStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
