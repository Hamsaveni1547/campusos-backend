<?php

namespace App\Modules\Students\Models;

use App\Models\User;
use App\Modules\Students\Enums\ReviewStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's request to correct details they cannot edit themselves.
 *
 * @property int $student_id
 * @property array<string, mixed> $changes
 * @property string|null $reason
 * @property ReviewStatus $status
 * @property string|null $remarks
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read Student $student
 * @property-read User|null $reviewer
 */
#[Fillable(['student_id', 'changes', 'reason'])]
class ProfileChangeRequest extends TenantModel
{
    protected string $auditLog = 'students';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'status' => ReviewStatus::class, 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
