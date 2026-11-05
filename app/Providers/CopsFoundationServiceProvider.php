<?php

namespace App\Providers;

use App\Console\Commands\ApprovalsEscalate;
use App\Console\Commands\ClientIdentityCreate;
use App\Console\Commands\RulesExpire;
use App\Events\ApprovalFinalised;
use App\Services\Approvals\OutcomeDispatcher;
use App\Services\ClamAvScanner;
use App\Services\ClientContext;
use App\Services\FileScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 0 wiring. Registered in bootstrap/providers.php by scripts/apply-bundle-08.py. */
class CopsFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/cops.php', 'cops');
        $this->app->scoped(ClientContext::class);                        // fresh per request / queue job

        // Real malware scanning when enabled; AppServiceProvider keeps the permissive default otherwise.
        if (config('ops.clamav.enabled')) {
            $this->app->bind(FileScanner::class, ClamAvScanner::class);
        }
    }

    public function boot(): void
    {
        $this->commands([ApprovalsEscalate::class, RulesExpire::class, ClientIdentityCreate::class]);
        Event::listen(ApprovalFinalised::class, [OutcomeDispatcher::class, 'handle']);

        RateLimiter::for('portal', fn (Request $r) => Limit::perMinute(60)->by($r->user('client')?->getAuthIdentifier() ?: $r->ip()));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('cops:approvals-escalate')->hourly()->withoutOverlapping();
            $schedule->command('cops:rules-expire')->dailyAt('01:15')->withoutOverlapping();
        });
    }
}
