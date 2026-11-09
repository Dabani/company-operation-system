<?php

namespace App\Services\Hr;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\HrReminderLog;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\Lookup;
use Carbon\CarbonImmutable;

/**
 * Probation-review and contract-expiry reminders (Guide 5.2). Each (employee, kind, due date, step) is sent once, so a missed run
 * catches up and a re-run never duplicates. Recipients: HR (hr.history.manage) and the line manager's login. In-app only for now.
 */
class HrReminders
{
    public const STEPS = [30, 14, 7, 1];
    private const KINDS = ['probation' => 'probation_end_on', 'contract' => 'contract_end_on'];

    public function run(?string $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now()->toDateString())->startOfDay();
        $sent = 0;
        foreach (self::KINDS as $kind => $col) {
            $employees = Employee::withoutGlobalScopes()->where('status_id', Lookup::id('employee_status', 'active'))
                ->whereDate($col, '>=', $today->toDateString())->whereDate($col, '<=', $today->addDays(max(self::STEPS))->toDateString())
                ->with('manager')->get();
            foreach ($employees as $e) {
                $due = CarbonImmutable::parse($e->{$col}->toDateString())->startOfDay();
                $days = (int) $today->diffInDays($due);
                $step = min(array_filter(self::STEPS, fn ($s) => $s >= $days));
                $key = ['employee_id' => $e->id, 'kind' => $kind, 'due_on' => $due->toDateString(), 'step_days' => $step];
                if (HrReminderLog::where('employee_id', $e->id)->where('kind', $kind)->whereDate('due_on', $due->toDateString())->where('step_days', $step)->exists()) {
                    continue;
                }
                foreach ($this->recipients($e) as $user) {
                    $locale = $user->language?->code ?? config('ops.default_language');
                    $params = ['name' => $e->full_name, 'date' => $due->toDateString(), 'days' => $days];
                    AppNotification::create([
                        'organization_id' => $e->organization_id, 'user_id' => $user->id, 'title' => __("hr.reminder.$kind.title", $params, $locale),
                        'body' => mb_substr(__("hr.reminder.$kind.body", $params, $locale), 0, 500), 'link' => '/hr/employees/' . $e->id,
                    ]);
                    $sent++;
                }
                HrReminderLog::create($key + ['organization_id' => $e->organization_id, 'sent_at' => now()]);
            }
        }
        return $sent;
    }

    private function recipients(Employee $e)
    {
        $roleIds = Role::withoutGlobalScopes()->where('organization_id', $e->organization_id)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'hr.history.manage'))->pluck('id');
        $ids = Membership::where('organization_id', $e->organization_id)->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))->pluck('user_id');
        if ($e->manager?->user_id) {
            $ids = $ids->push($e->manager->user_id);
        }
        return User::with('language')->whereIn('id', $ids->unique()->values())->get();
    }
}
