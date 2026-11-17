<?php

namespace App\Services\Meetings;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Approval subject "meeting_minutes": approved freezes the version; rejected, returned or cancelled send it back to the secretary as a draft. */
class MinutesApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private MinutesService $svc) {}

    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved', $r); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected', $r); }
    public function returned(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'returned', $r); }
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled', $r); }
}
