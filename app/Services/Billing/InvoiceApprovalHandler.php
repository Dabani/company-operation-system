<?php

namespace App\Services\Billing;

use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Services\Approvals\ApprovalOutcomeHandler;
use Illuminate\Validation\ValidationException;

class InvoiceApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private InvoiceService $invoices) {}

    public function approved(ApprovalRequest $r): void
    {
        if (!($inv = Invoice::find($r->subject_id))) return;
        try { $this->invoices->issue($inv, null); }
        catch (ValidationException $e) { $this->invoices->revertToDraft($inv, 'issue blocked after approval: ' . collect($e->errors())->flatten()->first()); }
    }

    public function rejected(ApprovalRequest $r): void { ($inv = Invoice::find($r->subject_id)) && $this->invoices->revertToDraft($inv, 'approval rejected'); }
    public function returned(ApprovalRequest $r): void { ($inv = Invoice::find($r->subject_id)) && $this->invoices->revertToDraft($inv, 'approval returned for changes'); }
    public function cancelled(ApprovalRequest $r): void { ($inv = Invoice::find($r->subject_id)) && $this->invoices->revertToDraft($inv, 'approval cancelled'); }
}
