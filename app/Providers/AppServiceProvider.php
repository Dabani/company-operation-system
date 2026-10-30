<?php

namespace App\Providers;

use App\Services\FileScanner;
use App\Services\NullFileScanner;
use App\Services\OrganizationContext;
use App\Services\Sms\AfricasTalkingSmsGateway;
use App\Services\Sms\BrevoSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);   // fresh per request / queue job

        $this->app->bind(FileScanner::class, NullFileScanner::class);   // swap for a ClamAV-backed scanner when available

        // SMS provider is a configuration choice (SMS_DRIVER), not a code change.
        $this->app->bind(SmsGateway::class, fn () => match (config('ops.sms.driver')) {
            'brevo' => new BrevoSmsGateway(),
            'africastalking' => new AfricasTalkingSmsGateway(),
            default => new LogSmsGateway(),
        });
    }

    public function boot(): void
    {
        // Reset links open the SPA, not a Laravel view.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(fn ($user, string $token) =>
            rtrim(config('ops.frontend_url'), '/') . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset()));

        // Bundle 7 - hardening
        if (app()->isProduction() && config('ops.security.force_https', true)) {
            URL::forceScheme('https');
        }

        // Scheduler jobs registered here (no routes/console.php edit needed). Keep the Bundle 3/4 lines in routes/console.php.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ops:heartbeat')->everyMinute();
            $schedule->command('ops:prune')->dailyAt('02:30')->withoutOverlapping();
        });

        $by = fn (Request $r) => $r->user()?->getAuthIdentifier() ?: $r->ip();
        RateLimiter::for('reports', fn (Request $r) => Limit::perMinute(30)->by($by($r)));
        RateLimiter::for('report-export', fn (Request $r) => Limit::perMinute(6)->by($by($r)));
        RateLimiter::for('health', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')) . '|' . $r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
    }
}
