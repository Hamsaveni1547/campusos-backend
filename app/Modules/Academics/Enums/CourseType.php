<?php

namespace App\Modules\Academics\Enums;

enum CourseType: string
{
    case Theory = 'theory';
    case Lab = 'lab';
    case TheoryWithLab = 'theory_lab';
    case Project = 'project';
    case Seminar = 'seminar';
    case Internship = 'internship';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::TheoryWithLab => 'Theory with lab',
            default => ucfirst($this->value),
        };
    }
}
