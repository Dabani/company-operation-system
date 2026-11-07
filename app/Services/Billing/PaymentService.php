<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MoneyService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private function fail(string $field, string $key): never { throw ValidationException::withMessages([$field => [__("billing.$key")]]); }

    public function record(Invoice $invoice, string $amount, string $paidOn, string $methodId, ?string $reference, ?string $notes, User $by): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $amount, $paidOn, $methodId, $reference, $notes, $by) {
            $i = Invoice::lockForUpdate()->with('status')->findOrFail($invoice->id);
            if (!in_array($i->status->code, ['issued', 'partially_paid', 'overdue'], true)) $this->fail('invoice', 'payment.not_payable');
            if (MoneyService::compare($amount, '0') <= 0) $this->fail('amount', 'payment.positive');
            if (MoneyService::compare($amount, (string) $i->balance) > 0) $this->fail('amount', 'payment.exceeds_balance');
            $p = InvoicePayment::create(['invoice_id' => $i->id, 'amount' => $amount, 'paid_on' => $paidOn, 'method_id' => $methodId, 'reference' => $reference, 'notes' => $notes, 'recorded_by' => $by->id]);
            $this->refresh($i);
            AuditLogger::log('invoice.payment_recorded', $i, [], ['amount' => $amount, 'method_id' => $methodId, 'reference' => $reference]);
            return $p;
        });
    }

    public function reverse(InvoicePayment $payment, string $reason, User $by): InvoicePayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $i = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            if (MoneyService::compare((string) $payment->amount, '0') <= 0 || $payment->reversal_of_id) $this->fail('payment', 'payment.not_reversible');
            if (InvoicePayment::where('reversal_of_id', $payment->id)->exists()) $this->fail('payment', 'payment.already_reversed');
            $r = InvoicePayment::create(['invoice_id' => $i->id, 'amount' => bcmul((string) $payment->amount, '-1', 2), 'paid_on' => now()->toDateString(), 'method_id' => $payment->method_id,
                'reference' => $payment->reference, 'notes' => $reason, 'reversal_of_id' => $payment->id, 'recorded_by' => $by->id]);
            $this->refresh($i);
            AuditLogger::log('invoice.payment_reversed', $i, [], ['payment' => $payment->id, 'reason' => $reason]);
            return $r;
        });
    }

    /** Recompute paid / credited / balance and the settlement status from the ledger rows. */
    public function refresh(Invoice $invoice): Invoice
    {
        $i = Invoice::with('status')->findOrFail($invoice->id);
        $paid = (string) InvoicePayment::where('invoice_id', $i->id)->sum('amount');
        $credited = (string) \App\Models\CreditNote::where('invoice_id', $i->id)->whereHas('status', fn ($s) => $s->where('code', 'issued'))->sum('total');
        $paid = MoneyService::round($paid ?: '0', 2);
        $credited = MoneyService::round($credited ?: '0', 2);
        $i->paid_total = $paid;
        $i->credited_total = $credited;
        $i->balance = InvoiceMath::balance((string) $i->total, $paid, $credited);
        if (in_array($i->status->code, ['issued', 'partially_paid', 'overdue', 'paid'], true)) {
            $i->status_id = Lookup::id('invoice_status', InvoiceMath::settlement((string) $i->total, $paid, $credited, $i->due_date?->toDateString(), now()->toDateString()));
        }
        $i->save();
        return $i->refresh();
    }
}
