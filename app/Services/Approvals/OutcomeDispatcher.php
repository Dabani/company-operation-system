<?php

namespace App\Services\Approvals;

use App\Events\ApprovalFinalised;

/** Registered explicitly in CopsFoundationServiceProvider (kept outside app/Listeners to avoid double registration by event discovery). */
class OutcomeDispatcher
{
    public function handle(ApprovalFinalised $e): void
    {
        $class = config('cops.approval_handlers.' . $e->request->subjectType->code);
        if ($class) {
            app($class)->{$e->outcome}($e->request);
        }
    }
}
