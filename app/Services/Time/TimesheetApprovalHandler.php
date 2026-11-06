<?php

namespace App\Services\Time;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

class TimesheetApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private TimesheetService $sheets) {}

    public function approved(ApprovalRequest $r): void { $this->sheets->applyOutcome($r->subject_id, true, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->sheets->applyOutcome($r->subject_id, false, 'rejected'); }
    public function returned(ApprovalRequest $r): void { $this->sheets->applyOutcome($r->subject_id, false, 'returned for changes'); }
    public function cancelled(ApprovalRequest $r): void { $this->sheets->applyOutcome($r->subject_id, false, 'cancelled'); }
}
