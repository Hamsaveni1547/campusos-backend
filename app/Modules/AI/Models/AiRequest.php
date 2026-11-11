<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Log of one AI call (no prompt or answer text is kept).
 *
 * @property int $id
 * @property int|null $institution_id
 * @property int|null $user_id
 * @property string $feature
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $duration_ms
 * @property string $status ok|failed|limited
 * @property string|null $error
 * @property Carbon $created_at
 */
class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
