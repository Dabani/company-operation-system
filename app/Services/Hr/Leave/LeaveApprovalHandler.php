<?php

namespace App\Services\Hr\Leave;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Leave: approved / rejected / cancelled settle the request; "returned" keeps it pending until it is resubmitted or cancelled. */
class LeaveApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private LeaveRequestService $svc) {}
    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled'); }
}
