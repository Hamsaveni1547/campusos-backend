<?php

namespace App\Modules\Institution\Enums;

enum BatchStatus: string
{
    case Active = 'active';
    case Graduated = 'graduated';
    case Discontinued = 'discontinued';
}
