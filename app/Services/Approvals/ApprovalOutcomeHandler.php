<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;

/**
 * Each module registers one handler per approval subject type in config/cops.php ('approval_handlers').
 * The engine never knows about leave, invoices or payroll - handlers apply the outcome to their own records.
 */
interface ApprovalOutcomeHandler
{
    public function approved(ApprovalRequest $request): void;
    public function rejected(ApprovalRequest $request): void;
    public function returned(ApprovalRequest $request): void;
    public function cancelled(ApprovalRequest $request): void;
}
