<?php

namespace App\Providers;

use App\Console\Commands\TripsRemind;
use App\Services\Hr\Trips\TripApprovalHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 5b wiring: trip approval handler and permission, code prefixes (trip, mission authorization), daily reminder of unretired advances. */
class TripServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['trip' => TripApprovalHandler::class]),
            'cops.approval_permissions' => array_merge(config('cops.approval_permissions', []), ['trip' => 'hr.trip.approve']),
            'cops.code_prefixes' => array_merge(config('cops.code_prefixes', []), ['trip' => 'TRP', 'trip_authorization' => 'MA']),
        ]);
        $this->commands([TripsRemind::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('cops:trips-remind')->dailyAt('08:00')->withoutOverlapping());
    }
}
