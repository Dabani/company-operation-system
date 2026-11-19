<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceHistory;
use App\Models\SupplierInvoiceIssue;
use App\Models\SupplierInvoiceLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier invoices (Guide 7.4 stages 10-11): recorded against a purchase order, matched automatically against what was ordered and received, held on any
 * discrepancy, released only by an approved exception, approved by someone independent, and then handed to payment with a status and a reference.
 * Actual bank transfers are outside this module (the Guide requires a separately approved integration for that).
 */
class InvoiceService
{
    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("procurement.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('supplier_invoice_status', $code); }

    private function history(SupplierInvoice $i, ?string $from, string $to, ?User $by, ?string $note): void
    {
        SupplierInvoiceHistory::create(['organization_id' => $i->organization_id, 'invoice_id' => $i->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    private function liveIds(): array { return array_map(fn ($c) => $this->st($c), ['received', 'on_hold', 'matched', 'approved', 'paid']); }

    /** Runs the three-way match and stores the discrepancies. Returns the issue list. */
    private function match(SupplierInvoice $i): array
    {
        $i->loadMissing(['lines', 'order']);
        $tol = (int) SettingsService::get($i->organization_id, 'procurement.invoice_price_tolerance_percent');
        $rate = (float) SettingsService::get($i->organization_id, 'procurement.tax_rate_percent');
        $orderLines = PurchaseOrderLine::where('order_id', $i->order_id)->get()->keyBy('id');
        $issues = [];
        if ($i->currency_code !== $i->order->currency_code) {
            $issues[] = ['order_line_id' => null, 'code' => 'currency', 'detail' => $i->currency_code . ' vs ' . $i->order->currency_code];
        }
        foreach ($i->lines as $l) {
            $ol = $orderLines->get($l->order_line_id);
            $invoiced = SupplierInvoiceLine::where('order_line_id', $l->order_line_id)->where('invoice_id', '!=', $i->id)
                ->whereHas('invoice', fn ($q) => $q->whereIn('status_id', $this->liveIds()))->sum('quantity');
            foreach (InvoiceRules::matchLine(InvoiceRules::thousandths($ol->accepted_quantity), InvoiceRules::thousandths($invoiced), InvoiceRules::thousandths($l->quantity),
                ProcurementRules::toCents($ol->unit_price), ProcurementRules::toCents($l->unit_price), $tol) as $x) {
                $issues[] = ['order_line_id' => $l->order_line_id] + $x;
            }
        }
        if (!InvoiceRules::taxOk(ProcurementRules::toCents($i->tax_amount), ProcurementRules::toCents($i->subtotal), $rate, $i->lines->count())) {
            $issues[] = ['order_line_id' => null, 'code' => 'tax_mismatch', 'detail' => 'expected ' . InvoiceRules::expectedTaxCents(ProcurementRules::toCents($i->subtotal), $rate) / 100 . ', stated ' . (string) $i->tax_amount];
        }
        SupplierInvoiceIssue::where('invoice_id', $i->id)->delete();
        foreach ($issues as $x) {
            SupplierInvoiceIssue::create(['invoice_id' => $i->id] + $x);
        }
        return $issues;
    }

    /** @param array<int, array{order_line_id:string, quantity:mixed, unit_price:mixed}> $lines */
    public function record(PurchaseOrder $o, array $d, array $lines, User $by): SupplierInvoice
    {
        $o->loadMissing('status');
        if (!in_array($o->status->code, ['issued', 'partially_received', 'received', 'closed'], true)) {
            $this->fail('order_id', 'invoice_order_not_issued');
        }
        return DB::transaction(function () use ($o, $d, $lines, $by) {
            $key = InvoiceRules::numberKey($d['invoice_number']);
            if ($key === '') {
                $this->fail('invoice_number', 'invoice_number_invalid');
            }
            $dupe = SupplierInvoice::where('supplier_id', $o->supplier_id)->where('number_key', $key)->whereIn('status_id', $this->liveIds())->exists();
            if ($dupe) {
                $this->fail('invoice_number', 'invoice_duplicate');
            }
            $own = PurchaseOrderLine::where('order_id', $o->id)->pluck('id')->all();
            $seen = [];
            $sub = 0;
            foreach ($lines as $n => $l) {
                if (!in_array($l['order_line_id'], $own, true) || isset($seen[$l['order_line_id']])) {
                    $this->fail("lines.$n.order_line_id", 'po_bad_line');
                }
                $seen[$l['order_line_id']] = true;
                $sub += ProcurementRules::lineCents($l['quantity'], $l['unit_price']);
            }
            $tax = ProcurementRules::toCents($d['tax_amount'] ?? 0);
            $i = SupplierInvoice::create(['order_id' => $o->id, 'supplier_id' => $o->supplier_id, 'invoice_number' => trim($d['invoice_number']), 'number_key' => $key, 'invoice_date' => $d['invoice_date'],
                'due_date' => $d['due_date'] ?? null, 'currency_code' => $o->currency_code, 'subtotal' => number_format($sub / 100, 2, '.', ''), 'tax_amount' => number_format($tax / 100, 2, '.', ''),
                'total_amount' => number_format(($sub + $tax) / 100, 2, '.', ''), 'status_id' => $this->st('received'), 'recorded_by' => $by->id]);
            foreach ($lines as $l) {
                SupplierInvoiceLine::create(['invoice_id' => $i->id, 'order_line_id' => $l['order_line_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                    'line_total' => number_format(ProcurementRules::lineCents($l['quantity'], $l['unit_price']) / 100, 2, '.', '')]);
            }
            $this->history($i, null, $i->status_id, $by, null);
            $issues = $this->match($i->refresh());
            $to = InvoiceRules::outcome($issues);
            $i->update(['status_id' => $this->st($to)]);
            $this->history($i, $this->st('received'), $i->status_id, null, $issues ? implode(', ', array_unique(array_column($issues, 'code'))) : null);
            AuditLogger::log('supplier_invoice.recorded', $i, [], ['order' => $o->code, 'number' => $i->invoice_number, 'total' => (string) $i->total_amount, 'status' => $to, 'issues' => count($issues)]);
            return $i->refresh();
        });
    }

    /** Re-run the match of a held invoice, for instance after a late goods receipt. */
    public function rematch(SupplierInvoice $i, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $by) {
            $i = SupplierInvoice::lockForUpdate()->with('status')->findOrFail($i->id);
            if ($i->status->code !== 'on_hold') {
                $this->fail('status', 'invoice_bad_move');
            }
            $issues = $this->match($i);
            if (!$issues) {
                $from = $i->status_id;
                $i->update(['status_id' => $this->st('matched')]);
                $this->history($i, $from, $i->status_id, $by, __('procurement.invoice_rematched'));
            }
            AuditLogger::log('supplier_invoice.rematched', $i, [], ['issues' => count($issues)]);
            return $i->refresh();
        });
    }

    /** Approve (accept the discrepancy as an exception) or reject a held invoice. The person who recorded it cannot decide. */
    public function decideException(SupplierInvoice $i, bool $accept, ?string $note, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $accept, $note, $by) {
            $i = SupplierInvoice::lockForUpdate()->with('status')->findOrFail($i->id);
            if ($i->status->code !== 'on_hold') {
                $this->fail('status', 'invoice_bad_move');
            }
            if (!InvoiceRules::exceptionDeciderAllowed($i->recorded_by, $by->id)) {
                $this->fail('status', 'invoice_same_person');
            }
            $note = trim((string) $note);
            if (mb_strlen($note) < 10) {
                $this->fail('note', 'invoice_note_required');
            }
            $from = $i->status_id;
            if ($accept) {
                $i->update(['status_id' => $this->st('matched'), 'exception_approved' => true, 'exception_note' => $note, 'exception_by' => $by->id]);
            } else {
                $i->update(['status_id' => $this->st('rejected'), 'decision_note' => $note]);
            }
            $this->history($i, $from, $i->status_id, $by, $note);
            AuditLogger::log($accept ? 'supplier_invoice.exception_approved' : 'supplier_invoice.rejected', $i, [], ['note' => $note, 'issues' => $i->issues()->pluck('code')->all()]);
            return $i->refresh();
        });
    }

    public function reject(SupplierInvoice $i, ?string $note, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $note, $by) {
            $i = SupplierInvoice::lockForUpdate()->with('status')->findOrFail($i->id);
            if ($i->status->code !== 'matched' || mb_strlen(trim((string) $note)) < 3) {
                $this->fail($i->status->code !== 'matched' ? 'status' : 'note', $i->status->code !== 'matched' ? 'invoice_bad_move' : 'supplier_reason_required');
            }
            $from = $i->status_id;
            $i->update(['status_id' => $this->st('rejected'), 'decision_note' => trim($note)]);
            $this->history($i, $from, $i->status_id, $by, trim($note));
            AuditLogger::log('supplier_invoice.rejected', $i, [], ['note' => trim($note)]);
            return $i->refresh();
        });
    }

    public function approve(SupplierInvoice $i, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $by) {
            $i = SupplierInvoice::lockForUpdate()->with(['status', 'order'])->findOrFail($i->id);
            if ($i->status->code !== 'matched') {
                $this->fail('status', 'invoice_bad_move');
            }
            if (!InvoiceRules::approverAllowed($i->recorded_by, $i->order->created_by, $by->id)) {
                $this->fail('status', 'invoice_same_person');
            }
            $from = $i->status_id;
            $i->update(['status_id' => $this->st('approved'), 'approved_by' => $by->id, 'approved_at' => now()]);
            $this->history($i, $from, $i->status_id, $by, null);
            AuditLogger::log('supplier_invoice.approved', $i, [], ['total' => (string) $i->total_amount, 'exception' => $i->exception_approved]);
            return $i->refresh();
        });
    }

    /** Payment handoff: records that payment was made, with its reference. The approver cannot also mark it paid. */
    public function pay(SupplierInvoice $i, string $reference, string $paidOn, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $reference, $paidOn, $by) {
            $i = SupplierInvoice::lockForUpdate()->with('status')->findOrFail($i->id);
            if ($i->status->code !== 'approved') {
                $this->fail('status', 'invoice_bad_move');
            }
            if (!InvoiceRules::payerAllowed($i->approved_by, $by->id)) {
                $this->fail('status', 'invoice_same_person');
            }
            $from = $i->status_id;
            $i->update(['status_id' => $this->st('paid'), 'paid_by' => $by->id, 'paid_on' => $paidOn, 'payment_reference' => trim($reference)]);
            $this->history($i, $from, $i->status_id, $by, trim($reference));
            AuditLogger::log('supplier_invoice.paid', $i, [], ['reference' => trim($reference), 'paid_on' => $paidOn, 'total' => (string) $i->total_amount]);
            return $i->refresh();
        });
    }

    public function cancel(SupplierInvoice $i, ?string $note, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($i, $note, $by) {
            $i = SupplierInvoice::lockForUpdate()->with('status')->findOrFail($i->id);
            if (!InvoiceRules::allowed($i->status->code, 'cancelled')) {
                $this->fail('status', 'invoice_bad_move');
            }
            if (mb_strlen(trim((string) $note)) < 3) {
                $this->fail('note', 'supplier_reason_required');
            }
            $from = $i->status_id;
            $i->update(['status_id' => $this->st('cancelled'), 'decision_note' => trim($note)]);
            $this->history($i, $from, $i->status_id, $by, trim($note));
            AuditLogger::log('supplier_invoice.cancelled', $i, [], ['note' => trim($note)]);
            return $i->refresh();
        });
    }
}
