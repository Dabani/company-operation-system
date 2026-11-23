<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\Employee;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\Trip;
use App\Models\TripAdvance;
use App\Models\TripBooking;
use App\Models\TripExpense;
use App\Models\TripSettlement;
use App\Models\TripTraveler;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Hr\Trips\TripMath;
use App\Services\Hr\Trips\TripService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Business trips and expenses (Guide 5.5).
 * perm: hr.trip.request (my trips and expenses) - hr.trip.view (trips of people in my scope) - hr.trip.finance (advances, expense review, settlement, all trips) - hr.trip.configure (rates).
 */
class TripController extends Controller
{
    public function __construct(private TripService $svc) {}

    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function org(): string { return $this->ctx()->organizationId(); }
    private function finance(): bool { return $this->ctx()->can('hr.trip.finance'); }
    private function me(Request $r): ?Employee { return Employee::where('user_id', $r->user()->id)->first(); }
    private function visibleEmployeeIds(Request $r) { return Employee::query()->visibleTo($r->user())->select('hr_employees.id'); }
    private function lookup(string $type): array { return ['required', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))]; }

    /** Trips the user may see: finance sees all; otherwise the ones they requested or where a traveller is in their scope (404 outside it). */
    private function scoped(Request $r)
    {
        $q = Trip::query();
        if (!$this->finance()) {
            $q->where(fn ($w) => $w->where('requested_by', $r->user()->id)->orWhereHas('travelers', fn ($t) => $t->whereIn('employee_id', $this->visibleEmployeeIds($r))));
        }
        return $q;
    }

    private function find(Request $r, string $id): Trip { return $this->scoped($r)->with(['status', 'zone', 'currency', 'requester', 'project'])->findOrFail($id); }

    private function canEdit(Request $r, Trip $t, ?string $employeeId = null): bool
    {
        return $this->finance() || $t->requested_by === $r->user()->id || ($employeeId && $employeeId === $this->me($r)?->id);
    }

    private function shape(Trip $t, bool $detail = false): array
    {
        $t->loadMissing(['status', 'zone', 'currency', 'requester', 'project']);
        $today = \Illuminate\Support\Carbon::now(\App\Services\Hr\Attendance\AttendanceService::tz($t->organization_id))->format('Y-m-d');
        $out = ['id' => $t->id, 'code' => $t->code, 'purpose' => $t->purpose, 'destination' => $t->destination, 'zone' => $t->zone->code, 'zone_id' => $t->zone_id, 'start_date' => $t->start_date->toDateString(),
            'end_date' => $t->end_date->toDateString(), 'currency' => $t->currency->code, 'budget_estimate' => (string) $t->budget_estimate, 'status' => $t->status->code, 'requested_by' => $t->requester?->name,
            'authorization_number' => $t->authorization_number, 'project' => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name] : null, 'approval_request_id' => $t->approval_request_id,
            'travelers' => TripTraveler::where('trip_id', $t->id)->with('employee')->get()->map(fn ($x) => ['employee_id' => $x->employee_id, 'name' => $x->employee?->full_name])->values()];
        if (!$detail) {
            return $out;
        }
        $adv = TripAdvance::with(['status', 'employee'])->where('trip_id', $t->id)->get()->map(fn ($a) => ['id' => $a->id, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name, 'amount' => (string) $a->amount,
            'status' => $a->status->code, 'paid_on' => $a->paid_on?->toDateString(), 'due_on' => $a->due_on?->toDateString(),
            'overdue' => TripMath::overdue($a->due_on?->toDateString(), $a->status->code === 'retired', $today), 'days_late' => $a->due_on && TripMath::overdue($a->due_on->toDateString(), $a->status->code === 'retired', $today) ? TripMath::daysLate($a->due_on->toDateString(), $today) : 0]);
        $exp = TripExpense::with(['category', 'status', 'employee'])->where('trip_id', $t->id)->orderBy('expense_date')->get();
        $missing = $this->svc->missingReceipts($t);
        return $out + ['itinerary' => $t->itinerary, 'linked_meeting' => $t->linked_meeting, 'report_text' => $t->report_text, 'cancel_reason' => $t->cancel_reason, 'advances' => $adv,
            'bookings' => TripBooking::with('kind')->where('trip_id', $t->id)->get()->map(fn ($b) => ['id' => $b->id, 'kind' => $b->kind->code, 'provider' => $b->provider, 'reference' => $b->reference,
                'starts_on' => $b->starts_on?->toDateString(), 'ends_on' => $b->ends_on?->toDateString(), 'amount' => $b->amount === null ? null : (string) $b->amount, 'notes' => $b->notes])->values(),
            'expenses' => $exp->map(fn ($x) => ['id' => $x->id, 'employee_id' => $x->employee_id, 'employee' => $x->employee?->full_name, 'category' => $x->category->code, 'expense_date' => $x->expense_date->toDateString(),
                'description' => $x->description, 'amount' => (string) $x->amount, 'status' => $x->status->code, 'is_generated' => $x->is_generated, 'review_note' => $x->review_note, 'needs_receipt' => in_array($x->id, $missing, true)])->values(),
            'settlements' => TripSettlement::with(['resolution', 'consent'])->where('trip_id', $t->id)->get()->map(fn ($s) => ['employee_id' => $s->employee_id, 'accepted_total' => (string) $s->accepted_total, 'advance_total' => (string) $s->advance_total,
                'balance' => (string) $s->balance, 'resolution' => $s->resolution->code, 'has_consent' => (bool) $s->consent, 'note' => $s->note])->values()];
    }

    // ---- list, request ----------------------------------------------------------------------------------------------------------------------
    public function index(Request $request)
    {
        $q = $this->scoped($request)->with(['status', 'zone', 'currency', 'requester', 'project'])->orderByDesc('start_date')->limit(300);
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('trip_status', $s));
        }
        return ['data' => $q->get()->map(fn ($t) => $this->shape($t))];
    }

    public function show(Request $request, string $id) { return ['data' => $this->shape($this->find($request, $id), true)]; }

    public function estimate(Request $request)
    {
        $d = $request->validate(['zone_id' => $this->lookup('trip_zone'), 'currency_id' => $this->lookup('currency'), 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'traveler_ids' => 'required|array|min:1|max:50', 'traveler_ids.*' => 'string']);
        $ids = Employee::query()->visibleTo($request->user())->whereIn('id', $d['traveler_ids'])->pluck('id')->all();
        return ['data' => $this->svc->estimate($this->org(), $d['zone_id'], $d['currency_id'], $d['start_date'], $d['end_date'], $ids)];
    }

    public function store(Request $request)
    {
        $d = $request->validate(['purpose' => 'required|string|max:5000', 'destination' => 'required|string|max:200', 'zone_id' => $this->lookup('trip_zone'), 'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d', 'currency_id' => $this->lookup('currency'), 'budget_estimate' => 'required|numeric|min:0.01|max:9999999999999',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('organization_id', $this->org())], 'linked_meeting' => 'nullable|string|max:200', 'itinerary' => 'nullable|string|max:10000',
            'traveler_ids' => 'nullable|array|max:50', 'traveler_ids.*' => 'string', 'advances' => 'nullable|array|max:50', 'advances.*.employee_id' => 'required|string', 'advances.*.amount' => 'required|numeric|min:0|max:9999999999999']);
        $mine = $this->me($request);
        $ids = $d['traveler_ids'] ?? ($mine ? [$mine->id] : []);
        abort_if($ids === [], 422, __('trips.err.no_traveler'));
        $ok = Employee::query()->visibleTo($request->user())->whereIn('id', $ids)->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'on_leave']))->pluck('id')->all();
        abort_if(count($ok) !== count(array_unique($ids)), 422, __('trips.err.traveler_scope'));
        $t = $this->svc->request($request->user(), $d, $ids);
        return response()->json(['data' => $this->shape($t, true)], 201);
    }

    public function cancel(Request $request, string $id, ApprovalEngine $engine)
    {
        $t = $this->find($request, $id);
        abort_unless($t->requested_by === $request->user()->id || $this->finance(), 403, 'forbidden');
        if ($t->status->code === 'pending_approval' && $t->approval_request_id) {
            abort_unless($t->requested_by === $request->user()->id, 403, 'forbidden');
            $engine->cancel($t->approval_request_id, $request->user());
        } else {
            $d = $request->validate(['reason' => 'required|string|max:2000']);
            $this->svc->cancel($t, $request->user(), $d['reason'], $this->finance());
        }
        return ['data' => $this->shape($t->refresh(), true)];
    }

    public function authorization(Request $request, string $id) { return ['data' => $this->svc->authorization($this->find($request, $id))]; }

    // ---- bookings ------------------------------------------------------------------------------------------------------------------------------
    public function addBooking(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        abort_unless($this->canEdit($request, $t), 403, 'forbidden');
        abort_if(in_array($t->status->code, ['rejected', 'cancelled', 'settled'], true), 422, __('trips.err.status_closed'));
        $d = $request->validate(['kind_id' => $this->lookup('trip_booking_kind'), 'provider' => 'required|string|max:150', 'reference' => 'nullable|string|max:100', 'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'amount' => 'nullable|numeric|min:0|max:9999999999999', 'notes' => 'nullable|string|max:2000']);
        $b = TripBooking::create($d + ['trip_id' => $t->id, 'created_by' => $request->user()->id]);
        AuditLogger::log('trip.booking_added', $b, [], ['provider' => $d['provider']]);
        return response()->json(['data' => ['id' => $b->id]], 201);
    }

    public function deleteBooking(Request $request, string $id, string $bookingId)
    {
        $t = $this->find($request, $id);
        abort_unless($this->canEdit($request, $t), 403, 'forbidden');
        $b = TripBooking::where('trip_id', $t->id)->findOrFail($bookingId);
        AuditLogger::log('trip.booking_removed', $b, ['provider' => $b->provider]);
        $b->delete();
        return response()->noContent();
    }

    // ---- advances (finance) ------------------------------------------------------------------------------------------------------------------
    public function payAdvance(Request $request, string $id, string $advanceId)
    {
        $t = $this->find($request, $id);
        $d = $request->validate(['paid_on' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $a = TripAdvance::with('trip')->where('trip_id', $t->id)->findOrFail($advanceId);
        return ['data' => $this->shape($this->svc->payAdvance($a, $d['paid_on'], $request->user())->trip->refresh(), true)];
    }

    public function unretired(Request $request)
    {
        return ['data' => $this->svc->unretired($this->org())->map(fn ($a) => ['advance_id' => $a->id, 'trip_id' => $a->trip_id, 'trip' => $a->trip->destination, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name,
            'amount' => (string) $a->amount, 'due_on' => $a->due_on->toDateString(), 'days_late' => TripMath::daysLate($a->due_on->toDateString(), now()->toDateString())])->values()];
    }

    // ---- expenses and report --------------------------------------------------------------------------------------------------------------------
    public function perDiem(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        abort_unless($this->canEdit($request, $t), 403, 'forbidden');
        return ['data' => $this->svc->generatePerDiem($t, $request->user())];
    }

    public function addExpense(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        $d = $request->validate(['employee_id' => 'nullable|string', 'category_id' => $this->lookup('trip_expense_category'), 'expense_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0.01|max:9999999999999']);
        $eid = $d['employee_id'] ?? $this->me($request)?->id;
        abort_unless($eid && $this->canEdit($request, $t, $eid), 403, 'forbidden');
        $x = $this->svc->addExpense($t, $eid, $d);
        return response()->json(['data' => ['id' => $x->id]], 201);
    }

    private function draftExpense(Request $request, string $expenseId): TripExpense
    {
        $x = TripExpense::with('trip')->findOrFail($expenseId);
        $this->find($request, $x->trip_id);                                           // scope: 404 outside it
        abort_unless($this->canEdit($request, $x->trip, $x->employee_id), 403, 'forbidden');
        abort_if($x->status_id !== Lookup::id('trip_expense_status', 'draft') || $x->is_generated, 422, __('trips.err.expense_locked'));
        return $x;
    }

    public function updateExpense(Request $request, string $expenseId)
    {
        $x = $this->draftExpense($request, $expenseId);
        $d = $request->validate(['expense_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0.01|max:9999999999999']);
        abort_unless(TripMath::withinTrip($d['expense_date'], $x->trip->start_date->toDateString(), $x->trip->end_date->toDateString()), 422, __('trips.err.outside_trip'));
        $x->update($d);
        AuditLogger::log('trip.expense_updated', $x, [], ['amount' => (string) $d['amount']]);
        return response()->noContent();
    }

    public function deleteExpense(Request $request, string $expenseId)
    {
        $x = $this->draftExpense($request, $expenseId);
        AuditLogger::log('trip.expense_removed', $x, ['amount' => (string) $x->amount]);
        $x->delete();
        return response()->noContent();
    }

    public function expenseFiles(Request $request, string $expenseId)
    {
        $x = TripExpense::findOrFail($expenseId);
        $this->find($request, $x->trip_id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'trip_expense'))->where('link_id', $x->id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function uploadReceipt(Request $request, string $expenseId)
    {
        $x = TripExpense::with('trip')->findOrFail($expenseId);
        $this->find($request, $x->trip_id);
        abort_unless($this->canEdit($request, $x->trip, $x->employee_id), 403, 'forbidden');
        abort_if($x->status_id === Lookup::id('trip_expense_status', 'accepted'), 422, __('trips.err.expense_locked'));
        $request->validate(['file' => 'required|file']);
        $asset = FileService::store($request->file('file'), $request->user(), ['classification_id' => Lookup::id('file_classification', 'confidential')], ['type' => 'trip_expense', 'id' => $x->id])
            ->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'trip_expense' => $x->id]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    public function submitReport(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        abort_unless($t->requested_by === $request->user()->id || $this->finance(), 403, 'forbidden');
        $d = $request->validate(['report_text' => 'required|string|max:20000']);
        return ['data' => $this->shape($this->svc->submitReport($t, $request->user(), $d['report_text']), true)];
    }

    // ---- finance: review and settlement ---------------------------------------------------------------------------------------------------------
    public function review(Request $request, string $expenseId)
    {
        $x = TripExpense::findOrFail($expenseId);
        $this->find($request, $x->trip_id);
        $d = $request->validate(['decision' => 'required|in:accept,reject', 'note' => 'nullable|string|max:2000']);
        $this->svc->review($x, $d['decision'], $d['note'] ?? null, $request->user());
        return response()->noContent();
    }

    public function settlementPreview(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        $d = $request->validate(['employee_id' => 'required|string']);
        abort_unless(TripTraveler::where('trip_id', $t->id)->where('employee_id', $d['employee_id'])->exists(), 404);
        return ['data' => $this->svc->totals($t, $d['employee_id'])];
    }

    public function settle(Request $request, string $id)
    {
        $t = $this->find($request, $id);
        $d = $request->validate(['employee_id' => 'required|string', 'resolution' => 'required|in:paid_to_employee,refunded_by_employee,payroll_deduction,waived,closed_even', 'note' => 'nullable|string|max:2000',
            'consent' => 'nullable|array', 'consent.consented_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'consent.lawful_basis' => 'nullable|string|max:2000']);
        $e = Employee::findOrFail($d['employee_id']);
        $this->svc->settle($t, $e, $d['resolution'], $d['note'] ?? null, $d['consent'] ?? null, $request->user());
        return ['data' => $this->shape($t->refresh(), true)];
    }
}
