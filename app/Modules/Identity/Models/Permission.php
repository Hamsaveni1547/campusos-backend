<?php

namespace App\Modules\Identity\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property int $id
 * @property string $name
 * @property string|null $module
 * @property string|null $description
 * @property bool $is_sensitive
 */
class Permission extends SpatiePermission
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }
}
