<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\RfqHistory;
use App\Models\RfqQuote;
use App\Models\RfqQuoteLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sourcing (Guide 7.4): a buyer invites approved suppliers to quote on an approved requisition, records the quotes received, compares them, and awards with a documented
 * reason. The awarded quote then becomes a purchase order through OrderService, so approval, tolerance and receiving rules stay in one place.
 */
class RfqService
{
    public function __construct(private OrderService $orders) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("procurement.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('rfq_status', $code); }

    private function history(Rfq $r, ?string $from, string $to, ?User $by, ?string $note): void
    {
        RfqHistory::create(['organization_id' => $r->organization_id, 'rfq_id' => $r->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    private function liveIds(): array { return array_map(fn ($c) => $this->st($c), ['draft', 'open', 'closed', 'awarded']); }

    /** @param string[] $supplierIds */
    private function syncInvitees(Rfq $r, array $supplierIds): void
    {
        $supplierIds = array_values(array_unique($supplierIds));
        $approved = Supplier::whereIn('id', $supplierIds)->where('status_id', Lookup::id('supplier_status', 'approved'))->pluck('id')->all();
        if (count($approved) !== count($supplierIds)) {
            $this->fail('supplier_ids', 'supplier_not_approved');
        }
        RfqQuote::where('rfq_id', $r->id)->whereNotIn('supplier_id', $supplierIds)->delete();
        foreach ($supplierIds as $sid) {
            RfqQuote::firstOrCreate(['rfq_id' => $r->id, 'supplier_id' => $sid]);
        }
    }

    public function create(PurchaseRequisition $req, array $d, array $supplierIds, User $by): Rfq
    {
        return DB::transaction(function () use ($req, $d, $supplierIds, $by) {
            $req = PurchaseRequisition::lockForUpdate()->with('status')->findOrFail($req->id);
            if ($req->status->code !== 'approved') {
                $this->fail('requisition_id', 'po_requisition_not_approved');
            }
            if (Rfq::where('requisition_id', $req->id)->whereIn('status_id', array_map(fn ($c) => $this->st($c), ['draft', 'open', 'closed', 'awarded']))->exists()) {
                $this->fail('requisition_id', 'rfq_exists');
            }
            if (PurchaseOrder::where('requisition_id', $req->id)->where('status_id', '!=', Lookup::id('po_status', 'cancelled'))->exists()) {
                $this->fail('requisition_id', 'po_exists');
            }
            $r = Rfq::create(['requisition_id' => $req->id, 'title' => $d['title'] ?? $req->title, 'instructions' => $d['instructions'] ?? null, 'closing_date' => $d['closing_date'],
                'status_id' => $this->st('draft'), 'created_by' => $by->id]);
            $this->syncInvitees($r, $supplierIds);
            $this->history($r, null, $r->status_id, $by, null);
            AuditLogger::log('rfq.created', $r, [], ['requisition' => $req->code, 'invited' => count($supplierIds)]);
            return $r->refresh();
        });
    }

    public function update(Rfq $r, array $d, ?array $supplierIds, User $by): Rfq
    {
        $r->loadMissing('status');
        if (!RfqRules::editable($r->status->code)) {
            $this->fail('status', 'rfq_not_editable');
        }
        return DB::transaction(function () use ($r, $d, $supplierIds, $by) {
            $r->update($d);
            if ($supplierIds !== null) {
                $this->syncInvitees($r, $supplierIds);
            }
            AuditLogger::log('rfq.updated', $r, [], ['fields' => array_keys($d), 'invited' => $supplierIds === null ? null : count($supplierIds)]);
            return $r->refresh();
        });
    }

    /** Move to a status that needs no extra input: open (send), close, reopen, cancel. */
    public function move(Rfq $r, string $to, ?string $note, ?string $soleSourceReason, User $by): Rfq
    {
        return DB::transaction(function () use ($r, $to, $note, $soleSourceReason, $by) {
            $r = Rfq::lockForUpdate()->with('status')->findOrFail($r->id);
            $from = $r->status->code;
            if (!in_array($to, ['open', 'closed', 'cancelled'], true) || !RfqRules::allowed($from, $to)) {
                $this->fail('status', 'rfq_bad_move');
            }
            $extra = [];
            if ($to === 'open' && $from === 'draft') {
                $min = (int) SettingsService::get($r->organization_id, 'procurement.rfq_min_suppliers');
                $reason = $soleSourceReason ?: $r->sole_source_reason;
                $count = RfqQuote::where('rfq_id', $r->id)->count();
                if (!RfqRules::inviteesOk($count, $min, $reason)) {
                    $this->fail($count === 0 ? 'supplier_ids' : 'sole_source_reason', $count === 0 ? 'rfq_no_suppliers' : 'rfq_sole_source_required', ['min' => $min]);
                }
                if ($r->closing_date->toDateString() < now()->toDateString()) {
                    $this->fail('closing_date', 'rfq_closing_past');
                }
                $extra['sole_source_reason'] = $count >= $min ? null : trim((string) $reason);
            }
            if ($to === 'cancelled' && mb_strlen(trim((string) $note)) < 3) {
                $this->fail('note', 'supplier_reason_required');
            }
            $fromId = $r->status_id;
            $r->update(['status_id' => $this->st($to), 'status_note' => $note ? trim($note) : null] + $extra);
            $this->history($r, $fromId, $r->status_id, $by, $note ? trim($note) : null);
            AuditLogger::log('rfq.' . $to, $r, [], ['from' => $from, 'note' => $note]);
            return $r->refresh();
        });
    }

    /**
     * Record (or replace) the quote a supplier sent. $lines: requisition_line_id => unit price; every line must be priced for the quote to count as complete,
     * but a partial quote is kept so the comparison can show it as incomplete.
     */
    public function recordQuote(Rfq $r, string $supplierId, array $d, array $lines, User $by): RfqQuote
    {
        return DB::transaction(function () use ($r, $supplierId, $d, $lines, $by) {
            $r = Rfq::lockForUpdate()->with(['status', 'requisition.lines'])->findOrFail($r->id);
            if (!RfqRules::canQuote($r->status->code)) {
                $this->fail('status', 'rfq_not_open');
            }
            $q = RfqQuote::where('rfq_id', $r->id)->where('supplier_id', $supplierId)->first();
            if (!$q) {
                $this->fail('supplier_id', 'rfq_not_invited');
            }
            $own = $r->requisition->lines->keyBy('id');
            $total = 0;
            RfqQuoteLine::where('quote_id', $q->id)->delete();
            foreach ($lines as $lid => $price) {
                $rl = $own->get($lid);
                if (!$rl || $price === null || $price === '') {
                    continue;
                }
                $c = ProcurementRules::lineCents($rl->quantity, $price);
                $total += $c;
                RfqQuoteLine::create(['quote_id' => $q->id, 'requisition_line_id' => $lid, 'unit_price' => $price, 'line_total' => number_format($c / 100, 2, '.', '')]);
            }
            $q->update(['lead_days' => $d['lead_days'] ?? null, 'valid_until' => $d['valid_until'] ?? null, 'notes' => $d['notes'] ?? null, 'total_amount' => number_format($total / 100, 2, '.', ''),
                'declined' => false, 'decline_reason' => null, 'submitted_at' => now(), 'recorded_by' => $by->id]);
            AuditLogger::log('rfq.quote_recorded', $r, [], ['supplier' => $supplierId, 'total' => (string) $q->total_amount, 'priced' => RfqQuoteLine::where('quote_id', $q->id)->count()]);
            return $q->refresh();
        });
    }

    public function declineQuote(Rfq $r, string $supplierId, ?string $reason, User $by): RfqQuote
    {
        $r->loadMissing('status');
        if (!RfqRules::canQuote($r->status->code)) {
            $this->fail('status', 'rfq_not_open');
        }
        $q = RfqQuote::where('rfq_id', $r->id)->where('supplier_id', $supplierId)->first();
        if (!$q) {
            $this->fail('supplier_id', 'rfq_not_invited');
        }
        return DB::transaction(function () use ($r, $q, $reason, $by) {
            RfqQuoteLine::where('quote_id', $q->id)->delete();
            $q->update(['declined' => true, 'decline_reason' => $reason ? trim($reason) : null, 'total_amount' => null, 'submitted_at' => now(), 'recorded_by' => $by->id]);
            AuditLogger::log('rfq.quote_declined', $r, [], ['supplier' => $q->supplier_id]);
            return $q->refresh();
        });
    }

    /** Quotes that may win: submitted, not declined, priced on every line and still valid today. Returns id => total cents. */
    public function eligible(Rfq $r): array
    {
        $lineCount = $r->requisition->lines()->count();
        $out = [];
        foreach (RfqQuote::where('rfq_id', $r->id)->where('declined', false)->whereNotNull('submitted_at')->withCount('lines')->get() as $q) {
            if (RfqRules::complete($lineCount, $q->lines_count) && RfqRules::stillValid($q->valid_until?->toDateString(), now()->toDateString())) {
                $out[$q->id] = ProcurementRules::toCents($q->total_amount);
            }
        }
        return $out;
    }

    public function award(Rfq $r, string $quoteId, ?string $reason, User $by): Rfq
    {
        return DB::transaction(function () use ($r, $quoteId, $reason, $by) {
            $r = Rfq::lockForUpdate()->with(['status', 'requisition'])->findOrFail($r->id);
            if (!RfqRules::allowed($r->status->code, 'awarded')) {
                $this->fail('status', 'rfq_bad_move');
            }
            $eligible = $this->eligible($r);
            if (!isset($eligible[$quoteId])) {
                $this->fail('quote_id', 'rfq_quote_not_eligible');
            }
            if (!OrderRules::withinTolerance($eligible[$quoteId], ProcurementRules::toCents($r->requisition->total_amount), (int) SettingsService::get($r->organization_id, 'procurement.po_tolerance_percent'))) {
                $this->fail('quote_id', 'rfq_over_requisition', ['pct' => (int) SettingsService::get($r->organization_id, 'procurement.po_tolerance_percent')]);
            }
            $needs = RfqRules::awardNeedsReason($quoteId, RfqRules::lowest($eligible));
            if ($needs && !RfqRules::reasonOk($reason)) {
                $this->fail('reason', 'rfq_award_reason_required');
            }
            $from = $r->status_id;
            $r->update(['status_id' => $this->st('awarded'), 'awarded_quote_id' => $quoteId, 'award_reason' => $needs ? trim($reason) : ($reason ? trim($reason) : null), 'awarded_by' => $by->id, 'awarded_at' => now()]);
            $this->history($r, $from, $r->status_id, $by, $needs ? trim($reason) : null);
            AuditLogger::log('rfq.awarded', $r, [], ['quote' => $quoteId, 'total' => $eligible[$quoteId], 'not_lowest' => $needs, 'reason' => $reason, 'quotes_compared' => count($eligible)]);
            return $r->refresh();
        });
    }

    /** Turns the awarded quote into a draft purchase order (supplier and negotiated prices from the quote); it then follows the normal order approval. */
    public function createOrder(Rfq $r, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($r, $by) {
            $r = Rfq::lockForUpdate()->with(['status', 'requisition'])->findOrFail($r->id);
            if ($r->status->code !== 'awarded' || !$r->awarded_quote_id) {
                $this->fail('status', 'rfq_not_awarded');
            }
            if (PurchaseOrder::where('rfq_id', $r->id)->where('status_id', '!=', Lookup::id('po_status', 'cancelled'))->exists()) {
                $this->fail('status', 'po_exists');
            }
            $q = RfqQuote::with('lines')->findOrFail($r->awarded_quote_id);
            $o = $this->orders->create($r->requisition, $q->supplier_id, [], $by);
            $o->update(['rfq_id' => $r->id]);
            $byReq = $q->lines->keyBy('requisition_line_id');
            $reqLines = $r->requisition->lines()->get()->keyBy('line_no');
            $prices = [];
            foreach ($o->lines()->get() as $ol) {
                $ql = $byReq->get($reqLines->get($ol->line_no)?->id);
                if ($ql) {
                    $prices[] = ['id' => $ol->id, 'unit_price' => $ql->unit_price];
                }
            }
            $o = $this->orders->update($o, [], $prices, $by);
            AuditLogger::log('rfq.order_created', $r, [], ['order' => $o->code]);
            return $o;
        });
    }
}
