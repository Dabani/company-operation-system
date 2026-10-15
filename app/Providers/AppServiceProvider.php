<?php

namespace App\Providers;

use App\Services\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);   // fresh per request / queue job
    }

    public function boot(): void
    {
        // Reset links open the SPA, not a Laravel view.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(fn ($user, string $token) =>
            rtrim(config('ops.frontend_url'), '/') . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')) . '|' . $r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
    }
}
