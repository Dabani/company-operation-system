<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceNetwork;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDay;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\WorkSchedule;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\Attendance\Cidr;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Working-time setup: shifts, schedules and their assignments, one-day overrides, public holidays, allowed networks. Nothing here is pre-filled. */
class AttendanceSetupController extends Controller
{
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    // ---- shifts ---------------------------------------------------------------------------------------------------
    private function shiftShape(Shift $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'break_minutes' => $s->break_minutes,
            'auto_deduct_break' => $s->auto_deduct_break, 'grace_minutes' => $s->grace_minutes, 'is_active' => $s->is_active];
    }

    private function shiftRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['name' => "$req|string|max:150", 'start_time' => "$req|date_format:H:i", 'end_time' => "$req|date_format:H:i|different:start_time", 'break_minutes' => 'sometimes|integer|min:0|max:480',
            'auto_deduct_break' => 'sometimes|boolean', 'grace_minutes' => 'sometimes|integer|min:0|max:120', 'is_active' => 'sometimes|boolean'];
    }

    public function shifts() { return ['data' => Shift::orderBy('start_time')->orderBy('name')->get()->map(fn ($s) => $this->shiftShape($s))]; }

    public function storeShift(Request $request)
    {
        $s = Shift::create($request->validate($this->shiftRules(true)));
        AuditLogger::log('attendance.shift_created', $s);
        return response()->json(['data' => $this->shiftShape($s->refresh())], 201);
    }

    public function updateShift(Request $request, string $id)
    {
        $s = Shift::findOrFail($id);
        $s->update($request->validate($this->shiftRules(false)));
        AuditLogger::log('attendance.shift_updated', $s);
        return ['data' => $this->shiftShape($s->refresh())];
    }

    // ---- schedules ------------------------------------------------------------------------------------------------
    private function scheduleShape(WorkSchedule $w): array
    {
        return ['id' => $w->id, 'name' => $w->name, 'is_active' => $w->is_active, 'days' => $w->days->sortBy('weekday')->map(fn ($d) => ['weekday' => $d->weekday, 'shift_id' => $d->shift_id, 'shift' => $d->shift?->name])->values()];
    }

    private function scheduleRules(bool $create): array
    {
        return ['name' => ($create ? 'required' : 'sometimes') . '|string|max:150', 'is_active' => 'sometimes|boolean', 'days' => ($create ? 'required' : 'sometimes') . '|array|max:7', 'days.*.weekday' => 'required|integer|between:1,7|distinct',
            'days.*.shift_id' => ['required', Rule::exists('hr_shifts', 'id')->where('organization_id', $this->org())]];
    }

    public function schedules() { return ['data' => WorkSchedule::with('days.shift')->orderBy('name')->get()->map(fn ($w) => $this->scheduleShape($w))]; }

    public function storeSchedule(Request $request)
    {
        $d = $request->validate($this->scheduleRules(true));
        $w = DB::transaction(function () use ($d) {
            $w = WorkSchedule::create(collect($d)->only(['name', 'is_active'])->all());
            foreach ($d['days'] as $day) {
                ScheduleDay::create(['schedule_id' => $w->id, 'weekday' => $day['weekday'], 'shift_id' => $day['shift_id']]);
            }
            return $w;
        });
        AuditLogger::log('attendance.schedule_created', $w);
        return response()->json(['data' => $this->scheduleShape($w->load('days.shift'))], 201);
    }

    /** Changing a schedule's days changes how every assigned person is expected to work: HR recomputes the month afterwards (until it is validated). */
    public function updateSchedule(Request $request, string $id)
    {
        $w = WorkSchedule::findOrFail($id);
        $d = $request->validate($this->scheduleRules(false));
        DB::transaction(function () use ($w, $d) {
            $w->update(collect($d)->only(['name', 'is_active'])->all());
            if (isset($d['days'])) {
                ScheduleDay::where('schedule_id', $w->id)->delete();
                foreach ($d['days'] as $day) {
                    ScheduleDay::create(['schedule_id' => $w->id, 'weekday' => $day['weekday'], 'shift_id' => $day['shift_id']]);
                }
            }
        });
        AuditLogger::log('attendance.schedule_updated', $w);
        return ['data' => $this->scheduleShape($w->refresh()->load('days.shift'))];
    }

    // ---- assignments ------------------------------------------------------------------------------------------------
    public function assignments(Request $request)
    {
        $q = ScheduleAssignment::with(['schedule', 'employee'])->orderByDesc('effective_from')->orderByDesc('created_at')->limit(500);
        if ($e = $request->query('employee_id')) {
            $q->where('employee_id', $e);
        }
        return ['data' => $q->get()->map(fn ($a) => ['id' => $a->id, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name, 'schedule_id' => $a->schedule_id, 'schedule' => $a->schedule?->name,
            'effective_from' => $a->effective_from->toDateString()])];
    }

    /** Assign a schedule to several people from a date. History is kept: a new assignment never overwrites an old one. */
    public function assign(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['schedule_id' => ['required', Rule::exists('hr_schedules', 'id')->where('organization_id', $this->org())], 'effective_from' => 'required|date_format:Y-m-d',
            'employee_ids' => 'required|array|min:1|max:500', 'employee_ids.*' => ['required', 'distinct', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())]]);
        $today = now()->setTimezone(AttendanceService::tz($this->org()))->format('Y-m-d');
        foreach (Employee::whereIn('id', $d['employee_ids'])->get() as $e) {
            ScheduleAssignment::create(['employee_id' => $e->id, 'schedule_id' => $d['schedule_id'], 'effective_from' => $d['effective_from'], 'created_by' => $request->user()->id]);
            if ($d['effective_from'] <= $today) {
                $svc->recompute($e, $d['effective_from'], $today);
            }
        }
        AuditLogger::log('attendance.schedule_assigned', null, [], ['schedule_id' => $d['schedule_id'], 'employees' => count($d['employee_ids']), 'effective_from' => $d['effective_from']]);
        return response()->json(['data' => ['assigned' => count($d['employee_ids'])]], 201);
    }

    // ---- overrides ------------------------------------------------------------------------------------------------------
    public function overrides(Request $request)
    {
        $q = ShiftOverride::with(['shift'])->orderByDesc('work_date')->limit(500);
        if ($e = $request->query('employee_id')) {
            $q->where('employee_id', $e);
        }
        if ($m = $request->query('month')) {
            $q->whereDate('work_date', '>=', "$m-01")->whereDate('work_date', '<=', (new \DateTimeImmutable("$m-01"))->modify('last day of this month')->format('Y-m-d'));
        }
        return ['data' => $q->get()->map(fn ($o) => ['id' => $o->id, 'employee_id' => $o->employee_id, 'work_date' => $o->work_date->toDateString(), 'shift_id' => $o->shift_id, 'shift' => $o->shift?->name, 'reason' => $o->reason])];
    }

    public function setOverride(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'work_date' => 'required|date_format:Y-m-d',
            'shift_id' => ['nullable', Rule::exists('hr_shifts', 'id')->where('organization_id', $this->org())], 'reason' => 'nullable|string|max:255']);
        if ($svc->isValidated($this->org(), $d['work_date'])) {
            throw ValidationException::withMessages(['work_date' => [__('attendance.period_validated')]]);
        }
        $o = ShiftOverride::updateOrCreate(['employee_id' => $d['employee_id'], 'work_date' => $d['work_date']], ['shift_id' => $d['shift_id'] ?? null, 'reason' => $d['reason'] ?? null, 'created_by' => $request->user()->id]);
        $svc->recompute(Employee::findOrFail($d['employee_id']), $d['work_date'], $d['work_date']);
        AuditLogger::log('attendance.override_set', $o);
        return response()->json(['data' => ['id' => $o->id]], 201);
    }

    public function deleteOverride(string $id, AttendanceService $svc)
    {
        $o = ShiftOverride::findOrFail($id);
        $date = $o->work_date->toDateString();
        if ($svc->isValidated($this->org(), $date)) {
            throw ValidationException::withMessages(['work_date' => [__('attendance.period_validated')]]);
        }
        $o->delete();
        $svc->recompute(Employee::findOrFail($o->employee_id), $date, $date);
        AuditLogger::log('attendance.override_removed', $o);
        return response()->noContent();
    }

    // ---- public holidays ---------------------------------------------------------------------------------------------------
    public function holidays(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->year);
        return ['data' => PublicHoliday::whereDate('holiday_date', '>=', "$year-01-01")->whereDate('holiday_date', '<=', "$year-12-31")->orderBy('holiday_date')->get()
            ->map(fn ($h) => ['id' => $h->id, 'holiday_date' => $h->holiday_date->toDateString(), 'name' => $h->name])];
    }

    public function storeHoliday(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['holiday_date' => ['required', 'date_format:Y-m-d', Rule::unique('hr_public_holidays', 'holiday_date')->where('organization_id', $this->org())], 'name' => 'required|string|max:150']);
        $h = PublicHoliday::create($d);
        $svc->recomputeMonth($this->org(), substr($d['holiday_date'], 0, 7));
        AuditLogger::log('attendance.holiday_added', $h);
        return response()->json(['data' => ['id' => $h->id, 'holiday_date' => $d['holiday_date'], 'name' => $d['name']]], 201);
    }

    public function deleteHoliday(string $id, AttendanceService $svc)
    {
        $h = PublicHoliday::findOrFail($id);
        $month = substr($h->holiday_date->toDateString(), 0, 7);
        $h->delete();
        $svc->recomputeMonth($this->org(), $month);
        AuditLogger::log('attendance.holiday_removed', $h);
        return response()->noContent();
    }

    // ---- allowed networks (optional clock-in restriction) ------------------------------------------------------------------
    public function networks() { return ['data' => AttendanceNetwork::orderBy('label')->get()->map(fn ($n) => ['id' => $n->id, 'label' => $n->label, 'cidr' => $n->cidr, 'is_active' => $n->is_active])]; }

    private function networkRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['label' => "$req|string|max:100", 'cidr' => ["$req", 'string', 'max:64', fn ($a, $v, $fail) => Cidr::valid((string) $v) ?: $fail(__('attendance.cidr_invalid'))], 'is_active' => 'sometimes|boolean'];
    }

    public function storeNetwork(Request $request)
    {
        $n = AttendanceNetwork::create($request->validate($this->networkRules(true)));
        AuditLogger::log('attendance.network_added', $n);
        return response()->json(['data' => ['id' => $n->id]], 201);
    }

    public function updateNetwork(Request $request, string $id)
    {
        $n = AttendanceNetwork::findOrFail($id);
        $n->update($request->validate($this->networkRules(false)));
        AuditLogger::log('attendance.network_updated', $n);
        return ['data' => ['id' => $n->id]];
    }

    public function deleteNetwork(string $id)
    {
        $n = AttendanceNetwork::findOrFail($id);
        $n->delete();
        AuditLogger::log('attendance.network_removed', $n);
        return response()->noContent();
    }
}
