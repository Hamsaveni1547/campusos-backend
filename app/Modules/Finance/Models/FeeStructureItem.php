<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $fee_structure_id
 * @property int $fee_head_id
 * @property string $amount
 * @property-read FeeHead $head
 */
#[Fillable(['fee_structure_id', 'fee_head_id', 'amount'])]
class FeeStructureItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<FeeHead, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class, 'fee_head_id');
    }
}
