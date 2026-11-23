<?php

namespace App\Services\Hr\Leave;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\HrSettings;
use App\Support\Lookup;
use Illuminate\Validation\ValidationException;

/**
 * Leave balances = sum of the append-only ledger (Guide 5.4.1: accrual, balances, transactions, adjustments).
 * Monthly accrual and year-end processing are idempotent (period_key), so a missed or repeated run changes nothing wrong.
 */
class LeaveBalanceService
{
    public function __construct(private LeavePolicyResolver $policy) {}

    private function kind(string $code): string { return Lookup::id('leave_ledger_kind', $code); }

    private function q(string $org, string $employeeId, string $typeId)
    {
        return LeaveLedgerEntry::withoutGlobalScopes()->where('organization_id', $org)->where('employee_id', $employeeId)->where('leave_type_id', $typeId);
    }

    public function balance(string $org, string $employeeId, string $typeId, ?string $asOf = null): string
    {
        $q = $this->q($org, $employeeId, $typeId);
        if ($asOf) {
            $q->whereDate('entry_date', '<=', $asOf);
        }
        return bcadd((string) $q->sum('days'), '0', 2);
    }

    /** Days of pending requests: they are reserved so that two requests cannot spend the same days. */
    public function pending(string $org, string $employeeId, string $typeId, ?string $ignoreRequestId = null): string
    {
        $q = LeaveRequest::withoutGlobalScopes()->where('organization_id', $org)->where('employee_id', $employeeId)->where('leave_type_id', $typeId)
            ->where('status_id', Lookup::id('leave_request_status', 'pending_approval'));
        if ($ignoreRequestId) {
            $q->where('id', '!=', $ignoreRequestId);
        }
        return bcadd((string) $q->sum('days'), '0', 2);
    }

    public function post(string $org, string $employeeId, string $typeId, string $kind, string $days, string $date, array $extra = []): LeaveLedgerEntry
    {
        return LeaveLedgerEntry::withoutGlobalScopes()->create([
            'organization_id' => $org, 'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'kind_id' => $this->kind($kind), 'entry_date' => $date, 'days' => $days,
            'leave_year' => LeaveYear::of($date, HrSettings::get($org, 'leave_year_start_month'))['id'],
        ] + $extra);
    }

    /** HR adjustment (opening balance, correction, compensatory day...). Always with a reason, always audited. */
    public function adjust(Employee $e, string $typeId, string $days, string $reason, User $by, string $date): LeaveLedgerEntry
    {
        if (bccomp($days, '0', 2) === 0) {
            throw ValidationException::withMessages(['days' => [__('leave.adjust_zero')]]);
        }
        if (trim(strip_tags($reason)) === '') {
            throw ValidationException::withMessages(['reason' => [__('leave.adjust_reason')]]);
        }
        $row = $this->post($e->organization_id, $e->id, $typeId, 'adjustment', $days, $date, ['reason' => $reason, 'created_by' => $by->id]);
        AuditLogger::log('leave.balance_adjusted', $row, [], ['employee_id' => $e->id, 'leave_type_id' => $typeId, 'days' => $days, 'reason' => $reason]);
        return $row;
    }

    private function accruingTypes(string $org)
    {
        return LeaveTypeSetting::withoutGlobalScopes()->with('type')->where('organization_id', $org)->where('is_active', true)->where('uses_balance', true)->where('accrues', true)->get();
    }

