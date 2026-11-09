<?php

namespace App\Providers;

use App\Console\Commands\HrApplyEffective;
use App\Console\Commands\HrSendReminders;
use App\Models\Employee;
use App\Observers\EmployeeObserver;
use App\Services\Hr\EmploymentApprovalHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 4a wiring: approval handler and permission, code prefixes, hire observer, daily jobs. */
class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['employment_change' => EmploymentApprovalHandler::class]),
            'cops.approval_permissions' => array_merge(config('cops.approval_permissions', []), ['employment_change' => 'hr.approve']),
            'cops.code_prefixes' => array_merge(config('cops.code_prefixes', []), ['hr_grade' => 'GRD', 'hr_position' => 'POS']),
        ]);
        Employee::observe(EmployeeObserver::class);
        $this->commands([HrApplyEffective::class, HrSendReminders::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->command('cops:hr-apply-effective')->dailyAt('00:10')->withoutOverlapping();
            $s->command('cops:hr-reminders')->dailyAt('07:00')->withoutOverlapping();
        });
    }
}
