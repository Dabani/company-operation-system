<?php

namespace App\Services\Hr;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Requisition approval: approved / rejected / cancelled update the requisition; "returned" keeps it pending (resubmit or cancel). */
class RequisitionApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private RecruitmentService $svc) {}
    public function approved(ApprovalRequest $r): void { $this->svc->requisitionOutcome($r->subject_id, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->svc->requisitionOutcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void { $this->svc->requisitionOutcome($r->subject_id, 'cancelled'); }
}
