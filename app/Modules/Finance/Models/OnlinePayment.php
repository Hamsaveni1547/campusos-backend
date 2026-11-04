<?php

namespace App\Modules\Finance\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An online payment attempt through the simulated gateway. A successful one creates a fee receipt.
 *
 * @property int $student_id
 * @property string $amount
 * @property list<int>|null $demand_ids
 * @property string|null $method upi|card|netbanking
 * @property string $status pending|success|failed
 * @property string|null $gateway_ref
 * @property string|null $failure_reason
 * @property int|null $fee_payment_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read Student $student
 * @property-read FeePayment|null $payment
 */
#[Fillable(['student_id', 'amount', 'demand_ids'])]
class OnlinePayment extends TenantModel
{
    public const METHODS = ['upi', 'card', 'netbanking'];

    protected string $auditLog = 'finance';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['demand_ids' => 'array', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<FeePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }
}
