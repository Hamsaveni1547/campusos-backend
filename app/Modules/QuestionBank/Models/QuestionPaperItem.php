<?php

namespace App\Modules\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $question_paper_id
 * @property int $question_id
 * @property int $section
 * @property int $position
 * @property-read Question $question
 */
#[Fillable(['question_paper_id', 'question_id', 'section', 'position'])]
class QuestionPaperItem extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
