<?php

namespace App\Modules\Communication\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An announcement or circular sent to an audience. Publishing fixes the list of recipients.
 *
 * @property string $title
 * @property string $body
 * @property string $category
 * @property string $priority normal|important
 * @property string $audience_type everyone|staff|students|roles|departments|programmes|batches|sections
 * @property list<int|string>|null $audience_ids
 * @property bool $is_pinned
 * @property bool $requires_ack
 * @property Carbon|null $expires_on
 * @property string $status draft|published|withdrawn
 * @property Carbon|null $published_at
 * @property int $recipients_count
 * @property int|null $created_by
 * @property-read User|null $author
 */
#[Fillable(['title', 'body', 'category', 'priority', 'audience_type', 'audience_ids', 'is_pinned', 'requires_ack', 'expires_on'])]
class Announcement extends TenantModel
{
    public const AUDIENCES = ['everyone', 'staff', 'students', 'roles', 'departments', 'programmes', 'batches', 'sections'];

    /** Audiences any publisher may use; the others need the "publish to everyone" permission. */
    public const LIMITED_AUDIENCES = ['departments', 'programmes', 'batches', 'sections'];

    protected string $auditLog = 'communication';

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['audience_ids' => 'array', 'is_pinned' => 'boolean', 'requires_ack' => 'boolean', 'expires_on' => 'date', 'published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AnnouncementRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }
}
