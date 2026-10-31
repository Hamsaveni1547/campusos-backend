<?php

namespace App\Modules\Admissions\Enums;

enum CycleStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
