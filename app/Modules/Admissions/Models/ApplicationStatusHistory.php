<?php

namespace App\Modules\Admissions\Models;

use App\Models\User;
use App\Modules\Admissions\Enums\ApplicationStatus;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $application_id
 * @property ApplicationStatus|null $from_status
 * @property ApplicationStatus $to_status
 * @property string|null $remarks
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 * @property-read User|null $changer
 */
#[Fillable(['application_id', 'from_status', 'to_status', 'remarks', 'changed_by'])]
class ApplicationStatusHistory extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $table = 'application_status_history';

    protected function casts(): array
    {
        return ['from_status' => ApplicationStatus::class, 'to_status' => ApplicationStatus::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
