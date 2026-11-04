<?php

namespace App\Modules\Communication\Policies;

use App\Models\User;
use App\Modules\Communication\Authorization\CommunicationPermissions as P;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Models\AnnouncementRecipient;

/**
 * Who may see an announcement (and so download its attachments): its recipients once published, and its publishers.
 */
class AnnouncementPolicy
{
    public function view(User $user, Announcement $announcement): bool
    {
        if ($user->can(P::AnnouncementsPublishAll) || ($user->can(P::AnnouncementsPublish) && $announcement->created_by === $user->id)) {
            return true;
        }

        return $announcement->status === 'published'
            && AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->where('user_id', $user->id)->exists();
    }
}
