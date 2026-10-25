<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\PeriodStatus;
use App\Modules\Institution\Enums\TermType;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A semester (or trimester / annual term) inside an academic year.
 *
 * @property int $academic_year_id
 * @property string $name
 * @property TermType $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_current
 * @property PeriodStatus $status
 * @property-read AcademicYear $academicYear
 */
#[Fillable(['academic_year_id', 'name', 'type', 'starts_on', 'ends_on', 'is_current', 'status'])]
class Term extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['status' => 'planned', 'is_current' => false];

    protected function casts(): array
    {
        return [
            'type' => TermType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'status' => PeriodStatus::class,
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
