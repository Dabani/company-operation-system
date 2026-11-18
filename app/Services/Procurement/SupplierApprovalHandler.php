<?php

namespace App\Services\Procurement;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Approval subject "supplier": the outcome is applied by SupplierService. */
class SupplierApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private SupplierService $svc) {}

    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved', $r); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected', $r); }
    public function returned(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'returned', $r); }
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled', $r); }
}
