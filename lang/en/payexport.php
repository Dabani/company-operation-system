<?php

// COPS Phase 6c: payroll export messages (en).
return [
    'err' => [
        'verify_not_author' => 'A layout must be verified by someone other than the person who prepared it.',
        'already_verified' => 'This layout is already verified.',
        'verified_is_final' => 'A verified layout is never edited or deleted: switch it off and create a new one.',
        'delimiter_required' => 'A delimited layout needs a delimiter.',
        'source_not_allowed' => 'The field ":source" is not available for a :kind file.',
        'scheme_required' => 'This field needs a scheme.',
        'component_required' => 'This field needs a pay component.',
        'constant_required' => 'A constant column needs its value.',
        'width_required' => 'A fixed-width column needs a width.',
        'profile_not_verified' => 'This layout is not verified (or is switched off): nothing can be generated from it.',
        'run_state' => 'A :kind file can only be generated when the run is: :allowed.',
        'adjustment_unsupported' => 'Adjustment runs support the bank file only: declarations and the journal for corrections are handled with the accountant.',
        'segregation' => 'Separation of duties: the person who prepared or calculated the run cannot generate its bank file.',
        'accounts_missing' => 'Journal accounts are not configured for: :keys.',
        'journal_unbalanced' => 'The journal does not balance: it was not generated.',
        'bank_account_missing' => 'No bank account recorded for: :names.',
        'no_rows' => 'There is nothing to export for this run.',
        'filename_placeholder' => 'Unknown placeholder {:name} in the file name.',
    ],
    'format' => [
        'no_columns' => 'The layout has no column.',
        'not_numeric' => 'A number was expected: :detail',
        'delimiter_in_value' => 'A value contains the delimiter and the layout has no quote character: :detail',
        'width_required' => 'A fixed-width column has no width: :detail',
        'overflow' => 'A value does not fit its column (nothing is cut silently): :detail',
        'unknown_placeholder' => 'Unknown placeholder in the header or trailer: {:detail}',
        'encoding' => 'A character cannot be represented in the chosen encoding: :detail',
    ],
];
