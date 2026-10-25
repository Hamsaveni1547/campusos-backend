<?php

namespace App\Modules\Communication\Channels;

final class SmsMessage
{
    public function __construct(public readonly string $text) {}
}
