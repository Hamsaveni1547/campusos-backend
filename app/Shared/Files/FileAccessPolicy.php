<?php

namespace App\Shared\Files;

use App\Models\User;
use App\Modules\Institution\Models\Institution;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read a stored file:
 *  - the uploader;
 *  - anyone allowed to "view" the record it is attached to (that record's policy decides);
 *  - members of the institution for its public files (logo) and user avatars.
 */
class FileAccessPolicy
{
    public function canView(User $user, StoredFile $file): bool
    {
        if ($file->uploaded_by === $user->id) {
            return true;
        }

        $sameInstitution = $file->institution_id !== null && $user->belongsToInstitution($file->institution_id);

        if ($file->classification === FileClassification::Public && ($sameInstitution || $file->institution_id === null)) {
            return true;
        }

        $owner = $file->attachable;

        if ($owner === null) {
            return false;
        }

        if ($owner instanceof User) {
            return $owner->is($user) || ($file->purpose === 'avatar' && $sameInstitution);
        }

        if ($owner instanceof Institution) {
            return $user->belongsToInstitution($owner->id);
        }

        return Gate::forUser($user)->allows('view', $owner);
    }

    public function canDelete(User $user, StoredFile $file): bool
    {
        return $file->uploaded_by === $user->id && ! $file->isAttached();
    }
}
