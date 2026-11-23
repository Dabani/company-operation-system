<?php

namespace App\Providers;

use App\Console\Commands\LeaveAccrue;
use App\Console\Commands\LeaveYearEnd;
use App\Services\Hr\Attendance\LeaveLookup;
use App\Services\Hr\Leave\DbLeaveLookup;
use App\Services\Hr\Leave\LeaveApprovalHandler;
use App\Services\Hr\Leave\LeavePolicyResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 5a wiring: leave approval handler and permission, request code prefix, attendance leave lookup, daily accrual and year-end jobs. */
class LeaveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeaveLookup::class, DbLeaveLookup::class);        // registered before HrServiceProvider's bindIf(NullLeaveLookup) runs
        $this->app->scoped(LeavePolicyResolver::class);                    // its statutory-rule cache lives for one request or job only
    }

    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['leave' => LeaveApprovalHandler::class]),
            'cops.approval_permissions' => array_merge(config('cops.approval_permissions', []), ['leave' => 'hr.leave.approve']),
            'cops.code_prefixes' => array_merge(config('cops.code_prefixes', []), ['leave_request' => 'LVE']),
        ]);
        $this->commands([LeaveAccrue::class, LeaveYearEnd::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->command('cops:leave-accrue')->dailyAt('01:30')->withoutOverlapping();       // idempotent: a missed day catches up
            $s->command('cops:leave-year-end')->dailyAt('02:00')->withoutOverlapping();
        });
    }
}
