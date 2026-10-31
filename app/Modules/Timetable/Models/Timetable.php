<?php

namespace App\Modules\Timetable\Models;

use App\Models\User;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The weekly timetable of one section in one term.
 *
 * @property int $term_id
 * @property int $section_id
 * @property int $slot_template_id
 * @property string $status draft|published
 * @property int $version
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property-read Term $term
 * @property-read Section $section
 * @property-read SlotTemplate $template
 * @property-read User|null $publisher
 */
#[Fillable(['term_id', 'section_id', 'slot_template_id'])]
class Timetable extends TenantModel
{
    protected string $auditLog = 'timetable';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'datetime'];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
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
     * @return BelongsTo<SlotTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SlotTemplate::class, 'slot_template_id');
    }

    /**
     * @return HasMany<TimetableEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    /**
     * @return HasMany<TimetableAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(TimetableAdjustment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
