<?php

namespace App\Modules\Feedback\Models;

use App\Models\User;
use App\Modules\Feedback\Policies\ComplaintPolicy;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $complaint_category_id
 * @property string $subject
 * @property string $description
 * @property bool $is_anonymous the raiser's name is hidden from staff
 * @property string $status open|in_progress|resolved|closed
 * @property int|null $assigned_to
 * @property Carbon|null $due_at
 * @property Carbon|null $resolved_at
 * @property int|null $rating 1–5 from the raiser
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read ComplaintCategory $category
 * @property-read User|null $raiser
 * @property-read User|null $assignee
 */
#[UsePolicy(ComplaintPolicy::class)]
#[Fillable(['complaint_category_id', 'subject', 'description', 'is_anonymous'])]
class Complaint extends TenantModel
{
    protected string $auditLog = 'feedback';

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'due_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true) && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * @return BelongsTo<ComplaintCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'complaint_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ComplaintMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class)->orderBy('id');
    }
}
