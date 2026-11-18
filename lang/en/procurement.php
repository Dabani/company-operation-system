<?php

// COPS Phase 8a: procurement (suppliers and purchase requisitions). rw / sw are first-pass translations pending native review.
return [
    'supplier_not_editable' => 'This supplier can no longer be changed.',
    'supplier_bad_move' => 'This change of supplier status is not allowed.',
    'supplier_incomplete' => 'Complete the legal name, tax number, country and a contact (e-mail or phone) before sending the supplier for review.',
    'supplier_reason_required' => 'Give a reason (at least 3 characters).',
    'supplier_approval_title' => 'Supplier onboarding: :name (:code)',
    'supplier_not_approved' => 'Only an approved supplier can be named on a requisition.',
    'preferred_reason_required' => 'Say why this supplier is preferred (at least 3 characters).',
    'bank_pending_exists' => 'A bank account change is already waiting for confirmation.',
    'bank_not_pending' => 'This bank account is not waiting for confirmation.',
    'bank_same_person' => 'Bank details must be confirmed by someone other than the person who entered them.',
    'requisition_not_editable' => 'This requisition can no longer be changed.',
    'requisition_missing' => 'This information is required before the requisition can be submitted.',
    'exception_required' => 'The requisition is over the project budget. Explain the exception (at least 10 characters) to submit it.',
    'tag_over_budget' => '(over budget)',
    'tag_split' => '(possible split purchase)',
    'requisition_approval_title' => 'Purchase requisition :code: :title',
    'requisition_bad_move' => 'This change of status is not allowed.',
];
