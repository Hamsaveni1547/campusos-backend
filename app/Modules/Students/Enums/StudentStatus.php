<?php

namespace App\Modules\Students\Enums;

/**
 * Enrollment lifecycle. Graduated, dropped out and transferred out are final.
 */
enum StudentStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Detained = 'detained';
    case YearBack = 'year_back';
    case Suspended = 'suspended';
    case Graduated = 'graduated';
    case DroppedOut = 'dropped_out';
    case TransferredOut = 'transferred_out';

    public function label(): string
    {
        return match ($this) {
            self::OnLeave => 'On leave',
            self::YearBack => 'Year back',
            self::DroppedOut => 'Dropped out',
            self::TransferredOut => 'Transferred out',
            default => ucfirst($this->value),
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Graduated, self::DroppedOut, self::TransferredOut], true);
    }

    /**
     * Still part of the institution (can use the student portal).
     */
    public function isEnrolled(): bool
    {
        return ! $this->isFinal();
    }

    public function canMoveTo(self $to): bool
    {
        if ($this === $to || $this->isFinal()) {
            return false;
        }

        return $this === self::Active || $to === self::Active || $to->isFinal();
    }
}
