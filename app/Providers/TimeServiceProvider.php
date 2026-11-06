<?php

namespace App\Providers;

use App\Console\Commands\TimersAutoStop;
use App\Services\Time\TimesheetApprovalHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 2a wiring: timesheet approval handler and the timer auto-stop job. */
class TimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config(['cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['timesheet' => TimesheetApprovalHandler::class])]);
        $this->commands([TimersAutoStop::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('cops:timers-autostop')->everyFiveMinutes()->withoutOverlapping());
    }
}
