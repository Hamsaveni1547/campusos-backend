<?php

namespace App\Modules\AI\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat with the CampusOS assistant.
 *
 * @property int $user_id
 * @property string $title
 */
#[Fillable(['user_id', 'title'])]
class AssistantConversation extends TenantModel
{
    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }
}
