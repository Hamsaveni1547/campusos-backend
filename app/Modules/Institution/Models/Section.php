<?php

namespace App\Modules\Institution\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $batch_id
 * @property string $name
 * @property int|null $capacity
 * @property int|null $class_advisor_id
 * @property-read Batch $batch
 * @property-read User|null $classAdvisor
 */
#[Fillable(['batch_id', 'name', 'capacity', 'class_advisor_id'])]
class Section extends TenantModel
{
    protected string $auditLog = 'institution';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function classAdvisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_advisor_id');
    }
}
