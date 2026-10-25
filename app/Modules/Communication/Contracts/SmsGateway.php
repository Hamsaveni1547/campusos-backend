<?php

namespace App\Modules\Communication\Contracts;

/**
 * Sends a text message. Implementations wrap a provider (MSG91, Twilio, …).
 */
interface SmsGateway
{
    /**
     * @throws \RuntimeException when the provider rejects the message
     */
    public function send(string $mobile, string $text): void;
}
