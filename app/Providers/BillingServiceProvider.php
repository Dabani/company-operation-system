<?php

namespace App\Providers;

use App\Console\Commands\InvoicesOverdue;
use App\Services\Billing\CreditNoteApprovalHandler;
use App\Services\Billing\InvoiceApprovalHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 2b wiring: invoice / credit-note approval handlers and the overdue job. */
class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config(['cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['invoice' => InvoiceApprovalHandler::class, 'credit_note' => CreditNoteApprovalHandler::class])]);
        $this->commands([InvoicesOverdue::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('cops:invoices-overdue')->dailyAt('06:00')->withoutOverlapping());
    }
}
