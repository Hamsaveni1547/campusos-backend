<?php

namespace App\Modules\Academics\Models;

use App\Models\User;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A syllabus topic marked as taught to a class.
 *
 * @property int $offering_section_id
 * @property int $syllabus_topic_id
 * @property Carbon $covered_on
 * @property string|null $notes
 * @property int|null $covered_by
 * @property-read User|null $teacher
 */
#[Fillable(['offering_section_id', 'syllabus_topic_id', 'covered_on', 'notes', 'covered_by'])]
class TopicCoverage extends Model
{
    use BelongsToInstitution;

    protected $table = 'topic_coverage';

    protected function casts(): array
    {
        return ['covered_on' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'covered_by');
    }
}
