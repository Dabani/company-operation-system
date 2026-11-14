<?php

namespace App\Services\Hr;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

class EmploymentApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private EmploymentService $employment) {}

    public function approved(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'effective'); }
    public function rejected(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'rejected'); }
    /** Returned for changes: the row stays pending. The requester resubmits it or cancels it (then proposes a new dated change). */
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'cancelled'); }
}
