<?php

namespace App\Support;

use Throwable;

/** Broadcast safely: if the websocket server (Reverb) is down, requests must not fail; clients fall back to polling. */
class Realtime
{
    public static function emit(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
