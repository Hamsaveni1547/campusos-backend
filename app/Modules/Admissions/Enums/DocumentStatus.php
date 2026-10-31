<?php

namespace App\Modules\Admissions\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
