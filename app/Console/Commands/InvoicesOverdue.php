<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use Illuminate\Console\Command;

class InvoicesOverdue extends Command
{
    protected $signature = 'cops:invoices-overdue';
    protected $description = 'Mark unpaid invoices past their due date as overdue and notify their creators';

    public function handle(PaymentService $payments): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            app(OrganizationContext::class)->resolveForOrganization($org);
            Invoice::whereHas('status', fn ($s) => $s->whereIn('code', ['issued', 'partially_paid']))->whereDate('due_date', '<', now()->toDateString())->get()->each(function (Invoice $i) use ($payments, &$n) {
                $payments->refresh($i);
                if ($i->refresh()->status->code === 'overdue' && ($u = User::find($i->created_by))) {
                    Notifier::send($u, 'invoice_overdue', ['title' => $i->number, 'client' => $i->client_name ?? '', 'balance' => (string) $i->balance . ' ' . $i->currency_code], null, ['in_app', 'email'], "/invoices/{$i->id}", $i->organization_id);
                    $n++;
                }
            });
        }
        $this->info("Marked $n invoice(s) overdue.");
        return self::SUCCESS;
    }
}
