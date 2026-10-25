<?php

namespace App\Shared\Settings;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored value for one setting. institution_id NULL = platform default.
 */
#[Fillable(['institution_id', 'group', 'key', 'value', 'updated_by'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
