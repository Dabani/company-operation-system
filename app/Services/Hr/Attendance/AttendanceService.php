<?php

namespace App\Services\Hr\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceNetwork;
use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Hr\HrSettings;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance and working time (Guide 5.3). Web punches use the server clock only. Raw events are never edited: the daily records are derived from them
 * (plus shifts, holidays, leave and approved corrections) and are frozen when HR validates the month, which is what payroll will rely on.
 */
class AttendanceService
{
    public function __construct(private ApprovalEngine $approvals, private LeaveLookup $leave, private ScheduleResolver $schedules) {}

    private function fail(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("attendance.$key", $params)]]);
    }

    private static array $tzCache = [];

    /** The organization's timezone (lookup code such as Africa/Kigali), falling back to the application timezone. */
    public static function tz(string $orgId): string
    {
        return self::$tzCache[$orgId] ??= (function () use ($orgId) {
            $code = Organization::find($orgId)?->timezone?->code;
            return $code && in_array($code, DateTimeZone::listIdentifiers(), true) ? $code : (string) config('app.timezone', 'UTC');
        })();
    }

    public static function forgetTimezones(): void { self::$tzCache = []; }

    private function now(): CarbonImmutable { return CarbonImmutable::instance(now())->utc(); }     // Laravel's now(): follows the test clock too
    private function localDate(string $tz, CarbonImmutable|string $utc): string
    {
        return AttendanceCalculator::localDate($tz, $utc instanceof CarbonImmutable ? $utc->format('Y-m-d H:i:s') : $utc);
    }

    private function status(string $code): string { return Lookup::id('attendance_status', $code); }

    // ---- clocking -----------------------------------------------------------------------------------------------------
    /** Current state from the last event: 'in' while the last punch was an 'in' within the pairing window. */
    public function state(Employee $e): array
    {
        $window = HrSettings::get($e->organization_id, 'pairing_window_hours') * 3600;
        $last = AttendanceEvent::where('employee_id', $e->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $in = $last && $last->direction === 'in' && $this->now()->getTimestamp() - $last->occurred_at->getTimestamp() <= $window;
        return ['state' => $in ? 'in' : 'out', 'since' => $in ? $last->occurred_at : null, 'last' => $last];
    }

    public function networkRestricted(string $orgId): bool
    {
        return AttendanceNetwork::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->exists();
    }

    public function clock(Employee $e, ?string $direction, ?string $ip): AttendanceEvent
    {
        $org = $e->organization_id;
        $tz = self::tz($org);
        $event = DB::transaction(function () use ($e, $direction, $ip, $org, $tz) {
            $now = $this->now();
            $s = $this->state($e);
            $dir = $direction ?? ($s['state'] === 'in' ? 'out' : 'in');
            if ($dir === 'in' && $s['state'] === 'in') {
                $this->fail('direction', 'already_in');
            }
            if ($dir === 'out' && $s['state'] === 'out') {
                $this->fail('direction', 'not_clocked_in');
            }
            $min = HrSettings::get($org, 'punch_min_interval_seconds');
            if ($s['last'] && $now->getTimestamp() - $s['last']->occurred_at->getTimestamp() < $min) {
                $this->fail('direction', 'too_soon');
            }
            if ($this->isValidated($org, $this->localDate($tz, $now))) {
                $this->fail('direction', 'period_validated');
            }
            $storeIp = null;
            $networks = AttendanceNetwork::where('is_active', true)->get();
            if ($networks->isNotEmpty()) {                                           // optional restriction: disclosed to staff, IP kept only while it is on
                if (!$ip || !$networks->contains(fn ($n) => Cidr::contains($n->cidr, $ip))) {
                    $this->fail('network', 'network_not_allowed');
                }
                $storeIp = $ip;
            }
            return AttendanceEvent::create(['employee_id' => $e->id, 'direction' => $dir, 'occurred_at' => $now, 'source_id' => Lookup::id('attendance_source', 'web'),
                'ip_address' => $storeIp, 'created_by' => request()->user()?->id]);
        });
        $local = $this->localDate($tz, $event->occurred_at);
        $this->recompute($e, (new DateTimeImmutable($local))->modify('-1 day')->format('Y-m-d'), $local);
        return $event;
    }

    // ---- periods ------------------------------------------------------------------------------------------------------
    public function isValidated(string $orgId, string $date): bool
    {
        return AttendancePeriod::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('reopened_at')->whereDate('month', substr($date, 0, 7) . '-01')->exists();
    }

    private function monthBounds(string $month): array
    {
        $first = $month . '-01';
        return [$first, (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d')];
    }

    /** Freeze the month: recompute everything once more, refuse while pending corrections or incomplete days remain, then lock. */
    public function validateMonth(string $orgId, string $month, User $by): AttendancePeriod
    {
        [$first, $last] = $this->monthBounds($month);
        $tz = self::tz($orgId);
        if ($last >= $this->localDate($tz, $this->now())) {
            $this->fail('month', 'month_not_over');
        }
        if ($this->isValidated($orgId, $first)) {
            $this->fail('month', 'already_validated');
        }
        $this->recomputeMonth($orgId, $month);
        $pending = AttendanceCorrection::withoutGlobalScopes()->where('organization_id', $orgId)->where('status_id', Lookup::id('attendance_correction_status', 'pending_approval'))
            ->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)->count();
        $incomplete = AttendanceDay::withoutGlobalScopes()->where('organization_id', $orgId)->where('status_id', $this->status('incomplete'))
            ->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)->count();
        if ($pending || $incomplete) {
            $this->fail('month', 'cannot_validate', ['pending' => $pending, 'incomplete' => $incomplete]);
        }
        $p = AttendancePeriod::withoutGlobalScopes()->updateOrCreate(['organization_id' => $orgId, 'month' => $first],
            ['validated_at' => $this->now(), 'validated_by' => $by->id, 'reopened_at' => null, 'reopened_by' => null, 'reopen_reason' => null]);
        AuditLogger::log('attendance.month_validated', $p, [], ['month' => $month]);
        return $p;
    }

    public function reopenMonth(string $orgId, string $month, string $reason, User $by): AttendancePeriod
    {
        [$first] = $this->monthBounds($month);
        $p = AttendancePeriod::withoutGlobalScopes()->where('organization_id', $orgId)->whereDate('month', $first)->whereNull('reopened_at')->first();
        if (!$p) {
            $this->fail('month', 'not_validated');
        }
        $p->update(['reopened_at' => $this->now(), 'reopened_by' => $by->id, 'reopen_reason' => $reason]);
        AuditLogger::log('attendance.month_reopened', $p, [], ['month' => $month, 'reason' => $reason]);
        return $p;
    }

    // ---- derived daily records --------------------------------------------------------------------------------------------
    /** Rebuild the daily records of one employee for a date range. Validated months are never touched. */
    public function recompute(Employee $e, string $from, string $to): void
    {
        $org = $e->organization_id;
        $tz = self::tz($org);
        $today = $this->localDate($tz, $this->now());
        $window = HrSettings::get($org, 'pairing_window_hours') * 3600;
        $minOt = HrSettings::get($org, 'overtime_min_minutes');

        $startUtc = AttendanceCalculator::localToUtc($tz, $from, '00:00') - 86400;
        $endUtc = AttendanceCalculator::localToUtc($tz, $to, '23:59') + 86400;
        $events = AttendanceEvent::where('employee_id', $e->id)->where('occurred_at', '>=', gmdate('Y-m-d H:i:s', $startUtc))->where('occurred_at', '<=', gmdate('Y-m-d H:i:s', $endUtc))
            ->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($x) => ['dir' => $x->direction, 'at' => $x->occurred_at->format('Y-m-d H:i:s')])->all();
        $byDate = [];
        foreach (EventPairer::pair($events, $window) as $pair) {
            $byDate[$this->localDate($tz, $pair[0])][] = $pair;
        }
        $corrections = AttendanceCorrection::where('employee_id', $e->id)->where('status_id', Lookup::id('attendance_correction_status', 'approved'))
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->orderBy('decided_at')->get()->keyBy(fn ($c) => $c->work_date->toDateString());
        $holidays = PublicHoliday::whereDate('holiday_date', '>=', $from)->whereDate('holiday_date', '<=', $to)->get()->map(fn ($h) => $h->holiday_date->toDateString())->flip();
        $shifts = $this->schedules->forRange($e->id, $from, $to);
        $locked = AttendancePeriod::where('organization_id', $org)->whereNull('reopened_at')->get()->map(fn ($p) => substr($p->month->toDateString(), 0, 7))->flip();
        $hired = $e->hired_on?->toDateString();
        $ended = $e->ended_on?->toDateString();

        foreach ($shifts as $date => $shift) {
            if ($locked->has(substr($date, 0, 7)) || ($hired && $date < $hired) || ($ended && $date > $ended)) {
                continue;
            }
            $corr = $corrections[$date] ?? null;
            $pairs = $corr ? [[$corr->requested_in_at->format('Y-m-d H:i:s'), $corr->requested_out_at->format('Y-m-d H:i:s')]] : ($byDate[$date] ?? []);
            $r = AttendanceCalculator::day([
                'tz' => $tz, 'date' => $date, 'today' => $today, 'now' => $this->now()->format('Y-m-d H:i:s'),
                'shift' => $shift ? ['start' => $shift->start_time, 'end' => $shift->end_time, 'break' => (int) $shift->break_minutes, 'auto_deduct' => (bool) $shift->auto_deduct_break, 'grace' => (int) $shift->grace_minutes] : null,
                'holiday' => $holidays->has($date), 'leave' => $this->leave->onApprovedLeave($e->id, $date), 'pairs' => $pairs, 'overtime_min' => $minOt,
            ]);
            $existing = AttendanceDay::where('employee_id', $e->id)->whereDate('work_date', $date)->first();
            if ($r['status'] === null) {
                $existing?->delete();
                continue;
            }
            $values = ['organization_id' => $org, 'shift_id' => $shift?->id, 'expected_minutes' => $r['expected'], 'first_in_at' => $r['first_in'], 'last_out_at' => $r['last_out'],
                'worked_minutes' => $r['worked'], 'late_minutes' => $r['late'], 'overtime_minutes' => $r['overtime'], 'status_id' => $this->status($r['status']),
                'is_open' => $r['open'], 'correction_id' => $corr?->id, 'computed_at' => $this->now()];
            $existing ? $existing->update($values) : AttendanceDay::create($values + ['employee_id' => $e->id, 'work_date' => $date]);
        }
    }

    public function recomputeMonth(string $orgId, string $month): int
    {
        [$first, $last] = $this->monthBounds($month);
        $n = 0;
        foreach (Employee::withoutGlobalScopes()->where('organization_id', $orgId)->get() as $e) {
            $this->recompute($e, $first, $last);
            $n++;
        }
        return $n;
    }

    /** Daily job: bring the last two completed days of every active employee up to date, which is when absences are detected. */
    public function closeRecentDays(): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            $today = $this->localDate(self::tz($org->id), $this->now());
            $from = (new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
            $to = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            foreach (Employee::withoutGlobalScopes()->where('organization_id', $org->id)->where('status_id', Lookup::id('employee_status', 'active'))->get() as $e) {
                $this->recompute($e, $from, $to);
                $n++;
            }
        }
        return $n;
    }

    // ---- corrections ------------------------------------------------------------------------------------------------------
    /** @param array{work_date:string,in_at:string,out_at:string,reason:string} $d local date-times (organization timezone) */
    public function requestCorrection(Employee $e, array $d, User $by): AttendanceCorrection
    {
        $org = $e->organization_id;
        $tz = self::tz($org);
        $date = $d['work_date'];
        if ($date > $this->localDate($tz, $this->now())) {
            $this->fail('work_date', 'correction_future');
        }
        if ($this->isValidated($org, $date)) {
            $this->fail('work_date', 'period_validated');
        }
        $in = (new DateTimeImmutable($d['in_at'], new DateTimeZone($tz)))->getTimestamp();
        $out = (new DateTimeImmutable($d['out_at'], new DateTimeZone($tz)))->getTimestamp();
        if ($out <= $in) {
            $this->fail('out_at', 'correction_order');
        }
        if ($out - $in > HrSettings::get($org, 'pairing_window_hours') * 3600) {
            $this->fail('out_at', 'correction_too_long');
        }
        $pending = Lookup::id('attendance_correction_status', 'pending_approval');
        if (AttendanceCorrection::where('employee_id', $e->id)->whereDate('work_date', $date)->where('status_id', $pending)->exists()) {
            $this->fail('work_date', 'correction_pending');
        }
        return DB::transaction(function () use ($e, $d, $by, $date, $in, $out, $pending) {
            $c = AttendanceCorrection::create(['employee_id' => $e->id, 'work_date' => $date, 'requested_in_at' => gmdate('Y-m-d H:i:s', $in), 'requested_out_at' => gmdate('Y-m-d H:i:s', $out),
                'reason' => $d['reason'], 'status_id' => $pending, 'requested_by' => $by->id]);
            AuditLogger::log('attendance.correction_requested', $c, [], ['employee_id' => $e->id, 'work_date' => $date]);
            $req = $this->approvals->submit('attendance_correction', $c->id, $by, __('attendance.approval_title', ['name' => $e->full_name, 'date' => $date]), ['department_id' => $e->department_id]);
            $c->update(['approval_request_id' => $req->id]);
            return $c->refresh();
        });
    }

    public function correctionOutcome(string $id, string $outcome): void
    {
        $c = AttendanceCorrection::withoutGlobalScopes()->find($id);
        if (!$c || $c->status_id !== Lookup::id('attendance_correction_status', 'pending_approval')) {
            return;
        }
        $date = $c->work_date->toDateString();
        if ($outcome === 'approved' && $this->isValidated($c->organization_id, $date)) {        // the month was locked while this waited: it must not rewrite what payroll uses
            $outcome = 'cancelled';
            AuditLogger::log('attendance.correction_blocked_locked', $c, [], ['work_date' => $date]);
        }
        $c->update(['status_id' => Lookup::id('attendance_correction_status', $outcome), 'decided_at' => $this->now()]);
        AuditLogger::log("attendance.correction_$outcome", $c);
        if ($outcome === 'approved') {
            $e = Employee::withoutGlobalScopes()->find($c->employee_id);
            $e && $this->recompute($e, $date, $date);
        }
    }
}
