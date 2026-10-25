<?php

namespace App\Modules\Institution\Enums;

enum CalendarEventType: string
{
    case Holiday = 'holiday';
    case TermStart = 'term_start';
    case TermEnd = 'term_end';
    case Examination = 'examination';
    case Deadline = 'deadline';
    case Event = 'event';
    case Other = 'other';
}
