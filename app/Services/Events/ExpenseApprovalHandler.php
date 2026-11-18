<?php

namespace App\Services\Events;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Approval subject "event_expense": the outcome is applied to the expense by ExpenseService. */
class ExpenseApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private ExpenseService $svc) {}

    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved', $r); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected', $r); }
    public function returned(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'returned', $r); }
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled', $r); }
}
