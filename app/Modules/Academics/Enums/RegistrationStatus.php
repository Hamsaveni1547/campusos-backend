<?php

namespace App\Modules\Academics\Enums;

/**
 * submitted → approved | rejected. A rejected registration can be changed and submitted again.
 */
enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
