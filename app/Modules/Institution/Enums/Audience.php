<?php

namespace App\Modules\Institution\Enums;

enum Audience: string
{
    case All = 'all';
    case Students = 'students';
    case Staff = 'staff';
}
