<?php

namespace App\Services\Rules;

use App\Models\ApprovalRequest;
use App\Models\RuleVersion;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Applies the outcome of a "rule_activation" approval to the rule version it was raised for. */
class RuleActivationHandler implements ApprovalOutcomeHandler
{
    public function __construct(private RuleLifecycle $lifecycle) {}

    private function version(ApprovalRequest $r): ?RuleVersion { return RuleVersion::find($r->subject_id); }

    public function approved(ApprovalRequest $r): void { ($v = $this->version($r)) && $this->lifecycle->activate($v); }
    public function rejected(ApprovalRequest $r): void { ($v = $this->version($r)) && $this->lifecycle->revert($v, 'approval rejected'); }
    public function returned(ApprovalRequest $r): void { ($v = $this->version($r)) && $this->lifecycle->revert($v, 'approval returned for changes'); }
    public function cancelled(ApprovalRequest $r): void { ($v = $this->version($r)) && $this->lifecycle->revert($v, 'approval cancelled'); }
}
