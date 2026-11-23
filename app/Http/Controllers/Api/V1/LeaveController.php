<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveTypeSetting;
use App\Models\LookupType;
use App\Rules\RichTextMin;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\HrSettings;
use App\Services\Hr\Leave\LeaveBalanceService;
use App\Services\Hr\Leave\LeavePolicyResolver;
use App\Services\Hr\Leave\LeaveRequestService;
use App\Services\Hr\Leave\LeaveYear;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave requests, balances, team calendar (Guide 5.4).
 * perm: hr.leave.request (my own) - hr.leave.view (people in my scope) - hr.leave.manage (HR: on behalf, adjust, cancel approved). Scope: Employee::visibleTo.
 */
class LeaveController extends Controller
{
    public function __construct(private LeaveRequestService $svc, private LeaveBalanceService $balances, private LeavePolicyResolver $policy) {}

    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function org(): string { return $this->ctx()->organizationId(); }
    private function me(Request $r): ?Employee { return Employee::where('user_id', $r->user()->id)->first(); }
    private function visibleIds(Request $r) { return Employee::query()->visibleTo($r->user())->select('hr_employees.id'); }
    private function today(): string { return (new \DateTimeImmutable('now', new \DateTimeZone(AttendanceService::tz($this->org()))))->format('Y-m-d'); }
    private function sees(): bool { return $this->ctx()->can('hr.leave.view') || $this->ctx()->can('hr.leave.manage'); }

    private function typeRule(): array
    {
        return ['required', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', 'leave_type')->pluck('id'))];
    }

    private function shape(LeaveRequest $r): array
    {
        $r->loadMissing(['employee', 'leaveType', 'status', 'requester']);
        return ['id' => $r->id, 'code' => $r->code, 'employee_id' => $r->employee_id, 'employee' => $r->employee?->full_name, 'employee_number' => $r->employee?->employee_number,
            'leave_type' => $r->leaveType->code, 'leave_type_id' => $r->leave_type_id, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'start_half' => $r->start_half, 'end_half' => $r->end_half, 'days' => (string) $r->days, 'status' => $r->status->code, 'reason' => $r->reason, 'requested_by' => $r->requester?->name,
            'mine' => $r->requested_by === request()->user()->id, 'approval_request_id' => $r->approval_request_id, 'decided_at' => $r->decided_at?->toIso8601String(), 'cancel_reason' => $r->cancel_reason];
    }

    /** Employee a request is for: myself, or (HR) somebody in my scope. */
    private function target(Request $request, ?string $employeeId): array
    {
        $mine = $this->me($request);
        if (!$employeeId || ($mine && $employeeId === $mine->id)) {
            abort_unless($mine && $this->ctx()->can('hr.leave.request'), 403, 'forbidden');
            return [$mine, false];
        }
        abort_unless($this->ctx()->can('hr.leave.manage'), 403, 'forbidden');
        return [Employee::query()->visibleTo($request->user())->findOrFail($employeeId), true];
    }

    private function inputs(Request $request): array
    {
        return $request->validate(['employee_id' => ['nullable', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'leave_type_id' => $this->typeRule(),
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d', 'start_half' => 'boolean', 'end_half' => 'boolean',
            'reason' => ['nullable', 'string', 'max:5000']]);
    }

    // ---- me -----------------------------------------------------------------------------------------------------------------------
    public function summary(Request $request)
    {
        $e = $this->me($request);
        abort_unless($e, 404);
        $today = $this->today();
        $year = LeaveYear::of($today, HrSettings::get($this->org(), 'leave_year_start_month'));
        $rows = LeaveTypeSetting::with('type')->where('is_active', true)->where('is_requestable', true)->get()->map(function ($s) use ($e, $today) {
            $row = ['leave_type_id' => $s->leave_type_id, 'leave_type' => $s->type->code, 'uses_balance' => $s->uses_balance, 'requires_document' => $s->requires_document, 'allow_half_days' => $s->allow_half_days];
            if ($s->uses_balance) {
                $bal = $this->balances->balance($this->org(), $e->id, $s->leave_type_id);
                $pend = $this->balances->pending($this->org(), $e->id, $s->leave_type_id);
                $row += ['balance' => $bal, 'pending' => $pend, 'available' => bcsub($bal, $pend, 2), 'annual_entitlement' => $s->accrues ? $this->policy->entitlementDays($e, $s->type->code, $today) : null];
            }
            return $row;
        })->values();
        $upcoming = LeaveRequest::with(['leaveType', 'status', 'employee', 'requester'])->where('employee_id', $e->id)->whereDate('end_date', '>=', $today)
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['pending_approval', 'approved']))->orderBy('start_date')->limit(20)->get()->map(fn ($r) => $this->shape($r));
        return ['data' => ['employee_id' => $e->id, 'leave_year' => $year, 'types' => $rows, 'upcoming' => $upcoming]];
    }

    // ---- requests ------------------------------------------------------------------------------------------------------------------
    public function preview(Request $request)
    {
        $d = $this->inputs($request);
        [$e, $privileged] = $this->target($request, $d['employee_id'] ?? null);
        return ['data' => $this->svc->preview($e, $d, $privileged)];
    }

