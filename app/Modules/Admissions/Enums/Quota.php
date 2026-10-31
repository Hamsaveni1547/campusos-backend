<?php

namespace App\Modules\Admissions\Enums;

enum Quota: string
{
    case Cet = 'cet';
    case Management = 'management';
    case Nri = 'nri';
    case Lateral = 'lateral';
    case Supernumerary = 'supernumerary';

    public function label(): string
    {
        return match ($this) {
            self::Cet => 'CET / Government',
            self::Management => 'Management',
            self::Nri => 'NRI',
            self::Lateral => 'Lateral entry',
            self::Supernumerary => 'Supernumerary',
        };
    }

    /**
     * Management-type quotas need the institution head's approval before an offer.
     */
    public function needsApproval(): bool
    {
        return in_array($this, [self::Management, self::Nri], true);
    }
}
