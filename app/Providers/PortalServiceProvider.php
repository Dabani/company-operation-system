<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 3: rate limits for the external portal (Guide 8.6). */
class PortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $key = fn (Request $r) => strtolower((string) $r->input('email')) . '|' . $r->ip();
        RateLimiter::for('portal-auth', fn (Request $r) => [Limit::perMinute(10)->by($key($r))]);
        RateLimiter::for('portal-mfa', fn (Request $r) => [Limit::perMinute(5)->by(($r->user('client')?->getAuthIdentifier() ?: 'guest') . '|' . $r->ip())]);
    }
}
