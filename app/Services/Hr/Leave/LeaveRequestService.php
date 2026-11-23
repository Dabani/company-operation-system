<?php

namespace App\Services\Hr\Leave;

use App\Models\Employee;
use App\Models\LeavePeriod;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveTypeSetting;
use App\Models\LookupValue;
use App\Models\PublicHoliday;
use App\Models\ScheduleAssignment;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\Attendance\ScheduleResolver;
use App\Services\Hr\HrSettings;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Leave requests (Guide 5.4): preview -> submit -> approval engine -> ledger + day rows -> attendance. Server-side, transaction-safe, audited. */
class LeaveRequestService
{
    public function __construct(
        private ApprovalEngine $approvals, private LeavePolicyResolver $policy, private LeaveBalanceService $balances,
        private ScheduleResolver $schedules, private AttendanceService $attendance,
    ) {}

    private function status(string $code): string { return Lookup::id('leave_request_status', $code); }
    private function today(string $org): string { return (new \DateTimeImmutable('now', new \DateTimeZone(AttendanceService::tz($org))))->format('Y-m-d'); }

    public function settingFor(string $org, string $typeId): LeaveTypeSetting
    {
        $s = LeaveTypeSetting::withoutGlobalScopes()->with(['type', 'dayBasis'])->where('organization_id', $org)->where('leave_type_id', $typeId)->first();
        if (!$s || !$s->is_active || !$s->is_requestable) {
            throw ValidationException::withMessages(['leave_type_id' => [__('leave.type_unavailable')]]);
        }
        return $s;
    }

    private function workingMap(Employee $e, string $from, string $to): array
    {
        if (!ScheduleAssignment::withoutGlobalScopes()->where('employee_id', $e->id)->exists()) {
            return LeaveDays::weekdays($from, $to);                       // no schedule yet: Monday-Friday
        }
        return array_map(fn ($shift) => $shift !== null, $this->schedules->forRange($e->id, $from, $to));
    }

    private function holidays(string $org, string $from, string $to): array
    {
        return PublicHoliday::withoutGlobalScopes()->where('organization_id', $org)->whereDate('holiday_date', '>=', $from)->whereDate('holiday_date', '<=', $to)->get()
            ->mapWithKeys(fn ($h) => [$h->holiday_date->toDateString() => true])->all();
    }

    /** Counted dates and days of a range for this employee. */
    public function count(Employee $e, LeaveTypeSetting $s, string $from, string $to, bool $startHalf, bool $endHalf): array
    {
        $half = $s->allow_half_days;
        return LeaveDays::count($from, $to, $this->workingMap($e, $from, $to), $this->holidays($e->organization_id, $from, $to), $s->dayBasis->code, $half && $startHalf, $half && $endHalf);
    }

    private function lockedMonth(string $org, string $from, string $to): bool
    {
        for ($m = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m-d') <= $to; $m = $m->modify('+1 month')) {
            if ($this->attendance->isValidated($org, $m->format('Y-m-d') < $from ? $from : $m->format('Y-m-d'))) {
                return true;
            }
        }
        return false;
    }

    /** Peak number of colleagues of the same department away on any day of the range. */
    private function teamPeak(Employee $e, string $from, string $to, ?string $ignore): int
    {
        if (!$e->department_id) {
            return 0;
        }
        $ids = Employee::withoutGlobalScopes()->where('organization_id', $e->organization_id)->where('department_id', $e->department_id)->where('id', '!=', $e->id)->pluck('id');
        $others = LeaveRequest::withoutGlobalScopes()->where('organization_id', $e->organization_id)->whereIn('employee_id', $ids)
            ->whereIn('status_id', [$this->status('pending_approval'), $this->status('approved')])->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->get(['start_date', 'end_date']);
        $peak = 0;
        for ($d = new \DateTimeImmutable($from), $end = new \DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $peak = max($peak, $others->filter(fn ($r) => $r->start_date->toDateString() <= $k && $r->end_date->toDateString() >= $k)->count());
        }
        return $peak;
    }

    private function msg(array $items): array
    {
        return array_map(fn ($i) => ['code' => $i['code'], 'message' => __('leave.check.' . $i['code'], $i['params'])], $items);
    }

