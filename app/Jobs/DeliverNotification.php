<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Services\Sms\SmsGateway;
use App\Support\Lookup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 180];

    public function __construct(public string $deliveryId) {}

    public function handle(SmsGateway $sms): void
    {
        $d = NotificationDelivery::withoutGlobalScopes()->with('channel')->findOrFail($this->deliveryId);
        $d->increment('attempts');

        try {
            $ref = null;
            if ($d->channel->code === 'email') {
                Mail::to($d->recipient)->send(new NotificationMail($d->subject, $d->body, $d->action_url, $d->locale));
            } else {
                $ref = $sms->send($d->recipient, $d->body);
            }
            $d->update(['status_id' => Lookup::id('delivery_status', 'sent'), 'sent_at' => now(), 'provider_reference' => $ref, 'error' => null]);
        } catch (Throwable $e) {
            $d->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;                      // let the queue retry with backoff
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationDelivery::withoutGlobalScopes()->whereKey($this->deliveryId)
            ->update(['status_id' => Lookup::id('delivery_status', 'failed'), 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
