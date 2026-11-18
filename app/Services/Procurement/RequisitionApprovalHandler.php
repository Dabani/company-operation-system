<?php

namespace App\Services\Procurement;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Approval subject "purchase_requisition": the outcome is applied by RequisitionService. */
class RequisitionApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private RequisitionService $svc) {}

    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved', $r); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected', $r); }
    public function returned(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'returned', $r); }
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled', $r); }
}
