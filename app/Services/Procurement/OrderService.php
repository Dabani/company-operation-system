<?php

namespace App\Services\Procurement;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderHistory;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders and goods receipts (Guide 7.4 steps 8-9). An order is raised from an approved requisition for an approved supplier, approved through the
 * approval engine (so delegation-of-authority rules apply), issued, and then received in one or more goods receipts with acceptance per line.
 */
class OrderService
{
    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("procurement.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('po_status', $code); }

    private function history(PurchaseOrder $o, ?string $from, string $to, ?User $by, ?string $note): void
    {
        PurchaseOrderHistory::create(['organization_id' => $o->organization_id, 'order_id' => $o->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    private function approvedSupplier(string $id): Supplier
    {
        $s = Supplier::whereKey($id)->where('status_id', Lookup::id('supplier_status', 'approved'))->first();
        if (!$s) {
            $this->fail('supplier_id', 'supplier_not_approved');
        }
        return $s;
    }

    private function tolerance(PurchaseOrder $o): int { return (int) SettingsService::get($o->organization_id, 'procurement.po_tolerance_percent'); }

    private function checkTolerance(PurchaseOrder $o, PurchaseRequisition $r): void
    {
        if (!OrderRules::withinTolerance(ProcurementRules::toCents($o->total_amount), ProcurementRules::toCents($r->total_amount), $this->tolerance($o))) {
            $this->fail('lines', 'po_over_requisition', ['pct' => $this->tolerance($o)]);
        }
    }

    private function retotal(PurchaseOrder $o): void
    {
        $total = 0;
        foreach ($o->lines()->get() as $l) {
            $c = ProcurementRules::lineCents($l->quantity, $l->unit_price);
            $total += $c;
            $l->update(['line_total' => number_format($c / 100, 2, '.', '')]);
        }
        $o->update(['total_amount' => number_format($total / 100, 2, '.', '')]);
    }

    public function create(PurchaseRequisition $r, string $supplierId, array $d, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($r, $supplierId, $d, $by) {
            $r = PurchaseRequisition::lockForUpdate()->with(['status', 'lines'])->findOrFail($r->id);
            if ($r->status->code !== 'approved') {
                $this->fail('requisition_id', 'po_requisition_not_approved');
            }
            $cancelled = $this->st('cancelled');
            if (PurchaseOrder::where('requisition_id', $r->id)->where('status_id', '!=', $cancelled)->exists()) {
                $this->fail('requisition_id', 'po_exists');
            }
            $this->approvedSupplier($supplierId);
            $o = PurchaseOrder::create(['requisition_id' => $r->id, 'supplier_id' => $supplierId, 'title' => $r->title, 'terms' => $d['terms'] ?? null,
                'delivery_location' => $d['delivery_location'] ?? $r->delivery_location, 'needed_by' => $d['needed_by'] ?? $r->needed_by->toDateString(), 'currency_code' => $r->currency_code,
                'total_amount' => $r->total_amount, 'status_id' => $this->st('draft'), 'created_by' => $by->id]);
            foreach ($r->lines as $l) {
                PurchaseOrderLine::create(['order_id' => $o->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total]);
            }
            $this->history($o, null, $o->status_id, $by, null);
            AuditLogger::log('purchase_order.created', $o, [], ['requisition' => $r->code, 'supplier' => $supplierId, 'total' => (string) $o->total_amount]);
            return $o->refresh();
        });
    }

    /** @param array<int, array{id:string, unit_price:mixed}>|null $prices negotiated unit prices by line id; quantities and descriptions stay as requisitioned */
    public function update(PurchaseOrder $o, array $d, ?array $prices, User $by): PurchaseOrder
    {
        $o->loadMissing('status');
        if (!OrderRules::editable($o->status->code)) {
            $this->fail('status', 'po_not_editable');
        }
        if (isset($d['supplier_id'])) {
            $this->approvedSupplier($d['supplier_id']);
        }
        return DB::transaction(function () use ($o, $d, $prices, $by) {
            $old = array_map(fn ($v) => (string) $v, $o->only(['supplier_id', 'total_amount', 'needed_by']));
            $o->update($d);
            if ($prices !== null) {
                $lines = $o->lines()->get()->keyBy('id');
                foreach ($prices as $p) {
                    $l = $lines->get($p['id']);
                    if (!$l) {
                        $this->fail('lines', 'po_bad_line');
                    }
                    $l->update(['unit_price' => $p['unit_price']]);
                }
                $this->retotal($o);
            }
            $this->checkTolerance($o->refresh(), $o->requisition);
            AuditLogger::log('purchase_order.updated', $o, $old, array_map(fn ($v) => (string) $v, $o->only(['supplier_id', 'total_amount', 'needed_by'])));
            return $o;
        });
    }

    public function submit(PurchaseOrder $o, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($o, $by) {
            $o = PurchaseOrder::lockForUpdate()->with(['status', 'requisition', 'supplier'])->findOrFail($o->id);
            if ($o->status->code !== 'draft') {
                $this->fail('status', 'po_not_editable');
            }
            $this->approvedSupplier($o->supplier_id);
            $this->checkTolerance($o, $o->requisition);
            $req = $this->approvals->submit('purchase_order', $o->id, $by, __('procurement.po_approval_title', ['code' => $o->code, 'title' => $o->title, 'supplier' => $o->supplier->name]),
                ['amount' => (string) $o->total_amount, 'currency' => $o->currency_code, 'department_id' => $o->requisition->department_id]);
            $from = $o->status_id;
            $o->update(['status_id' => $this->st('pending_approval'), 'approval_request_id' => $req->id, 'status_note' => null]);
            $this->history($o, $from, $o->status_id, $by, null);
            AuditLogger::log('purchase_order.submitted', $o, [], ['code' => $o->code, 'total' => (string) $o->total_amount]);
            return $o->refresh();
        });
    }

    public function outcome(string $id, string $outcome, ApprovalRequest $req): void
    {
        $o = PurchaseOrder::with('status')->find($id);
        if (!$o || $o->status->code !== 'pending_approval') {
            return;
        }
        $last = ApprovalAction::where('request_id', $req->id)->orderByDesc('acted_at')->first();
        $note = $last?->comment ? mb_substr($last->comment, 0, 2000) : null;
        $to = $outcome === 'approved' ? 'approved' : 'draft';
        $from = $o->status_id;
        $o->update(['status_id' => $this->st($to), 'status_note' => $note]);
        $this->history($o, $from, $o->status_id, null, $note);
        AuditLogger::log('purchase_order.' . $outcome, $o, [], ['code' => $o->code, 'note' => $note]);
        if ($o->created_by) {
            Notifier::send(User::whereKey($o->created_by)->with('language')->get(), 'po_decided', ['code' => $o->code, 'title' => $o->title, 'outcome' => $outcome, 'note' => (string) $note],
                null, ['in_app'], '/purchase-orders/' . $o->id, $o->organization_id);
        }
    }

    public function issue(PurchaseOrder $o, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($o, $by) {
            $o = PurchaseOrder::lockForUpdate()->with(['status', 'requisition'])->findOrFail($o->id);
            if ($o->status->code !== 'approved') {
                $this->fail('status', 'po_bad_move');
            }
            $this->approvedSupplier($o->supplier_id);
            $from = $o->status_id;
            $o->update(['status_id' => $this->st('issued'), 'issued_at' => now()]);
            $this->history($o, $from, $o->status_id, $by, null);
            AuditLogger::log('purchase_order.issued', $o, [], ['code' => $o->code, 'supplier' => $o->supplier_id, 'total' => (string) $o->total_amount]);
            Notifier::send(User::whereKey($o->requisition->requester_id)->with('language')->get(), 'po_issued', ['code' => $o->code, 'title' => $o->title],
                null, ['in_app', 'email'], '/purchase-orders/' . $o->id, $o->organization_id);
            return $o->refresh();
        });
    }

    /** Cancel (before anything was accepted) or close (after delivery; a short delivery needs a reason). */
    public function move(PurchaseOrder $o, string $to, ?string $note, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($o, $to, $note, $by) {
            $o = PurchaseOrder::lockForUpdate()->with(['status', 'lines'])->findOrFail($o->id);
            $from = $o->status->code;
            $note = $note !== null ? trim($note) : null;
            if (!in_array($to, ['cancelled', 'closed'], true) || !OrderRules::allowed($from, $to)) {
                $this->fail('status', 'po_bad_move');
            }
            if ($to === 'cancelled' && OrderRules::hasReceipts($o->lines->map(fn ($l) => ['accepted' => $l->accepted_quantity])->all())) {
                $this->fail('status', 'po_has_receipts');
            }
            if (($to === 'cancelled' || $from === 'partially_received') && mb_strlen((string) $note) < 3) {
                $this->fail('note', 'supplier_reason_required');
            }
            if ($from === 'pending_approval' && $o->approval_request_id) {
                $this->approvals->cancel($o->approval_request_id, $by);
                $o->refresh();
            }
            $fromId = $o->status_id;
            $o->update(['status_id' => $this->st($to), 'status_note' => $note ?: $o->status_note, 'closed_at' => $to === 'closed' ? now() : $o->closed_at]);
            $this->history($o, $fromId, $o->status_id, $by, $note ?: null);
            AuditLogger::log('purchase_order.' . $to, $o, ['status' => $from], ['status' => $to, 'note' => $note]);
            return $o->refresh();
        });
    }

