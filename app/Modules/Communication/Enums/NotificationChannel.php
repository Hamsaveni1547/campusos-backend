<?php

namespace App\Modules\Communication\Enums;

enum NotificationChannel: string
{
    case Database = 'database';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
        };
    }
}
