<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\Audience;
use App\Modules\Institution\Enums\CalendarEventType;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Academic calendar entry: holidays, term dates, exams, deadlines, events.
 *
 * @property int|null $academic_year_id
 * @property string $title
 * @property CalendarEventType $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_holiday
 * @property Audience $audience
 */
#[Fillable(['academic_year_id', 'title', 'description', 'type', 'starts_on', 'ends_on', 'is_holiday', 'audience'])]
class CalendarEvent extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['audience' => 'all', 'is_holiday' => false];

    protected function casts(): array
    {
        return [
            'type' => CalendarEventType::class,
            'audience' => Audience::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_holiday' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
