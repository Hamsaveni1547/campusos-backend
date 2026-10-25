<?php

namespace App\Modules\Communication\Gateways;

use App\Modules\Communication\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Development gateway: writes the message to the log instead of sending it.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $mobile, string $text): void
    {
        Log::info('SMS (log driver)', ['to' => $mobile, 'text' => $text]);
    }
}
