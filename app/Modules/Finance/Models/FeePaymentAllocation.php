<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $fee_payment_id
 * @property int $fee_demand_id
 * @property string $amount
 * @property-read FeeDemand $demand
 */
#[Fillable(['fee_payment_id', 'fee_demand_id', 'amount'])]
class FeePaymentAllocation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<FeeDemand, $this>
     */
    public function demand(): BelongsTo
    {
        return $this->belongsTo(FeeDemand::class, 'fee_demand_id');
    }
}
