<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /** Sends one SMS. $to = digits with country code (e.g. 250788123456). Returns the provider reference or null. Throws on failure. */
    public function send(string $to, string $message): ?string;
}
