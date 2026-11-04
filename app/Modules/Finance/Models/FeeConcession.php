<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $fee_demand_id
 * @property string $amount
 * @property string $reason
 * @property string $status pending|approved|rejected
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property-read FeeDemand $demand
 * @property-read User|null $requester
 */
#[Fillable(['fee_demand_id', 'amount', 'reason'])]
class FeeConcession extends TenantModel
{
    protected string $auditLog = 'finance';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<FeeDemand, $this>
     */
    public function demand(): BelongsTo
    {
        return $this->belongsTo(FeeDemand::class, 'fee_demand_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
