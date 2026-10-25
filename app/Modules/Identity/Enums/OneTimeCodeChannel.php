<?php

namespace App\Modules\Identity\Enums;

enum OneTimeCodeChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
