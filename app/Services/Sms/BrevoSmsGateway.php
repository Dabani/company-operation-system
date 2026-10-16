<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/** Brevo transactional SMS: POST https://api.brevo.com/v3/transactionalSMS/send (api-key header). Credits are bought separately, priced per country. */
class BrevoSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): ?string
    {
        $res = Http::withHeaders(['api-key' => config('ops.sms.brevo_key')])
            ->acceptJson()->timeout(15)
            ->post('https://api.brevo.com/v3/transactionalSMS/send', [
                'sender' => substr(preg_replace('/[^A-Za-z0-9]/', '', config('ops.sms.sender')), 0, 11),
                'recipient' => $to,
                'content' => $message,
                'type' => 'transactional',
            ])->throw();

        return (string) ($res->json('messageId') ?? $res->json('reference') ?? '');
    }
}
