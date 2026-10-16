<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Alternative for East Africa (often cheaper per SMS locally). Set AT_URL to the sandbox URL while testing. */
class AfricasTalkingSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): ?string
    {
        $payload = ['username' => config('ops.sms.at_username'), 'to' => '+' . ltrim($to, '+'), 'message' => $message];
        if ($from = config('ops.sms.sender')) {
            $payload['from'] = $from;
        }

        $res = Http::asForm()->withHeaders(['apiKey' => config('ops.sms.at_key'), 'Accept' => 'application/json'])
            ->timeout(15)->post(config('ops.sms.at_url'), $payload)->throw();

        $r = $res->json('SMSMessageData.Recipients.0');
        if (!$r || ($r['status'] ?? '') !== 'Success') {
            throw new RuntimeException('Africa\'s Talking: ' . ($r['status'] ?? 'no recipient result'));
        }
        return $r['messageId'] ?? null;
    }
}
