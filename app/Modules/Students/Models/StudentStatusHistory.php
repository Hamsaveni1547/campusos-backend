<?php

namespace App\Modules\Students\Models;

use App\Models\User;
use App\Modules\Students\Enums\StudentStatus;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $student_id
 * @property StudentStatus|null $from_status
 * @property StudentStatus $to_status
 * @property Carbon $effective_on
 * @property string|null $reason
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 * @property-read User|null $changer
 */
#[Fillable(['student_id', 'from_status', 'to_status', 'effective_on', 'reason', 'changed_by'])]
class StudentStatusHistory extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $table = 'student_status_history';

    protected function casts(): array
    {
        return ['from_status' => StudentStatus::class, 'to_status' => StudentStatus::class, 'effective_on' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
