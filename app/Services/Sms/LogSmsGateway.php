<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the SMS to storage/logs instead of sending it. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): ?string
    {
        Log::info("[SMS to {$to}] {$message}");
        return 'log-' . uniqid();
    }
}
