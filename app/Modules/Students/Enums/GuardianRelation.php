<?php

namespace App\Modules\Students\Enums;

enum GuardianRelation: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Guardian = 'guardian';
    case Spouse = 'spouse';
    case Sibling = 'sibling';
    case Other = 'other';
}
