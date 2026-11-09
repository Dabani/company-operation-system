<?php

namespace App\Services\Hr;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

class EmploymentApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private EmploymentService $employment) {}

    public function approved(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'effective'); }
    public function rejected(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'rejected'); }
    public function cancelled(ApprovalRequest $r): void { $this->employment->applyOutcome($r->subject_id, 'cancelled'); }
}
