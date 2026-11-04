<?php

namespace App\Modules\Feedback\Models;

use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $ulid
 * @property int $survey_id
 * @property int $position
 * @property string $text
 * @property string $type rating|choice|text
 * @property list<string>|null $options
 * @property bool $required
 */
#[Fillable(['survey_id', 'position', 'text', 'type', 'options', 'required'])]
class SurveyQuestion extends Model
{
    use HasPublicId;

    public const TYPES = ['rating', 'choice', 'text'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean'];
    }
}
