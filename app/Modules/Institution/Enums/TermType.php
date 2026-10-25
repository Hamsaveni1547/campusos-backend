<?php

namespace App\Modules\Institution\Enums;

enum TermType: string
{
    case Odd = 'odd';
    case Even = 'even';
    case Annual = 'annual';
    case Summer = 'summer';
}
