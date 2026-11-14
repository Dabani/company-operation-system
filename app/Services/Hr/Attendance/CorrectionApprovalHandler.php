<?php

namespace App\Services\Hr\Attendance;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Attendance corrections: approved / rejected / cancelled update the correction; "returned" keeps it pending (resubmit or cancel). */
class CorrectionApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private AttendanceService $svc) {}
    public function approved(ApprovalRequest $r): void { $this->svc->correctionOutcome($r->subject_id, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->svc->correctionOutcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void { $this->svc->correctionOutcome($r->subject_id, 'cancelled'); }
}
