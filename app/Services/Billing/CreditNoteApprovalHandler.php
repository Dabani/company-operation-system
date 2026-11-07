<?php

namespace App\Services\Billing;

use App\Models\ApprovalRequest;
use App\Models\CreditNote;
use App\Services\Approvals\ApprovalOutcomeHandler;

class CreditNoteApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private CreditNoteService $notes) {}

    public function approved(ApprovalRequest $r): void { ($c = CreditNote::find($r->subject_id)) && $this->notes->issue($c); }
    public function rejected(ApprovalRequest $r): void { ($c = CreditNote::find($r->subject_id)) && $this->notes->reject($c, 'rejected'); }
    public function returned(ApprovalRequest $r): void { ($c = CreditNote::find($r->subject_id)) && $this->notes->reject($c, 'returned: request a new credit note'); }
    public function cancelled(ApprovalRequest $r): void { ($c = CreditNote::find($r->subject_id)) && $this->notes->reject($c, 'cancelled'); }
}
