<?php

namespace App\Shared\Files;

enum FileClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Secret = 'secret';

    public function logsDownloads(): bool
    {
        return in_array($this, [self::Confidential, self::Secret], true);
    }
}
