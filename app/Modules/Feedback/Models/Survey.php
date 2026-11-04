<?php

namespace App\Modules\Feedback\Models;

use App\Modules\Institution\Models\Term;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A feedback survey. `course` surveys are answered once per class the student takes in the term;
 * `general` surveys once per person in the audience.
 *
 * @property string $title
 * @property string|null $description
 * @property string $kind course|general
 * @property int|null $term_id
 * @property string $audience students|staff|all
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 * @property string $status draft|open|closed
 * @property-read Term|null $term
 */
#[Fillable(['title', 'description', 'kind', 'term_id', 'audience', 'opens_on', 'closes_on'])]
class Survey extends TenantModel
{
    protected string $auditLog = 'feedback';

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date'];
    }

    public function isAnswerable(): bool
    {
        return $this->status === 'open' && today()->betweenIncluded($this->opens_on, $this->closes_on);
    }

    /**
     * @return HasMany<SurveyQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
