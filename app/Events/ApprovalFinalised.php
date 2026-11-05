<?php

namespace App\Events;

use App\Models\ApprovalRequest;

/** Fired after an approval request reaches a final or returned state. outcome: approved | rejected | returned | cancelled. */
class ApprovalFinalised
{
    public function __construct(public ApprovalRequest $request, public string $outcome) {}
}
