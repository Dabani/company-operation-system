<?php

namespace App\Services\Hr\Trips;

use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Trips: approval issues the mission authorization; "returned" keeps the trip pending until it is resubmitted or cancelled. */
class TripApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private TripService $svc) {}
    public function approved(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'approved'); }
    public function rejected(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'rejected'); }
    public function returned(ApprovalRequest $r): void {}
    public function cancelled(ApprovalRequest $r): void { $this->svc->outcome($r->subject_id, 'cancelled'); }
}