    /**
     * Everything the screen needs before submitting. $d: leave_type_id, start_date, end_date, start_half?, end_half?
     * @return array{days:string,dates:array,blockers:array,warnings:array,balance:?string,available:?string,balance_after:?string,rule_version_id:?string,missing_profile:array}
     */
    public function preview(Employee $e, array $d, bool $privileged, ?string $ignoreRequestId = null): array
    {
        $org = $e->organization_id;
        $s = $this->settingFor($org, $d['leave_type_id']);
        $from = $d['start_date'];
        $to = $d['end_date'];
        $inverted = $to < $from;
        $c = $inverted ? ['days' => '0.00', 'dates' => []] : $this->count($e, $s, $from, $to, !empty($d['start_half']), !empty($d['end_half']));
        $eff = $this->policy->effective($e, $s->type->code, $from);
        $year = LeaveYear::of($from, HrSettings::get($org, 'leave_year_start_month'));

        $overlap = !$inverted && LeaveRequest::withoutGlobalScopes()->where('employee_id', $e->id)->whereIn('status_id', [$this->status('pending_approval'), $this->status('approved')])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId))->exists();
        $blackout = LeavePeriod::withoutGlobalScopes()->where('organization_id', $org)->where('is_active', true)->where('kind_id', Lookup::id('leave_period_kind', 'blackout'))
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->where(fn ($q) => $q->whereNull('leave_type_id')->orWhere('leave_type_id', $s->leave_type_id))->value('name');
        $taken = LeaveRequest::withoutGlobalScopes()->where('employee_id', $e->id)->where('leave_type_id', $s->leave_type_id)
            ->whereIn('status_id', [$this->status('pending_approval'), $this->status('approved')])->whereDate('start_date', '>=', $year['start'])->whereDate('start_date', '<=', $year['end'])
            ->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId))->sum('days');

        $balance = $available = $after = null;
        if ($s->uses_balance) {
            $balance = $this->balances->balance($org, $e->id, $s->leave_type_id);
            $available = bcsub($balance, $this->balances->pending($org, $e->id, $s->leave_type_id, $ignoreRequestId), 2);
            $after = bcsub($available, $c['days'], 2);
        }
        $check = LeaveChecker::check([
            'start' => $from, 'end' => $to, 'days' => $c['days'], 'today' => $this->today($org), 'privileged' => $privileged,
            'hired_on' => $e->hired_on?->toDateString(), 'ended_on' => $e->ended_on?->toDateString(),
            'uses_balance' => $s->uses_balance, 'ignores_blackout' => $s->ignores_blackout, 'allow_retroactive' => $s->allow_retroactive, 'requires_document' => $s->requires_document,
            'policy' => $eff['values'], 'balance_available' => $available, 'taken_this_year' => bcadd((string) $taken, '0', 2),
            'service_months' => $e->hired_on ? LeaveEntitlement::completedMonths($e->hired_on->toDateString(), $from) : 0,
            'overlap' => $overlap, 'blackout' => $blackout, 'validated_month' => !$inverted && $this->lockedMonth($org, $from, $to),
            'team_peak' => $inverted ? 0 : $this->teamPeak($e, $from, $to, $ignoreRequestId),
        ]);
        return ['days' => $c['days'], 'dates' => $c['dates'], 'blockers' => $this->msg($check['blockers']), 'warnings' => $this->msg($check['warnings']),
            'balance' => $balance, 'available' => $available, 'balance_after' => $after, 'rule_version_id' => $eff['rule_version_id'], 'missing_profile' => $eff['missing'],
            'type' => $s->type->code];
    }

    public function request(Employee $e, array $d, User $by, bool $privileged): LeaveRequest
    {
        $p = $this->preview($e, $d, $privileged);
        if ($p['blockers']) {
            throw ValidationException::withMessages(['start_date' => array_column($p['blockers'], 'message')]);
        }
        $s = $this->settingFor($e->organization_id, $d['leave_type_id']);
        return DB::transaction(function () use ($e, $d, $by, $p, $s) {
            $r = LeaveRequest::withoutGlobalScopes()->create([
                'organization_id' => $e->organization_id, 'employee_id' => $e->id, 'leave_type_id' => $s->leave_type_id, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'],
                'start_half' => $s->allow_half_days && !empty($d['start_half']), 'end_half' => $s->allow_half_days && !empty($d['end_half']), 'days' => $p['days'],
                'status_id' => $this->status('pending_approval'), 'reason' => $d['reason'] ?? null, 'rule_version_id' => $p['rule_version_id'], 'requested_by' => $by->id,
            ]);
            AuditLogger::log('leave.requested', $r, [], ['employee_id' => $e->id, 'type' => $s->type->code, 'from' => $d['start_date'], 'to' => $d['end_date'], 'days' => $p['days']]);
            $e->loadMissing('category');
            $req = $this->approvals->submit('leave', $r->id, $by, __('leave.approval_title', ['name' => $e->full_name, 'type' => __('leave.type.' . $s->type->code), 'from' => $d['start_date'], 'to' => $d['end_date']]),
                ['department_id' => $e->department_id, 'attributes' => ['leave_type' => $s->type->code, 'leave_days' => $p['days'], 'employee_category' => $e->category?->code]]);
            $r->update(['approval_request_id' => $req->id]);
            return $r->refresh();
        });
    }

    /** Called by the approval handler. "returned" keeps the request pending. */
    public function outcome(string $id, string $outcome): void
    {
        $r = LeaveRequest::withoutGlobalScopes()->with('employee')->find($id);
        if (!$r || $r->status_id !== $this->status('pending_approval')) {
            return;
        }
        $from = $r->start_date->toDateString();
        $to = $r->end_date->toDateString();
        if ($outcome === 'approved' && $this->lockedMonth($r->organization_id, $from, $to)) {      // the month was locked while this waited: it must not rewrite what payroll uses
            $outcome = 'cancelled';
            AuditLogger::log('leave.blocked_locked', $r, [], ['from' => $from, 'to' => $to]);
        }
        DB::transaction(function () use ($r, $outcome, $from, $to) {
            $r->update(['status_id' => $this->status($outcome), 'decided_at' => now()]);
            AuditLogger::log("leave.$outcome", $r);
            if ($outcome !== 'approved') {
                return;
            }
            $s = LeaveTypeSetting::withoutGlobalScopes()->with('dayBasis')->where('organization_id', $r->organization_id)->where('leave_type_id', $r->leave_type_id)->first();
            $c = $this->count($r->employee, $s, $from, $to, $r->start_half, $r->end_half);
            $startMonth = HrSettings::get($r->organization_id, 'leave_year_start_month');
            $perYear = [];
            $firstDate = [];
            foreach ($c['dates'] as $date => $fraction) {
                LeaveRequestDay::withoutGlobalScopes()->create(['request_id' => $r->id, 'organization_id' => $r->organization_id, 'employee_id' => $r->employee_id, 'leave_date' => $date, 'fraction' => $fraction]);
                $y = LeaveYear::of($date, $startMonth)['id'];
                $perYear[$y] = bcadd($perYear[$y] ?? '0.00', $fraction, 2);
                $firstDate[$y] ??= $date;
            }
            if ($s->uses_balance) {
                foreach ($perYear as $y => $days) {
                    $this->balances->post($r->organization_id, $r->employee_id, $r->leave_type_id, 'usage', bcsub('0', $days, 2), $firstDate[$y],
                        ['request_id' => $r->id, 'period_key' => 'req:' . substr($r->id, -10) . ':' . $y]);      // 19 characters: fits period_key(20)
                }
            }
        });
        $r->employee && $this->attendance->recompute($r->employee, $from, $to);
    }

    /** Cancel an approved request: the days come back through reversal entries, the day rows go, attendance is recomputed. */
    public function cancelApproved(LeaveRequest $r, User $by, bool $isHr, string $reason): LeaveRequest
    {
        if ($r->status_id !== $this->status('approved')) {
            throw ValidationException::withMessages(['status' => [__('leave.not_approved')]]);
        }
        $from = $r->start_date->toDateString();
        $to = $r->end_date->toDateString();
        if (!$isHr && $from <= $this->today($r->organization_id)) {
            throw ValidationException::withMessages(['status' => [__('leave.already_started')]]);
        }
        if ($this->lockedMonth($r->organization_id, $from, $to)) {
            throw ValidationException::withMessages(['status' => [__('leave.check.period_validated')]]);
        }
        DB::transaction(function () use ($r, $by, $reason) {
            foreach (LeaveLedgerEntry::withoutGlobalScopes()->where('request_id', $r->id)->where('kind_id', Lookup::id('leave_ledger_kind', 'usage'))->get() as $u) {
                $this->balances->post($r->organization_id, $r->employee_id, $r->leave_type_id, 'reversal', bcsub('0', (string) $u->days, 2), $u->entry_date->toDateString(),
                    ['request_id' => $r->id, 'period_key' => 'rev:' . substr($u->id, -14), 'reason' => $reason, 'created_by' => $by->id]);
            }
            LeaveRequestDay::withoutGlobalScopes()->where('request_id', $r->id)->delete();
            $r->update(['status_id' => $this->status('cancelled'), 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
            AuditLogger::log('leave.cancelled_after_approval', $r, [], ['reason' => $reason]);
        });
        $r->employee && $this->attendance->recompute($r->employee, $from, $to);
        return $r->refresh();
    }
}
