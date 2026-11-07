<?php

namespace App\Services\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\MoneyService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Issued invoices are corrected only by credit notes (or authorised adjustments), each approved by someone other than its author. */
class CreditNoteService
{
    public function __construct(private ApprovalEngine $approvals, private DocumentNumber $numbers, private PaymentService $payments) {}

    private function fail(string $field, string $key): never { throw ValidationException::withMessages([$field => [__("billing.$key")]]); }

    public function request(Invoice $invoice, string $amount, string $reason, User $by): CreditNote
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $by) {
            $i = Invoice::lockForUpdate()->with('status')->findOrFail($invoice->id);
            if (!in_array($i->status->code, ['issued', 'partially_paid', 'overdue', 'paid'], true)) $this->fail('invoice', 'credit.not_credit_able');
            if (MoneyService::compare($amount, '0') <= 0) $this->fail('amount', 'payment.positive');
            $open = (string) (CreditNote::where('invoice_id', $i->id)->whereHas('status', fn ($s) => $s->whereIn('code', ['pending_approval', 'issued']))->sum('amount') ?: '0');
            if (MoneyService::compare($amount, MoneyService::sub((string) $i->subtotal, MoneyService::round($open, 2))) > 0) $this->fail('amount', 'credit.exceeds_invoice');
            $tax = InvoiceMath::creditTax($amount, $i->tax_exempt ? null : ($i->tax_percent === null ? null : (string) $i->tax_percent));
            $cn = CreditNote::create(['invoice_id' => $i->id, 'status_id' => Lookup::id('credit_note_status', 'pending_approval'), 'reason' => $reason,
                'amount' => $amount, 'tax_total' => $tax, 'total' => MoneyService::add($amount, $tax), 'created_by' => $by->id]);
            $req = $this->approvals->submit('credit_note', $cn->id, $by, "Credit note for {$i->number}", ['amount' => (string) $cn->total, 'currency' => $i->currency_code]);
            $cn->update(['approval_request_id' => $req->id]);
            AuditLogger::log('credit_note.requested', $cn, [], ['invoice' => $i->number, 'amount' => $amount, 'reason' => $reason]);
            return $cn;
        });
    }

    public function issue(CreditNote $cn): void
    {
        DB::transaction(function () use ($cn) {
            $c = CreditNote::lockForUpdate()->with('status')->findOrFail($cn->id);
            if ($c->status->code !== 'pending_approval') return;
            $c->update(['number' => $this->numbers->next($c->organization_id, 'CN'), 'status_id' => Lookup::id('credit_note_status', 'issued'), 'issued_at' => now()]);
            $this->payments->refresh(Invoice::findOrFail($c->invoice_id));
            AuditLogger::log('credit_note.issued', $c, [], ['number' => $c->number]);
        });
    }

    public function reject(CreditNote $cn, string $note): void
    {
        $c = CreditNote::with('status')->findOrFail($cn->id);
        if ($c->status->code === 'pending_approval') {
            $c->update(['status_id' => Lookup::id('credit_note_status', 'rejected')]);
            AuditLogger::log('credit_note.rejected', $c, [], ['note' => $note]);
        }
    }
}
