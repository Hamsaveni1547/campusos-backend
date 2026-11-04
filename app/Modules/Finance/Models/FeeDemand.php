<?php

namespace App\Modules\Finance\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a student owes. Balance = amount − approved concession − paid.
 *
 * @property int $student_id
 * @property int|null $fee_structure_id
 * @property int|null $term_id
 * @property string $title
 * @property Carbon $due_on
 * @property string $amount
 * @property string $concession
 * @property string $paid
 * @property string $status open|partly_paid|paid|cancelled
 * @property string|null $cancel_reason
 * @property-read Student $student
 */
#[Fillable(['student_id', 'fee_structure_id', 'term_id', 'title', 'due_on', 'amount'])]
class FeeDemand extends TenantModel
{
    protected string $auditLog = 'finance';

    protected function casts(): array
    {
        return ['due_on' => 'date', 'amount' => 'decimal:2', 'concession' => 'decimal:2', 'paid' => 'decimal:2'];
    }

    public function balance(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round((float) $this->amount - (float) $this->concession - (float) $this->paid, 2);
    }

    /**
     * Status from the amounts (cancelled stays cancelled).
     */
    public function refreshStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $this->status = $this->balance() <= 0 ? 'paid' : ((float) $this->paid > 0 ? 'partly_paid' : 'open');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return HasMany<FeeDemandItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeDemandItem::class);
    }

    /**
     * @return HasMany<FeeConcession, $this>
     */
    public function concessions(): HasMany
    {
        return $this->hasMany(FeeConcession::class);
    }
}
