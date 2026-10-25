<?php

namespace App\Shared\Imports;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Validated = 'validated';
    case Invalid = 'invalid';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function canCommit(): bool
    {
        return $this === self::Validated;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled, self::Invalid], true);
    }
}
