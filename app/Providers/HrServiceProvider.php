<?php

namespace App\Providers;

use App\Console\Commands\HrApplyEffective;
use App\Console\Commands\HrAttendanceClose;
use App\Console\Commands\HrRetentionReview;
use App\Console\Commands\HrSendReminders;
use App\Models\Employee;
use App\Observers\EmployeeObserver;
use App\Services\Hr\Attendance\CorrectionApprovalHandler;
use App\Services\Hr\Attendance\LeaveLookup;
use App\Services\Hr\Attendance\NullLeaveLookup;
use App\Services\Hr\EmploymentApprovalHandler;
use App\Services\Hr\RequisitionApprovalHandler;
use App\Services\Hr\SelectionApprovalHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 4a wiring: approval handler and permission, code prefixes, hire observer, daily jobs. */
class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), [
                'employment_change' => EmploymentApprovalHandler::class, 'workforce_requisition' => RequisitionApprovalHandler::class, 'applicant_selection' => SelectionApprovalHandler::class,
                'attendance_correction' => CorrectionApprovalHandler::class,
            ]),
            'cops.approval_permissions' => array_merge(config('cops.approval_permissions', []), [
                'employment_change' => 'hr.approve', 'workforce_requisition' => 'hr.requisition.approve', 'applicant_selection' => 'hr.selection.approve',
                'attendance_correction' => 'hr.attendance.approve',
            ]),
            'cops.code_prefixes' => array_merge(config('cops.code_prefixes', []), [
                'hr_grade' => 'GRD', 'hr_position' => 'POS', 'requisition' => 'REQ', 'vacancy' => 'VAC', 'applicant' => 'APL', 'offer' => 'OFR', 'shift' => 'SHF',
            ]),
        ]);
        Employee::observe(EmployeeObserver::class);
        $this->commands([HrApplyEffective::class, HrSendReminders::class, HrRetentionReview::class, HrAttendanceClose::class]);
        $this->app->bindIf(LeaveLookup::class, NullLeaveLookup::class);       // Phase 5 (leave) rebinds this to its own implementation
        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->command('cops:hr-apply-effective')->dailyAt('00:10')->withoutOverlapping();
            $s->command('cops:hr-reminders')->dailyAt('07:00')->withoutOverlapping();
            $s->command('cops:hr-retention-review')->dailyAt('06:30')->withoutOverlapping();
            $s->command('cops:hr-attendance-close')->dailyAt('02:30')->withoutOverlapping();     // UTC: detects absences of the last completed days
        });
    }
}
