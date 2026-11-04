<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payment received at the counter, with its receipt number. Cancelled receipts are kept.
 *
 * @property int $student_id
 * @property string $receipt_no
 * @property string $amount
 * @property string $mode cash|cheque|dd|neft|upi|card
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property string $status valid|cancelled
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property-read Student $student
 * @property-read User|null $receiver
 */
#[Fillable(['student_id', 'amount', 'mode', 'reference', 'paid_on'])]
class FeePayment extends TenantModel
{
    public const MODES = ['cash', 'cheque', 'dd', 'neft', 'upi', 'card'];

    protected string $auditLog = 'finance';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'cancelled_at' => 'datetime'];
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
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<FeePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class);
    }
}