    /** @param array<int, array{order_line_id:string, quantity_received:mixed, quantity_accepted:mixed, note?:?string}> $lines */
    public function receive(PurchaseOrder $o, array $d, array $lines, User $by): GoodsReceipt
    {
        return DB::transaction(function () use ($o, $d, $lines, $by) {
            $o = PurchaseOrder::lockForUpdate()->with(['status', 'lines'])->findOrFail($o->id);
            if (!OrderRules::canReceive($o->status->code)) {
                $this->fail('status', 'po_not_receivable');
            }
            if (!OrderRules::receiverAllowed($o->created_by, $by->id)) {
                $this->fail('status', 'po_receiver_same_person');
            }
            $byId = $o->lines->keyBy('id');
            $seen = [];
            foreach ($lines as $i => $l) {
                $pl = $byId->get($l['order_line_id']);
                if (!$pl || isset($seen[$pl->id])) {
                    $this->fail("lines.$i.order_line_id", 'po_bad_line');
                }
                $seen[$pl->id] = true;
                if (!OrderRules::receiptLineValid($l['quantity_received'], $l['quantity_accepted'], OrderRules::remaining($pl->quantity, $pl->accepted_quantity))) {
                    $this->fail("lines.$i.quantity_received", 'receipt_bad_quantity');
                }
            }
            $g = GoodsReceipt::create(['order_id' => $o->id, 'received_on' => $d['received_on'], 'delivery_note' => $d['delivery_note'] ?? null, 'note' => $d['note'] ?? null, 'received_by' => $by->id]);
            foreach ($lines as $l) {
                $pl = $byId->get($l['order_line_id']);
                GoodsReceiptLine::create(['receipt_id' => $g->id, 'order_line_id' => $pl->id, 'quantity_received' => $l['quantity_received'], 'quantity_accepted' => $l['quantity_accepted'], 'note' => $l['note'] ?? null]);
                $pl->update(['accepted_quantity' => number_format((OrderRules::thousandths($pl->accepted_quantity) + OrderRules::thousandths($l['quantity_accepted'])) / 1000, 3, '.', '')]);
            }
            $fresh = $o->lines()->get()->map(fn ($l) => ['quantity' => $l->quantity, 'accepted' => $l->accepted_quantity])->all();
            $to = OrderRules::statusAfterReceipt($fresh);
            if ($to !== $o->status->code) {
                $from = $o->status_id;
                $o->update(['status_id' => $this->st($to)]);
                $this->history($o, $from, $o->status_id, $by, $g->code);
            }
            AuditLogger::log('goods_receipt.recorded', $g, [], ['order' => $o->code, 'lines' => count($lines), 'status' => $to]);
            if ($o->created_by) {
                Notifier::send(User::whereKey($o->created_by)->with('language')->get(), 'goods_received', ['code' => $o->code, 'receipt' => $g->code, 'title' => $o->title],
                    null, ['in_app'], '/purchase-orders/' . $o->id, $o->organization_id);
            }
            return $g->refresh();
        });
    }
}
