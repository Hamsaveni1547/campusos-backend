<?php

namespace App\Modules\Institution\Enums;

enum ProgrammeLevel: string
{
    case Undergraduate = 'ug';
    case Postgraduate = 'pg';
    case Diploma = 'diploma';
    case Doctoral = 'doctoral';
    case Certificate = 'certificate';
}
