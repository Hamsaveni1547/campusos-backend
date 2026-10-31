<?php

namespace App\Modules\QuestionBank\Models;

use App\Models\User;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $institution_id
 * @property int $question_paper_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $ip
 * @property Carbon $created_at
 * @property-read User|null $user
 */
#[Fillable(['question_paper_id', 'user_id', 'action', 'ip'])]
class PaperAccessLog extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
