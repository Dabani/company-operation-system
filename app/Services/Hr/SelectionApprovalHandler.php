<?php

namespace App\Services\Hr;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Selection approval: approval moves the applicant to the Offer stage; refusal leaves the stage unchanged. */
class SelectionApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private RecruitmentService $svc) {}
    public function approved(ApprovalRequest $r): void { $this->svc->selectionOutcome($r->subject_id, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->svc->selectionOutcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void {}
}
