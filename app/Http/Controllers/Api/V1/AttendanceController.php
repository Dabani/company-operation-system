<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Hr\Attendance\AttendanceCalculator;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\Attendance\ScheduleResolver;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Clocking, my attendance, team attendance and corrections (Guide 5.3). */
class AttendanceController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function org(): string { return $this->ctx()->organizationId(); }
    private function tz(): string { return AttendanceService::tz($this->org()); }
    private function me(Request $r): ?Employee { return Employee::where('user_id', $r->user()->id)->first(); }
    private function monthOf(Request $r): array
    {
        $m = $r->query('month') ?: CarbonImmutable::instance(now())->setTimezone($this->tz())->format('Y-m');
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m), 422, 'month must be YYYY-MM');
        $first = "$m-01";
        return [$m, $first, (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d')];
    }

    private function dayShape(AttendanceDay $d): array
    {
        return ['id' => $d->id, 'employee_id' => $d->employee_id, 'employee' => $d->employee?->full_name, 'employee_number' => $d->employee?->employee_number, 'work_date' => $d->work_date->toDateString(),
            'status' => $d->status?->code, 'shift' => $d->shift?->name, 'expected_minutes' => $d->expected_minutes, 'first_in_at' => $d->first_in_at?->toIso8601String(), 'last_out_at' => $d->last_out_at?->toIso8601String(),
            'worked_minutes' => $d->worked_minutes, 'late_minutes' => $d->late_minutes, 'overtime_minutes' => $d->overtime_minutes, 'is_open' => $d->is_open, 'corrected' => $d->correction_id !== null];
    }

    // ---- me --------------------------------------------------------------------------------------------------------
    private function todayPayload(Employee $e, AttendanceService $svc, ScheduleResolver $res): array
    {
        $tz = $this->tz();
        $now = CarbonImmutable::instance(now())->utc();
        $today = $now->setTimezone($tz)->format('Y-m-d');
        $state = $svc->state($e);
        $shift = $res->forRange($e->id, $today, $today)[$today] ?? null;
        $events = AttendanceEvent::where('employee_id', $e->id)->where('occurred_at', '>=', gmdate('Y-m-d H:i:s', AttendanceCalculator::localToUtc($tz, $today, '00:00')))->orderBy('occurred_at')->get()
            ->map(fn ($x) => ['direction' => $x->direction, 'at' => $x->occurred_at->toIso8601String()]);
        $day = AttendanceDay::with(['status', 'shift', 'employee'])->where('employee_id', $e->id)->whereDate('work_date', $today)->first();
        return ['employee_id' => $e->id, 'name' => $e->full_name, 'state' => $state['state'], 'since' => $state['since']?->toIso8601String(), 'timezone' => $tz, 'server_time' => $now->toIso8601String(),
            'ip_restricted' => $svc->networkRestricted($this->org()), 'date' => $today, 'shift' => $shift ? ['name' => $shift->name, 'start' => $shift->start_time, 'end' => $shift->end_time] : null,
            'events' => $events, 'day' => $day ? $this->dayShape($day) : null];
    }

    public function today(Request $request, AttendanceService $svc, ScheduleResolver $res)
    {
        $e = $this->me($request);
        abort_unless($e, 404);
        return ['data' => $this->todayPayload($e, $svc, $res)];
    }

    public function clock(Request $request, AttendanceService $svc, ScheduleResolver $res)
    {
        $e = $this->me($request);
        abort_unless($e, 404);
        $d = $request->validate(['direction' => ['nullable', Rule::in(['in', 'out'])]]);
        $svc->clock($e, $d['direction'] ?? null, $request->ip());
        return response()->json(['data' => $this->todayPayload($e->refresh(), $svc, $res)], 201);
    }

    private function monthPayload(array $employeeIds, string $first, string $last, bool $withEmployee): array
    {
        $days = AttendanceDay::with(['status', 'shift', 'employee'])->whereIn('employee_id', $employeeIds)->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)
            ->orderBy('work_date')->get();
        return ['timezone' => $this->tz(), 'validated' => AttendancePeriod::whereNull('reopened_at')->whereDate('month', $first)->exists(), 'days' => $days->map(fn ($d) => $this->dayShape($d))->values()];
    }

    public function myDays(Request $request)
    {
        $e = $this->me($request);
        abort_unless($e, 404);
        [$m, $first, $last] = $this->monthOf($request);
        return ['data' => ['month' => $m] + $this->monthPayload([$e->id], $first, $last, false)];
    }

    // ---- team ------------------------------------------------------------------------------------------------------
    private function visible(Request $r)
    {
        return Employee::query()->visibleTo($r->user());
    }

    public function days(Request $request)
    {
        [$m, $first, $last] = $this->monthOf($request);
        $q = $this->visible($request);
        if ($id = $request->query('employee_id')) {
            $q->where('hr_employees.id', $id);
        }
        return ['data' => ['month' => $m] + $this->monthPayload($q->pluck('hr_employees.id')->all(), $first, $last, true)];
    }

    /** One line per person: counts of each status and the minutes worked, late and overtime. */
    public function summary(Request $request)
    {
        [$m, $first, $last] = $this->monthOf($request);
        $people = $this->visible($request)->orderBy('last_name')->orderBy('first_name')->get();
        $days = AttendanceDay::with('status')->whereIn('employee_id', $people->pluck('id'))->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)->get()->groupBy('employee_id');
        $rows = $people->map(function ($p) use ($days) {
            $d = $days[$p->id] ?? collect();
            $count = fn (string $s) => $d->filter(fn ($x) => $x->status?->code === $s)->count();
            return ['employee_id' => $p->id, 'employee' => $p->full_name, 'employee_number' => $p->employee_number, 'present' => $count('present'), 'late' => $count('late'), 'absent' => $count('absent'),
                'holiday' => $count('holiday'), 'leave' => $count('leave'), 'day_off' => $count('day_off'), 'incomplete' => $count('incomplete'), 'expected_minutes' => (int) $d->sum('expected_minutes'),
                'worked_minutes' => (int) $d->sum('worked_minutes'), 'late_minutes' => (int) $d->sum('late_minutes'), 'overtime_minutes' => (int) $d->sum('overtime_minutes')];
        })->values();
        $pending = AttendanceCorrection::where('status_id', Lookup::id('attendance_correction_status', 'pending_approval'))->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)
            ->whereIn('employee_id', $people->pluck('id'))->count();
        return ['data' => ['month' => $m, 'validated' => AttendancePeriod::whereNull('reopened_at')->whereDate('month', $first)->exists(), 'pending_corrections' => $pending, 'rows' => $rows]];
    }

    // ---- corrections -----------------------------------------------------------------------------------------------
    private function corrShape(AttendanceCorrection $c): array
    {
        return ['id' => $c->id, 'employee_id' => $c->employee_id, 'employee' => $c->employee?->full_name, 'work_date' => $c->work_date->toDateString(), 'in_at' => $c->requested_in_at->toIso8601String(),
            'out_at' => $c->requested_out_at->toIso8601String(), 'reason' => $c->reason, 'status' => $c->status?->code, 'requested_by' => $c->requester?->name, 'mine' => $c->requested_by === request()->user()->id,
            'approval_request_id' => $c->approval_request_id, 'timezone' => $this->tz()];
    }

    public function corrections(Request $request)
    {
        $q = AttendanceCorrection::with(['employee', 'status', 'requester'])->orderByDesc('created_at')->limit(300);
        if ($this->ctx()->can('hr.attendance.view') || $this->ctx()->can('hr.attendance.correct') || $this->ctx()->can('hr.attendance.manage')) {
            $q->whereIn('employee_id', $this->visible($request)->select('hr_employees.id'));
        } else {
            $q->where('employee_id', $this->me($request)?->id ?? '0');
        }
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('attendance_correction_status', $s));
        }
        return ['data' => $q->get()->map(fn ($c) => $this->corrShape($c))];
    }

    public function storeCorrection(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['employee_id' => ['nullable', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'work_date' => 'required|date_format:Y-m-d',
            'in_at' => 'required|date_format:Y-m-d H:i', 'out_at' => 'required|date_format:Y-m-d H:i', 'reason' => 'required|string|min:3|max:2000']);
        $mine = $this->me($request);
        if (empty($d['employee_id']) || ($mine && $d['employee_id'] === $mine->id)) {
            abort_unless($mine && $this->ctx()->can('hr.attendance.clock'), 403, 'forbidden');
            $e = $mine;
        } else {
            abort_unless($this->ctx()->can('hr.attendance.correct'), 403, 'forbidden');
            $e = $this->visible($request)->findOrFail($d['employee_id']);
        }
        $c = $svc->requestCorrection($e, $d, $request->user());
        return response()->json(['data' => $this->corrShape($c->load(['employee', 'status', 'requester']))], 201);
    }

    public function cancelCorrection(Request $request, string $id, ApprovalEngine $engine)
    {
        $c = AttendanceCorrection::findOrFail($id);
        abort_unless($c->requested_by === $request->user()->id, 403, 'forbidden');
        abort_unless($c->status_id === Lookup::id('attendance_correction_status', 'pending_approval') && $c->approval_request_id, 422);
        $engine->cancel($c->approval_request_id, $request->user());
        return ['data' => $this->corrShape($c->refresh()->load(['employee', 'status', 'requester']))];
    }
}
