<?php

namespace App\Modules\Feedback\Policies;

use App\Models\User;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Feedback\Services\FeedbackService;

/**
 * Who may see a complaint (and so download its attachments): the person who raised it and its handlers.
 */
class ComplaintPolicy
{
    public function view(User $user, Complaint $complaint): bool
    {
        return $complaint->created_by === $user->id || app(FeedbackService::class)->canHandle($user, $complaint);
    }
}