    public function store(Request $request)
    {
        $d = $this->inputs($request);
        [$e, $privileged] = $this->target($request, $d['employee_id'] ?? null);
        $r = $this->svc->request($e, $d, $request->user(), $privileged);
        return response()->json(['data' => $this->shape($r)], 201);
    }

    public function index(Request $request)
    {
        $q = LeaveRequest::with(['employee', 'leaveType', 'status', 'requester'])->orderByDesc('start_date')->limit(300);
        if ($this->sees()) {
            $q->whereIn('employee_id', $this->visibleIds($request));
        } else {
            $q->where('employee_id', $this->me($request)?->id ?? '0');
        }
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('leave_request_status', $s));
        }
        if ($id = $request->query('employee_id')) {
            $q->where('employee_id', $id);
        }
        return ['data' => $q->get()->map(fn ($r) => $this->shape($r))];
    }

    private function find(Request $request, string $id): LeaveRequest
    {
        $q = LeaveRequest::with(['employee', 'leaveType', 'status', 'requester']);
        $this->sees() ? $q->whereIn('employee_id', $this->visibleIds($request)) : $q->where('employee_id', $this->me($request)?->id ?? '0');
        return $q->findOrFail($id);      // 404 outside scope: no existence leak
    }

    public function show(Request $request, string $id) { return ['data' => $this->shape($this->find($request, $id))]; }

    public function cancel(Request $request, string $id, ApprovalEngine $engine)
    {
        $r = $this->find($request, $id);
        $hr = $this->ctx()->can('hr.leave.manage');
        abort_unless($r->requested_by === $request->user()->id || $hr, 403, 'forbidden');
        if ($r->status->code === 'pending_approval' && $r->approval_request_id) {
            abort_unless($r->requested_by === $request->user()->id, 403, 'forbidden');       // the engine lets only the requester withdraw a pending request
            $engine->cancel($r->approval_request_id, $request->user());
        } else {
            $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
            $this->svc->cancelApproved($r, $request->user(), $hr, $d['reason']);
        }
        return ['data' => $this->shape($r->refresh())];
    }

    // ---- calendar and balances -----------------------------------------------------------------------------------------------------
    public function calendar(Request $request)
    {
        abort_unless($this->sees(), 403, 'forbidden');
        $d = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if((new \DateTimeImmutable($d['from']))->diff(new \DateTimeImmutable($d['to']))->days > 62, 422, 'range too long');
        $rows = LeaveRequest::with(['employee', 'leaveType', 'status', 'requester'])->whereIn('employee_id', $this->visibleIds($request))
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['pending_approval', 'approved']))->whereDate('start_date', '<=', $d['to'])->whereDate('end_date', '>=', $d['from'])->orderBy('start_date')->limit(500)->get();
        return ['data' => $rows->map(fn ($r) => $this->shape($r))];
    }

    public function balancesList(Request $request)
    {
        abort_unless($this->sees(), 403, 'forbidden');
        $types = LeaveTypeSetting::with('type')->where('is_active', true)->where('uses_balance', true)->get();
        $people = Employee::query()->visibleTo($request->user())->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'on_leave']))->orderBy('last_name')->orderBy('first_name')->limit(300)->get();
        $rows = $people->map(fn ($p) => ['employee_id' => $p->id, 'employee' => $p->full_name, 'employee_number' => $p->employee_number,
            'balances' => $types->map(fn ($s) => ['leave_type_id' => $s->leave_type_id, 'leave_type' => $s->type->code, 'balance' => $this->balances->balance($this->org(), $p->id, $s->leave_type_id),
                'pending' => $this->balances->pending($this->org(), $p->id, $s->leave_type_id)])->values()]);
        return ['data' => $rows->values()];
    }

    public function ledger(Request $request, string $employeeId)
    {
        $mine = $this->me($request);
        if (!($mine && $mine->id === $employeeId)) {
            abort_unless($this->sees(), 403, 'forbidden');
            Employee::query()->visibleTo($request->user())->findOrFail($employeeId);
        }
        $rows = LeaveLedgerEntry::with(['leaveType', 'kind'])->where('employee_id', $employeeId)->orderByDesc('entry_date')->orderByDesc('created_at')->limit(200)->get();
        return ['data' => $rows->map(fn ($x) => ['id' => $x->id, 'leave_type' => $x->leaveType->code, 'kind' => $x->kind->code, 'entry_date' => $x->entry_date->toDateString(), 'days' => (string) $x->days,
            'leave_year' => $x->leave_year, 'reason' => $x->reason, 'request_id' => $x->request_id])];
    }

    public function adjust(Request $request)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'leave_type_id' => $this->typeRule(),
            'days' => 'required|numeric|between:-366,366', 'reason' => ['required', 'string', 'max:2000'], 'entry_date' => 'nullable|date_format:Y-m-d']);
        $e = Employee::query()->visibleTo($request->user())->findOrFail($d['employee_id']);
        abort_unless(LeaveTypeSetting::where('leave_type_id', $d['leave_type_id'])->where('uses_balance', true)->exists(), 422, __('leave.type_no_balance'));
        $row = $this->balances->adjust($e, $d['leave_type_id'], bcadd((string) $d['days'], '0', 2), $d['reason'], $request->user(), $d['entry_date'] ?? $this->today());
        return response()->json(['data' => ['id' => $row->id, 'balance' => $this->balances->balance($this->org(), $e->id, $d['leave_type_id'])]], 201);
    }
}