    /** Accrue one calendar month (YYYY-MM) for everybody employed on its last day. Safe to repeat. Returns the number of entries posted. */
    public function accrueMonth(string $org, string $month): int
    {
        $end = LeaveYear::monthEnd($month . '-01');
        $start = HrSettings::get($org, 'leave_year_start_month');
        $year = LeaveYear::of($end, $start);
        $idx = LeaveYear::monthIndex($end, $start);
        $n = 0;
        $people = Employee::withoutGlobalScopes()->where('organization_id', $org)->whereNotNull('hired_on')->whereDate('hired_on', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $end))
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'on_leave']))->get();
        foreach ($this->accruingTypes($org) as $s) {
            foreach ($people as $e) {
                $key = 'acc:' . $month;
                if (LeaveLedgerEntry::withoutGlobalScopes()->where('employee_id', $e->id)->where('leave_type_id', $s->leave_type_id)->where('kind_id', $this->kind('accrual'))->where('period_key', $key)->exists()) {
                    continue;
                }
                $annual = $this->policy->entitlementDays($e, $s->type->code, $end);
                if (bccomp($annual, '0', 2) <= 0) {
                    continue;
                }
                $hired = $e->hired_on->toDateString();
                $hireIdx = $hired >= $year['start'] ? LeaveYear::monthIndex($hired, $start) : null;
                $so = $this->q($org, $e->id, $s->leave_type_id)->where('kind_id', $this->kind('accrual'))->where('leave_year', $year['id'])->sum('days');
                $amount = LeaveEntitlement::monthlyAccrual($annual, LeaveEntitlement::eligibleMonths($idx, $hireIdx), bcadd((string) $so, '0', 2));
                if (bccomp($amount, '0', 2) > 0) {
                    $this->post($org, $e->id, $s->leave_type_id, 'accrual', $amount, $end, ['period_key' => $key, 'reason' => $month]);
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Daily job: every completed month of the current leave year that is not accrued yet (a missed run catches up). Earlier years are an opening balance. */
    public function accrueDue(): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            $tz = AttendanceService::tz($org->id);
            $today = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
            $year = LeaveYear::of($today, HrSettings::get($org->id, 'leave_year_start_month'));
            for ($m = new \DateTimeImmutable($year['start']); $m->format('Y-m') < substr($today, 0, 7); $m = $m->modify('+1 month')) {
                $n += $this->accrueMonth($org->id, $m->format('Y-m'));
            }
        }
        return $n;
    }

    /** Daily job: carry-over limit at the end of the leave year that just ended, and expiry of carried-over days. Safe to repeat. */
    public function yearEnd(): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            $startMonth = HrSettings::get($org->id, 'leave_year_start_month');
            $today = (new \DateTimeImmutable('now', new \DateTimeZone(AttendanceService::tz($org->id))))->format('Y-m-d');
            $current = LeaveYear::of($today, $startMonth);
            $prevEnd = (new \DateTimeImmutable($current['start']))->modify('-1 day')->format('Y-m-d');
            $prev = LeaveYear::of($prevEnd, $startMonth);
            foreach ($this->balanceTypes($org->id) as $s) {
                foreach (Employee::withoutGlobalScopes()->where('organization_id', $org->id)->whereNotNull('hired_on')->get() as $e) {
                    $eff = $this->policy->effective($e, $s->type->code, $prevEnd)['values'];
                    $bal = $this->balance($org->id, $e->id, $s->leave_type_id, $prevEnd);
                    $key = 'yend:' . $prev['id'];
                    $done = $this->q($org->id, $e->id, $s->leave_type_id)->where('kind_id', $this->kind('carry_forfeit'))->where('period_key', $key)->exists();
                    if (!$done && $eff['carry_over_max_days'] !== null) {
                        $f = LeaveCarryOver::forfeitAtYearEnd($bal, $eff['carry_over_max_days']);
                        if (bccomp($f, '0', 2) > 0) {
                            $this->post($org->id, $e->id, $s->leave_type_id, 'carry_forfeit', bcsub('0', $f, 2), $prevEnd, ['period_key' => $key, 'reason' => (string) $prev['id']]);
                            $bal = bcsub($bal, $f, 2);
                            $n++;
                        }
                    } elseif ($done) {
                        $bal = $this->balance($org->id, $e->id, $s->leave_type_id, $prevEnd);
                    }
                    $months = $eff['carry_over_expiry_months'];
                    $ekey = 'cexp:' . $current['id'];
                    if ($months !== null && $months > 0 && !$this->q($org->id, $e->id, $s->leave_type_id)->where('kind_id', $this->kind('carry_expiry'))->where('period_key', $ekey)->exists()) {
                        $due = (new \DateTimeImmutable($current['start']))->modify("+$months months")->modify('-1 day')->format('Y-m-d');
                        if ($due <= $today && bccomp($bal, '0', 2) > 0) {
                            $used = bcsub('0', (string) $this->q($org->id, $e->id, $s->leave_type_id)->whereIn('kind_id', [$this->kind('usage'), $this->kind('reversal')])
                                ->whereDate('entry_date', '>=', $current['start'])->whereDate('entry_date', '<=', $due)->sum('days'), 2);
                            $now = $this->balance($org->id, $e->id, $s->leave_type_id, $due);
                            $f = LeaveCarryOver::forfeitAtExpiry($bal, $used, $now);
                            if (bccomp($f, '0', 2) > 0) {
                                $this->post($org->id, $e->id, $s->leave_type_id, 'carry_expiry', bcsub('0', $f, 2), $due, ['period_key' => $ekey, 'reason' => (string) $current['id']]);
                                $n++;
                            }
                        }
                    }
                }
            }
        }
        return $n;
    }

    private function balanceTypes(string $org)
    {
        return LeaveTypeSetting::withoutGlobalScopes()->with('type')->where('organization_id', $org)->where('is_active', true)->where('uses_balance', true)->get();
    }
}
