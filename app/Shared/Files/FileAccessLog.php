<?php

namespace App\Shared\Files;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['file_id', 'user_id', 'action', 'ip_address', 'user_agent', 'created_at'])]
class FileAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
