<?php

namespace App\Modules\Academics\Models;

use App\Models\User;
use App\Modules\Academics\Enums\RegistrationStatus;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's registration for one term. Every teaching module uses it to know who takes what.
 *
 * @property int $student_id
 * @property int $term_id
 * @property int $section_id
 * @property int $term_number
 * @property RegistrationStatus $status
 * @property string $total_credits
 * @property string|null $remarks
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property-read Student $student
 * @property-read Term $term
 * @property-read Section $section
 * @property-read User|null $decider
 */
#[Fillable(['student_id', 'term_id', 'section_id', 'term_number', 'total_credits'])]
class TermRegistration extends TenantModel
{
    protected string $auditLog = 'academics';

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['status' => RegistrationStatus::class, 'total_credits' => 'decimal:1', 'term_number' => 'integer', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return HasMany<CourseRegistration, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(CourseRegistration::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
