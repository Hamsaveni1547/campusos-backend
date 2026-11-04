<?php

namespace App\Modules\Library\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $library_copy_id
 * @property int $user_id
 * @property string $member_type student|staff
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property Carbon|null $returned_on
 * @property int $renewals
 * @property string $fine
 * @property string $fine_status none|due|paid|waived
 * @property string|null $fine_note
 * @property-read LibraryCopy $copy
 * @property-read User $member
 */
#[Fillable(['library_copy_id', 'user_id', 'member_type', 'issued_on', 'due_on'])]
class LibraryLoan extends TenantModel
{
    protected string $auditLog = 'library';

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'returned_on' => 'date', 'fine' => 'decimal:2'];
    }

    public function isOverdue(): bool
    {
        return $this->returned_on === null && $this->due_on->lt(today());
    }

    /**
     * @return BelongsTo<LibraryCopy, $this>
     */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
