<?php

namespace App\Modules\Institution\Enums;

enum InstitutionType: string
{
    case University = 'university';
    case College = 'college';
    case AutonomousCollege = 'autonomous_college';
    case Institute = 'institute';
    case School = 'school';
}
