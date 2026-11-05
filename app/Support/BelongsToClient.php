<?php

namespace App\Support;

use App\Services\ClientContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client-scoped access (Guide 3.4). While a portal request is active every query is limited to the signed-in client,
 * so guessing another client's identifier yields 404. Fails closed: portal context without a client matches nothing.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope('client', function (Builder $q) {
            $ctx = app(ClientContext::class);
            if ($ctx->active()) {
                $q->where($q->getModel()->getTable() . '.client_id', $ctx->clientId() ?? '__none__');
            }
        });
    }
}
