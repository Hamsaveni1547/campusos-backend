<?php

namespace App\Modules\QuestionBank\Models;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Models\ExamSchedule;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $paper_blueprint_id
 * @property int $course_id
 * @property int|null $exam_schedule_id
 * @property string $set_code
 * @property int $seed
 * @property string $status draft|submitted|approved|sealed
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $submitted_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $sealed_by
 * @property Carbon|null $sealed_at
 * @property string|null $encrypted_payload
 * @property string|null $wrapped_key
 * @property string|null $payload_hash
 * @property Carbon|null $created_at
 * @property-read PaperBlueprint $blueprint
 * @property-read Course $course
 * @property-read ExamSchedule|null $schedule
 * @property-read User|null $setter
 * @property-read User|null $approver
 */
#[Fillable(['paper_blueprint_id', 'course_id', 'exam_schedule_id', 'set_code', 'seed'])]
#[Hidden(['encrypted_payload', 'wrapped_key'])]
class QuestionPaper extends TenantModel
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'sealed'];

    protected string $auditLog = 'qbank';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'sealed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PaperBlueprint, $this>
     */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(PaperBlueprint::class, 'paper_blueprint_id');
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<ExamSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class, 'exam_schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<QuestionPaperItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuestionPaperItem::class)->orderBy('section')->orderBy('position');
    }

    /**
     * @return HasMany<PaperAccessLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PaperAccessLog::class)->latest('id');
    }
}
