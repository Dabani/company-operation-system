<?php

namespace App\Services\Hr\Trips;

use App\Models\Employee;
use App\Models\FileLink;
use App\Models\Organization;
use App\Models\Trip;
use App\Models\TripAdvance;
use App\Models\TripDeductionConsent;
use App\Models\TripExpense;
use App\Models\TripRate;
use App\Models\TripSettlement;
use App\Models\TripTraveler;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\CodeGenerator;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Hr\EmploymentService;
use App\Services\Hr\HrSettings;
use App\Services\Notifier;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Business trips (Guide 5.5): request -> approval -> mission authorization -> advances -> expenses -> report -> review -> settlement. Server-side, transaction-safe, audited. */
class TripService
{
    public function __construct(private ApprovalEngine $approvals) {}

    private function st(string $c): string { return Lookup::id('trip_status', $c); }
    private function adv(string $c): string { return Lookup::id('trip_advance_status', $c); }
    private function exp(string $c): string { return Lookup::id('trip_expense_status', $c); }
    private function today(string $org): string { return \Illuminate\Support\Carbon::now(AttendanceService::tz($org))->format('Y-m-d'); }
    private function code(string $lookupValueId): ?string { return \App\Models\LookupValue::find($lookupValueId)?->code; }
    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("trips.err.$key", $p)]]); }

    // ---- request ----------------------------------------------------------------------------------------------------------------------
    /** @param array $d purpose,destination,zone_id,start_date,end_date,currency_id,budget_estimate,project_id?,linked_meeting?,itinerary?,advances?[{employee_id,amount}] */
    public function request(User $by, array $d, array $travelerIds): Trip
    {
        $org = app(\App\Services\OrganizationContext::class)->organizationId();
        $travelerIds = array_values(array_unique($travelerIds));
        if ($d['end_date'] < $d['start_date']) {
            $this->fail('end_date', 'dates_inverted');
        }
        if (bccomp((string) $d['budget_estimate'], '0', 2) <= 0) {
            $this->fail('budget_estimate', 'budget_positive');
        }
        $busy = TripTraveler::withoutGlobalScopes()->whereIn('employee_id', $travelerIds)->whereHas('trip', fn ($q) => $q->withoutGlobalScopes()
            ->whereIn('status_id', [$this->st('pending_approval'), $this->st('approved'), $this->st('report_submitted')])
            ->whereDate('start_date', '<=', $d['end_date'])->whereDate('end_date', '>=', $d['start_date']))->exists();
        if ($busy) {
            $this->fail('traveler_ids', 'traveler_busy');
        }
        $advances = collect($d['advances'] ?? [])->filter(fn ($a) => bccomp((string) $a['amount'], '0', 2) > 0)->values();
        foreach ($advances as $a) {
            if (!in_array($a['employee_id'], $travelerIds, true)) {
                $this->fail('advances', 'advance_not_traveler');
            }
        }
        if ($advances->unique('employee_id')->count() !== $advances->count()) {
            $this->fail('advances', 'advance_duplicate');
        }
        if (bccomp($advances->reduce(fn ($c, $a) => bcadd($c, (string) $a['amount'], 2), '0.00'), (string) $d['budget_estimate'], 2) > 0) {
            $this->fail('advances', 'advance_above_budget');
        }
        return DB::transaction(function () use ($by, $d, $travelerIds, $advances, $org) {
            $trip = Trip::create(['requested_by' => $by->id, 'purpose' => $d['purpose'], 'destination' => $d['destination'], 'zone_id' => $d['zone_id'], 'start_date' => $d['start_date'],
                'end_date' => $d['end_date'], 'project_id' => $d['project_id'] ?? null, 'linked_meeting' => $d['linked_meeting'] ?? null, 'itinerary' => $d['itinerary'] ?? null,
                'currency_id' => $d['currency_id'], 'budget_estimate' => $d['budget_estimate'], 'status_id' => $this->st('pending_approval')]);
            foreach ($travelerIds as $eid) {
                TripTraveler::create(['trip_id' => $trip->id, 'employee_id' => $eid]);
            }
            foreach ($advances as $a) {
                TripAdvance::create(['trip_id' => $trip->id, 'employee_id' => $a['employee_id'], 'amount' => $a['amount'], 'status_id' => $this->adv('requested')]);
            }
            AuditLogger::log('trip.requested', $trip, [], ['destination' => $d['destination'], 'from' => $d['start_date'], 'to' => $d['end_date'], 'budget' => (string) $d['budget_estimate'], 'travelers' => count($travelerIds)]);
            $first = Employee::find($travelerIds[0]);
            $req = $this->approvals->submit('trip', $trip->id, $by, __('trips.approval_title', ['destination' => $d['destination'], 'from' => $d['start_date'], 'to' => $d['end_date']]),
                ['amount' => (string) $d['budget_estimate'], 'currency' => $this->code($d['currency_id']), 'department_id' => $first?->department_id, 'attributes' => ['trip_zone' => $this->code($d['zone_id'])]]);
            $trip->update(['approval_request_id' => $req->id]);
            return $trip->refresh();
        });
    }

    /** Called by the approval handler. "returned" keeps the trip pending. */
    public function outcome(string $id, string $outcome): void
    {
        $t = Trip::withoutGlobalScopes()->find($id);
        if (!$t || $t->status_id !== $this->st('pending_approval')) {
            return;
        }
        $upd = ['status_id' => $this->st($outcome)];
        if ($outcome === 'approved') {
            $upd += ['authorization_number' => CodeGenerator::next($t->organization_id, 'trip_authorization', fn ($c) => Trip::withoutGlobalScopes()->where('organization_id', $t->organization_id)->where('authorization_number', $c)->exists()),
                'authorized_at' => now()];
        }
        $t->update($upd);
        AuditLogger::log("trip.$outcome", $t, [], ['authorization' => $upd['authorization_number'] ?? null]);
    }

    public function cancel(Trip $t, User $by, string $reason, bool $manager): Trip
    {
        if ($t->status_id !== $this->st('approved')) {
            $this->fail('status', 'cancel_state');
        }
        if (!$manager && $t->start_date->toDateString() <= $this->today($t->organization_id)) {
            $this->fail('status', 'already_started');
        }
        if (TripAdvance::where('trip_id', $t->id)->where('status_id', '!=', $this->adv('requested'))->exists()) {
            $this->fail('status', 'advance_paid');                      // money has left: the trip is settled, not cancelled
        }
        $t->update(['status_id' => $this->st('cancelled'), 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
        AuditLogger::log('trip.cancelled', $t, [], ['reason' => $reason]);
        return $t->refresh();
    }

    // ---- rates and per diem ------------------------------------------------------------------------------------------------------------------
    private function rateRows(string $org): array
    {
        return TripRate::withoutGlobalScopes()->with('kind')->where('organization_id', $org)->where('is_active', true)->get()
            ->map(fn ($r) => ['kind' => $r->kind->code, 'zone' => $r->zone_id, 'grade' => $r->grade_id, 'amount' => (string) $r->amount, 'currency' => $r->currency_id,
                'valid_from' => $r->valid_from->toDateString(), 'valid_to' => $r->valid_to?->toDateString(), 'id' => $r->id])->all();
    }

    /** Per diem a traveller would get: [{employee_id, rate_id, rate, days, amount}] with rate null when the table has no rate for them. */
    public function estimate(string $org, string $zoneId, string $currencyId, string $from, string $to, array $employeeIds): array
    {
        $rates = $this->rateRows($org);
        return array_map(function ($eid) use ($rates, $zoneId, $currencyId, $from, $to) {
            $grade = EmploymentService::asOfRecord($eid, $from)?->grade_id;
            $r = TripMath::pickRate($rates, 'per_diem', $zoneId, $grade, $from, $currencyId);
            return ['employee_id' => $eid, 'rate_id' => $r['id'] ?? null, 'rate' => $r['amount'] ?? null, 'days' => TripMath::days($from, $to), 'amount' => $r ? TripMath::perDiem($r['amount'], $from, $to) : null];
        }, $employeeIds);
    }

    /** Creates (or refreshes) the per diem expense line of every traveller from the rate table. Lines already submitted or reviewed are never touched. */
    public function generatePerDiem(Trip $t, User $by): array
    {
        if ($t->status_id !== $this->st('approved')) {
            $this->fail('status', 'expenses_state');
        }
        $created = 0;
        $missing = [];
        $locked = [];
        $cat = Lookup::id('trip_expense_category', 'per_diem');
        $from = $t->start_date->toDateString();
        $to = $t->end_date->toDateString();
        foreach ($this->estimate($t->organization_id, $t->zone_id, $t->currency_id, $from, $to, $t->travelers()->pluck('employee_id')->all()) as $e) {
            $name = Employee::find($e['employee_id'])?->full_name;
            if ($e['amount'] === null) {
                $missing[] = $name;
                continue;
            }
            $line = TripExpense::where('trip_id', $t->id)->where('employee_id', $e['employee_id'])->where('is_generated', true)->first();
            if ($line && $line->status_id !== $this->exp('draft')) {
                $locked[] = $name;
                continue;
            }
            $vals = ['category_id' => $cat, 'expense_date' => $from, 'description' => __('trips.per_diem_line', ['days' => $e['days'], 'rate' => $e['rate']]), 'amount' => $e['amount']];
            $line ? $line->update($vals) : TripExpense::create($vals + ['trip_id' => $t->id, 'employee_id' => $e['employee_id'], 'is_generated' => true, 'status_id' => $this->exp('draft')]);
            $created++;
        }
        AuditLogger::log('trip.per_diem_generated', $t, [], ['lines' => $created]);
        return ['created' => $created, 'missing_rate' => array_values(array_filter($missing)), 'locked' => array_values(array_filter($locked))];
    }

    // ---- advances ----------------------------------------------------------------------------------------------------------------------------
    public function payAdvance(TripAdvance $a, string $paidOn, User $by): TripAdvance
    {
        $t = $a->trip;
        if (!in_array($t->status_id, [$this->st('approved'), $this->st('report_submitted')], true)) {
            $this->fail('status', 'advance_state');
        }
        if ($a->status_id !== $this->adv('requested')) {
            $this->fail('status', 'advance_not_requested');
        }
        $base = max($t->end_date->toDateString(), $paidOn);
        $a->update(['status_id' => $this->adv('paid'), 'paid_on' => $paidOn, 'paid_by' => $by->id, 'due_on' => TripMath::dueOn($base, HrSettings::get($t->organization_id, 'trip_advance_retire_days'))]);
        AuditLogger::log('trip.advance_paid', $a, [], ['amount' => (string) $a->amount, 'paid_on' => $paidOn, 'due_on' => $a->due_on->toDateString()]);
        return $a->refresh();
    }

    // ---- expenses and report ---------------------------------------------------------------------------------------------------------------------
    public function addExpense(Trip $t, string $employeeId, array $d): TripExpense
    {
        if ($t->status_id !== $this->st('approved')) {
            $this->fail('status', 'expenses_state');
        }
        if (!TripTraveler::where('trip_id', $t->id)->where('employee_id', $employeeId)->exists()) {
            $this->fail('employee_id', 'not_traveler');
        }
        if ($this->code($d['category_id']) === 'per_diem') {
            $this->fail('category_id', 'per_diem_generated');
        }
        if (!TripMath::withinTrip($d['expense_date'], $t->start_date->toDateString(), $t->end_date->toDateString())) {
            $this->fail('expense_date', 'outside_trip');
        }
        $x = TripExpense::create(['trip_id' => $t->id, 'employee_id' => $employeeId, 'category_id' => $d['category_id'], 'expense_date' => $d['expense_date'], 'description' => $d['description'],
            'amount' => $d['amount'], 'status_id' => $this->exp('draft')]);
        AuditLogger::log('trip.expense_added', $x, [], ['amount' => (string) $d['amount']]);
        return $x;
    }

    /** Expenses (not per diem) that still have no receipt: the report cannot be submitted until each has one. */
    public function missingReceipts(Trip $t): array
    {
        $ids = TripExpense::where('trip_id', $t->id)->where('is_generated', false)->where('status_id', '!=', $this->exp('rejected'))->pluck('id')->all();
        $have = FileLink::whereIn('link_id', $ids)->where('link_type_id', Lookup::id('file_link_type', 'trip_expense'))->pluck('link_id')->all();
        return array_values(array_diff($ids, $have));
    }

    public function submitReport(Trip $t, User $by, string $text): Trip
    {
        if ($t->status_id !== $this->st('approved')) {
            $this->fail('status', 'report_state');
        }
        if (trim(strip_tags($text)) === '') {
            $this->fail('report_text', 'report_required');
        }
        if ($missing = $this->missingReceipts($t)) {
            throw ValidationException::withMessages(['receipts' => [__('trips.err.receipts_missing', ['count' => count($missing)])]]);
        }
        DB::transaction(function () use ($t, $text) {
            TripExpense::where('trip_id', $t->id)->where('status_id', $this->exp('draft'))->update(['status_id' => $this->exp('submitted')]);
            $t->update(['status_id' => $this->st('report_submitted'), 'report_text' => $text, 'report_submitted_at' => now()]);
            AuditLogger::log('trip.report_submitted', $t);
        });
        return $t->refresh();
    }

    public function review(TripExpense $x, string $decision, ?string $note, User $by): TripExpense
    {
        if ($x->status_id !== $this->exp('submitted')) {
            $this->fail('status', 'review_state');
        }
        if ($decision === 'reject' && trim((string) $note) === '') {
            $this->fail('note', 'reject_note');
        }
        $x->update(['status_id' => $this->exp($decision === 'accept' ? 'accepted' : 'rejected'), 'review_note' => $note, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        AuditLogger::log("trip.expense_$decision" . 'ed', $x, [], ['amount' => (string) $x->amount]);
        return $x->refresh();
    }

    // ---- settlement ----------------------------------------------------------------------------------------------------------------------------------
    public function totals(Trip $t, string $employeeId): array
    {
        $accepted = (string) TripExpense::where('trip_id', $t->id)->where('employee_id', $employeeId)->where('status_id', $this->exp('accepted'))->sum('amount');
        $advance = (string) TripAdvance::where('trip_id', $t->id)->where('employee_id', $employeeId)->whereIn('status_id', [$this->adv('paid'), $this->adv('retired')])->sum('amount');
        return ['accepted_total' => bcadd($accepted, '0', 2), 'advance_total' => bcadd($advance, '0', 2)] + TripMath::settlement(bcadd($accepted, '0', 2), bcadd($advance, '0', 2));
    }

    /** $consent: {consented_on, lawful_basis}. Required for a payroll deduction, nothing else. Phase 6 never deducts without the row this creates. */
    public function settle(Trip $t, Employee $e, string $resolution, ?string $note, ?array $consent, User $by): TripSettlement
    {
        if ($t->status_id !== $this->st('report_submitted')) {
            $this->fail('status', 'settle_state');
        }
        if (!TripTraveler::where('trip_id', $t->id)->where('employee_id', $e->id)->exists()) {
            $this->fail('employee_id', 'not_traveler');
        }
        if (TripSettlement::where('trip_id', $t->id)->where('employee_id', $e->id)->exists()) {
            $this->fail('employee_id', 'already_settled');
        }
        if (TripExpense::where('trip_id', $t->id)->where('employee_id', $e->id)->where('status_id', '!=', $this->exp('accepted'))->where('status_id', '!=', $this->exp('rejected'))->exists()) {
            $this->fail('employee_id', 'unreviewed');
        }
        $tot = $this->totals($t, $e->id);
        $allowed = ['pay_employee' => ['paid_to_employee'], 'even' => ['closed_even'], 'employee_owes' => ['refunded_by_employee', 'payroll_deduction', 'waived']][$tot['direction']];
        if (!in_array($resolution, $allowed, true)) {
            $this->fail('resolution', 'resolution_not_allowed', ['allowed' => implode(', ', $allowed)]);
        }
        if ($resolution === 'waived' && trim((string) $note) === '') {
            $this->fail('note', 'waive_note');
        }
        if ($resolution === 'payroll_deduction' && (empty($consent['consented_on']) || trim((string) ($consent['lawful_basis'] ?? '')) === '')) {
            $this->fail('consent', 'consent_required');
        }
        return DB::transaction(function () use ($t, $e, $resolution, $note, $consent, $by, $tot) {
            $s = TripSettlement::create(['trip_id' => $t->id, 'employee_id' => $e->id, 'accepted_total' => $tot['accepted_total'], 'advance_total' => $tot['advance_total'], 'balance' => $tot['balance'],
                'resolution_id' => Lookup::id('trip_settlement_resolution', $resolution), 'note' => $note, 'settled_by' => $by->id, 'settled_at' => now(), 'created_at' => now()]);
            if ($resolution === 'payroll_deduction') {
                TripDeductionConsent::create(['settlement_id' => $s->id, 'employee_id' => $e->id, 'amount' => ltrim($tot['balance'], '-'), 'consented_on' => $consent['consented_on'],
                    'lawful_basis' => $consent['lawful_basis'], 'recorded_by' => $by->id, 'created_at' => now()]);
            }
            TripAdvance::where('trip_id', $t->id)->where('employee_id', $e->id)->where('status_id', $this->adv('paid'))->update(['status_id' => $this->adv('retired'), 'retired_at' => now()]);
            if (TripSettlement::where('trip_id', $t->id)->count() >= $t->travelers()->count()) {
                $t->update(['status_id' => $this->st('settled')]);
            }
            AuditLogger::log('trip.settled', $s, [], ['employee_id' => $e->id, 'balance' => $tot['balance'], 'resolution' => $resolution]);
            return $s;
        });
    }

    // ---- unretired advances ------------------------------------------------------------------------------------------------------------------------
    /** Paid advances past their due date and still not retired. */
    public function unretired(string $org)
    {
        return TripAdvance::withoutGlobalScopes()->with(['employee', 'trip'])->where('organization_id', $org)->where('status_id', $this->adv('paid'))
            ->whereDate('due_on', '<', $this->today($org))->orderBy('due_on')->get();
    }

    /** Daily job: tell the traveller and finance about each late advance, at most once a week per advance. */
    public function remindOverdue(): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            $today = $this->today($org->id);
            $finance = Notifier::usersWithPermission($org->id, 'hr.trip.finance');
            foreach ($this->unretired($org->id) as $a) {
                if ($a->last_reminded_on && $a->last_reminded_on->toDateString() > (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d')) {
                    continue;
                }
                $who = User::find($a->employee?->user_id);
                $params = ['name' => $a->employee?->full_name, 'trip' => $a->trip->destination, 'days' => TripMath::daysLate($a->due_on->toDateString(), $today)];
                Notifier::send(collect([$who])->filter()->merge($finance), 'trip_advance_overdue', $params, null, ['in_app', 'email'], '/trips/' . $a->trip_id, $org->id);
                $a->update(['last_reminded_on' => $today]);
                $n++;
            }
        }
        return $n;
    }

    /** The mission authorization as data: the screen prints it. Only for an approved (or later) trip. */
    public function authorization(Trip $t): array
    {
        abort_unless($t->authorization_number, 404);
        $t->loadMissing(['travelers.employee', 'zone', 'project']);
        return ['number' => $t->authorization_number, 'authorized_at' => $t->authorized_at?->toIso8601String(), 'purpose' => $t->purpose, 'destination' => $t->destination, 'from' => $t->start_date->toDateString(),
            'to' => $t->end_date->toDateString(), 'zone' => $t->zone->code, 'project' => $t->project?->name, 'linked_meeting' => $t->linked_meeting,
            'travelers' => $t->travelers->map(fn ($x) => ['employee_id' => $x->employee_id, 'name' => $x->employee?->full_name, 'employee_number' => $x->employee?->employee_number])->values()];
    }
}
