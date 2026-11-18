<?php

namespace App\Services\Procurement;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionHistory;
use App\Models\PurchaseRequisitionLine;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase requisitions (Guide 7.4 steps 2-3): the requester states the need, the cost centre and the lines; the budget is checked when the requisition is
 * submitted. A requisition over its project budget is not blocked silently: it needs a written exception reason and is flagged for the approver.
 * Approval goes through the approval engine, so delegation-of-authority rules (amount, department) apply as configured.
 */
class RequisitionService
{
    public function __construct(private ApprovalEngine $approvals, private OrganizationContext $ctx) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("procurement.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('requisition_status', $code); }

    private function history(PurchaseRequisition $r, ?string $from, string $to, ?User $by, ?string $note): void
    {
        PurchaseRequisitionHistory::create(['organization_id' => $r->organization_id, 'requisition_id' => $r->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    private function checkSupplier(array $d): void
    {
        if (!empty($d['preferred_supplier_id'])) {
            $ok = \App\Models\Supplier::whereKey($d['preferred_supplier_id'])->where('status_id', Lookup::id('supplier_status', 'approved'))->exists();
            if (!$ok) {
                $this->fail('preferred_supplier_id', 'supplier_not_approved');
            }
            if (mb_strlen(trim((string) ($d['preferred_supplier_reason'] ?? ''))) < 3) {
                $this->fail('preferred_supplier_reason', 'preferred_reason_required');
            }
        }
    }

    /** @param array<int, array{description:string,quantity:mixed,unit:?string,unit_price:mixed}> $lines */
    private function saveLines(PurchaseRequisition $r, array $lines): void
    {
        PurchaseRequisitionLine::where('requisition_id', $r->id)->delete();
        $total = 0;
        foreach (array_values($lines) as $i => $l) {
            $cents = ProcurementRules::lineCents($l['quantity'], $l['unit_price']);
            $total += $cents;
            PurchaseRequisitionLine::create(['requisition_id' => $r->id, 'line_no' => $i + 1, 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'] ?? null,
                'unit_price' => $l['unit_price'], 'line_total' => number_format($cents / 100, 2, '.', '')]);
        }
        $r->update(['total_amount' => number_format($total / 100, 2, '.', '')]);
    }

    public function create(array $d, array $lines, User $by): PurchaseRequisition
    {
        $this->checkSupplier($d);
        return DB::transaction(function () use ($d, $lines, $by) {
            $r = PurchaseRequisition::create($d + ['requester_id' => $by->id, 'status_id' => $this->st('draft')]);
            $this->saveLines($r, $lines);
            $this->history($r, null, $r->status_id, $by, null);
            AuditLogger::log('requisition.created', $r, [], ['title' => $r->title, 'total' => (string) $r->total_amount]);
            return $r->refresh();
        });
    }

    public function update(PurchaseRequisition $r, array $d, ?array $lines, User $by): PurchaseRequisition
    {
        $r->loadMissing('status');
        if (!ProcurementRules::requisitionEditable($r->status->code)) {
            $this->fail('status', 'requisition_not_editable');
        }
        $this->checkSupplier($d + ['preferred_supplier_id' => $r->preferred_supplier_id, 'preferred_supplier_reason' => $r->preferred_supplier_reason]);
        return DB::transaction(function () use ($r, $d, $lines, $by) {
            $old = array_map(fn ($v) => (string) $v, $r->only(['title', 'total_amount', 'department_id', 'project_id']));
            $r->update($d);
            if ($lines !== null) {
                $this->saveLines($r, $lines);
            }
            AuditLogger::log('requisition.updated', $r, $old, array_map(fn ($v) => (string) $v, $r->refresh()->only(['title', 'total_amount', 'department_id', 'project_id'])));
            return $r;
        });
    }

    /** Money already committed on the project by other approved or pending requisitions in the same currency, in cents. */
    public function committedCents(PurchaseRequisition $r): int
    {
        if (!$r->project_id) {
            return 0;
        }
        $ids = [$this->st('approved'), $this->st('pending_approval')];
        return ProcurementRules::toCents(PurchaseRequisition::where('project_id', $r->project_id)->where('currency_code', $r->currency_code)->whereIn('status_id', $ids)->where('id', '!=', $r->id)->sum('total_amount'));
    }

    private function recentCents(PurchaseRequisition $r): int
    {
        $days = (int) SettingsService::get($r->organization_id, 'procurement.split_window_days');
        $ids = [$this->st('approved'), $this->st('pending_approval')];
        return ProcurementRules::toCents(PurchaseRequisition::where('requester_id', $r->requester_id)->where('department_id', $r->department_id)->where('procurement_type_id', $r->procurement_type_id)
            ->where('currency_code', $r->currency_code)->whereIn('status_id', $ids)->where('id', '!=', $r->id)->where('created_at', '>=', now()->subDays(max($days, 1)))->sum('total_amount'));
    }

    public function submit(PurchaseRequisition $r, User $by): PurchaseRequisition
    {
        return DB::transaction(function () use ($r, $by) {
            $r = PurchaseRequisition::lockForUpdate()->with(['status', 'lines', 'project', 'department'])->findOrFail($r->id);
            if ($r->status->code !== 'draft') {
                $this->fail('status', 'requisition_not_editable');
            }
            $cents = ProcurementRules::toCents($r->total_amount);
            $tz = \App\Services\Hr\Attendance\AttendanceService::tz($r->organization_id);
            $missing = ProcurementRules::missing($r->only(['title', 'purpose', 'delivery_location', 'department_id', 'procurement_type_id', 'currency_code']) + ['needed_by' => $r->needed_by?->toDateString()],
                $r->lines->count(), $cents, now($tz)->toDateString());
            if ($missing) {
                throw ValidationException::withMessages(collect($missing)->mapWithKeys(fn ($k) => [$k => [__('procurement.requisition_missing')]])->all());
            }
            $budget = $r->project?->budget_amount !== null && $r->project->currency_code === $r->currency_code ? ProcurementRules::toCents($r->project->budget_amount) : null;
            $over = ProcurementRules::exceedsBudget($budget, $this->committedCents($r), $cents);
            if ($over && mb_strlen(trim((string) $r->exception_reason)) < 10) {
                $this->fail('exception_reason', 'exception_required');
            }
            $threshold = ProcurementRules::toCents((string) SettingsService::get($r->organization_id, 'procurement.split_threshold'));
            $split = ProcurementRules::splitSuspected($threshold, $cents, $this->recentCents($r));
            $tags = trim(($over ? __('procurement.tag_over_budget') : '') . ' ' . ($split ? __('procurement.tag_split') : ''));
            $title = __('procurement.requisition_approval_title', ['code' => $r->code, 'title' => $r->title]) . ($tags !== '' ? " $tags" : '');
            $req = $this->approvals->submit('purchase_requisition', $r->id, $by, $title, ['amount' => (string) $r->total_amount, 'currency' => $r->currency_code, 'department_id' => $r->department_id]);
            $from = $r->status_id;
            $r->update(['status_id' => $this->st('pending_approval'), 'over_budget' => $over, 'split_suspected' => $split, 'approval_request_id' => $req->id, 'submitted_at' => now(), 'decision_note' => null]);
            $this->history($r, $from, $r->status_id, $by, null);
            AuditLogger::log('requisition.submitted', $r, [], ['code' => $r->code, 'total' => (string) $r->total_amount, 'over_budget' => $over, 'split_suspected' => $split]);
            return $r->refresh();
        });
    }

    /** Cancel (draft, pending or approved) or reopen a rejected requisition as a draft. */
    public function move(PurchaseRequisition $r, string $to, ?string $note, User $by): PurchaseRequisition
    {
        $r->loadMissing('status');
        $from = $r->status->code;
        if (!ProcurementRules::requisitionAllowed($from, $to) || !in_array($to, ['cancelled', 'draft'], true) || ($to === 'draft' && $from !== 'rejected')) {
            $this->fail('status', 'requisition_bad_move');
        }
        if ($to === 'cancelled' && mb_strlen(trim((string) $note)) < 3) {
            $this->fail('note', 'supplier_reason_required');
        }
        if ($from === 'pending_approval' && $r->approval_request_id) {
            $this->approvals->cancel($r->approval_request_id, $by);       // the handler returns it to draft; it is cancelled just below
            $r->refresh();
        }
        $fromId = $r->status_id;
        $r->update(['status_id' => $this->st($to), 'decision_note' => $to === 'cancelled' ? trim((string) $note) : $r->decision_note]);
        $this->history($r, $fromId, $r->status_id, $by, $note ? trim($note) : null);
        AuditLogger::log('requisition.' . ($to === 'cancelled' ? 'cancelled' : 'reopened'), $r, ['status' => $from], ['status' => $to, 'note' => $note]);
        return $r->refresh();
    }

    public function delete(PurchaseRequisition $r): void
    {
        $r->loadMissing('status');
        if (!ProcurementRules::requisitionEditable($r->status->code) || $r->approval_request_id) {
            $this->fail('status', 'requisition_not_editable');
        }
        AuditLogger::log('requisition.deleted', $r, ['code' => $r->code, 'total' => (string) $r->total_amount], []);
        $r->delete();
    }

    /** Called by the approval handler. */
    public function outcome(string $id, string $outcome, ApprovalRequest $req): void
    {
        $r = PurchaseRequisition::with('status')->find($id);
        if (!$r || $r->status->code !== 'pending_approval') {
            return;
        }
        $last = ApprovalAction::where('request_id', $req->id)->orderByDesc('acted_at')->first();
        $note = $last?->comment ? mb_substr($last->comment, 0, 2000) : null;
        $to = $outcome === 'approved' ? 'approved' : ($outcome === 'rejected' ? 'rejected' : 'draft');
        $from = $r->status_id;
        $r->update(['status_id' => $this->st($to), 'decided_at' => now(), 'decision_note' => $note]);
        $this->history($r, $from, $r->status_id, null, $note);
        AuditLogger::log('requisition.' . $outcome, $r, [], ['code' => $r->code, 'note' => $note]);
        Notifier::send(User::whereKey($r->requester_id)->with('language')->get(), 'requisition_decided', ['code' => $r->code, 'title' => $r->title, 'outcome' => $outcome, 'note' => (string) $note],
            null, ['in_app', 'email'], '/requisitions/' . $r->id, $r->organization_id);
    }
}
