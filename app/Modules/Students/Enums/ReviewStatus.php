<?php

namespace App\Modules\Students\Enums;

/**
 * Review state of a student document or a profile change request.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
