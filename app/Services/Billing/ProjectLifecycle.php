<?php

namespace App\Services\Billing;

use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Lookup;
use Illuminate\Validation\ValidationException;

/** Delivered / Closed gates (Guide 4.10): no unresolved work to deliver, no unissued invoices to close. */
class ProjectLifecycle
{
    public function __construct(private InvoiceService $invoices) {}

    private function fail(string $key, array $p = []): never { throw ValidationException::withMessages(['project' => [__("billing.project.$key", $p)]]); }

    /** @return array{project: Project, invoice: ?Invoice, invoice_error: ?string} */
    public function deliver(Project $p, User $by): array
    {
        $p->loadMissing('status');
        if (in_array($p->status->code, ['delivered', 'closed', 'cancelled'], true)) $this->fail('already_final');
        $open = Task::where('project_id', $p->id)->whereHas('status', fn ($s) => $s->whereNotIn('code', ['done', 'cancelled']))->count();
        if ($open > 0) $this->fail('open_tasks', ['count' => $open]);
        $reviews = ApprovalRequest::whereIn('subject_id', Task::where('project_id', $p->id)->pluck('id'))->whereHas('status', fn ($s) => $s->where('code', 'pending'))->count();
        if ($reviews > 0) $this->fail('pending_reviews', ['count' => $reviews]);

        $p->update(['status_id' => Lookup::id('project_status', 'delivered')]);
        AuditLogger::log('project.delivered', $p);

        $invoice = null;
        $error = null;
        if ($p->billing_model_id && $p->client_id) {
            try { $invoice = $this->invoices->generateDraft($p->refresh(), $by); }
            catch (ValidationException $e) { $error = collect($e->errors())->flatten()->first(); }     // delivery stands; the draft can be fixed and generated later
        }
        return ['project' => $p->refresh(), 'invoice' => $invoice, 'invoice_error' => $error];
    }

    public function close(Project $p, ?string $exceptionReason): Project
    {
        $p->loadMissing('status');
        if ($p->status->code === 'closed') $this->fail('already_final');
        $unissued = Invoice::where('project_id', $p->id)->whereHas('status', fn ($s) => $s->whereIn('code', ['draft', 'pending_approval']))->count();
        if ($unissued > 0 && !$exceptionReason) $this->fail('unissued_invoices', ['count' => $unissued]);
        $p->update(['status_id' => Lookup::id('project_status', 'closed')]);
        AuditLogger::log('project.closed', $p, [], ['unissued_invoices' => $unissued, 'exception_reason' => $exceptionReason]);
        return $p->refresh();
    }
}
