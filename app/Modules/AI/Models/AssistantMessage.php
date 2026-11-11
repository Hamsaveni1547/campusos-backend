<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message in an assistant conversation: the person's question or the assistant's answer
 * (with the names of the tools it used to look things up).
 *
 * @property int $assistant_conversation_id
 * @property string $role user|assistant
 * @property string $content
 * @property list<string>|null $tools
 * @property Carbon $created_at
 */
class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tools' => 'array', 'created_at' => 'datetime'];
    }
}
