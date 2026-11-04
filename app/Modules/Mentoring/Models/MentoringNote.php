<?php

namespace App\Modules\Mentoring\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $student_id
 * @property string $body
 * @property string $visibility shared (mentor, HOD) | confidential (author and counsellors only)
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $author
 */
#[Fillable(['student_id', 'visibility'])]
class MentoringNote extends TenantModel
{
    /** The body is set with forceFill and not fillable, so private text never reaches the audit log. */
    protected string $auditLog = 'mentoring';

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
