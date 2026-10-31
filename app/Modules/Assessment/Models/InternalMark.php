<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $institution_id
 * @property int $internal_mark_sheet_id
 * @property int $student_id
 * @property string $computed
 * @property string $final
 * @property array<string, mixed> $breakdown
 * @property-read Student $student
 */
#[Fillable(['internal_mark_sheet_id', 'student_id', 'computed', 'final', 'breakdown'])]
class InternalMark extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['computed' => 'decimal:2', 'final' => 'decimal:2', 'breakdown' => 'array'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
