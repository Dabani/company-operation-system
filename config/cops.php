<?php

return [
    // approval subject type code => handler class (implements App\Services\Approvals\ApprovalOutcomeHandler).
    // Later phases add their own line here: 'timesheet' => \App\Services\Projects\TimesheetApprovalHandler::class, ...
    'approval_handlers' => [
        'rule_activation' => \App\Services\Rules\RuleActivationHandler::class,
    ],

    // approval subject type code => permission an approver must also hold (segregation of roles from configuration).
    'approval_permissions' => [
        'rule_activation' => 'rules.approve',
    ],
];
