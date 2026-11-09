<?php

// COPS Phase 4a: HR messages.
return [
    'employment' => [
        'pending_exists' => 'This employee already has a change waiting for approval.',
        'date_order' => 'The effective date must be after the latest recorded change.',
        'salary_required' => 'A salary change needs the new salary amount.',
        'currency_required' => 'A salary needs a currency.',
        'nothing_changed' => 'Nothing would change.',
        'confirmation_needs_category' => 'A confirmation must set the new employee category.',
        'use_change' => 'This field has a dated history: record an employment change instead of editing it.',
        'approval_title' => [
            'promotion' => 'Promotion: :name',
            'confirmation' => 'Confirmation: :name',
            'salary_change' => 'Salary change: :name',
            'category_change' => 'Category change: :name',
            'transfer' => 'Transfer: :name',
            'manager_change' => 'Manager change: :name',
        ],
    ],
    'criteria' => [
        'must_be_higher' => 'The target grade must be higher than the starting grade.',
        'exists' => 'This progression rule already exists.',
    ],
    'review' => [
        'cycle_closed' => 'This review cycle is closed.',
        'rating_required' => 'Choose a rating before submitting.',
    ],
    'reminder' => [
        'probation' => ['title' => 'Probation ends soon: :name', 'body' => 'Probation of :name ends on :date (in :days days). Decide on confirmation or extension.'],
        'contract' => ['title' => 'Contract ends soon: :name', 'body' => 'The contract of :name ends on :date (in :days days). Renew or plan the exit.'],
    ],
];
