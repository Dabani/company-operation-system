<?php

// COPS Phase 4c: attendance messages.
return [
    'already_in' => 'You are already clocked in.',
    'not_clocked_in' => 'You are not clocked in.',
    'too_soon' => 'Too soon after your last punch.',
    'period_validated' => 'This month has been validated and is locked.',
    'network_not_allowed' => 'You can only clock in from the organization\'s network.',
    'month_not_over' => 'A month can be validated only after it is over.',
    'already_validated' => 'This month is already validated.',
    'cannot_validate' => 'The month cannot be validated yet: :pending correction(s) waiting for approval and :incomplete day(s) with a missing clock-out.',
    'not_validated' => 'This month is not validated.',
    'correction_future' => 'A correction cannot be for a future day.',
    'correction_order' => 'The end time must be after the start time.',
    'correction_too_long' => 'That is longer than the longest allowed work period.',
    'correction_pending' => 'A correction for this day is already waiting for approval.',
    'approval_title' => 'Attendance correction: :name, :date',
    'import_empty' => 'The file is empty.',
    'import_columns' => 'The file needs an employee_number column and a timestamp column.',
    'import_too_many' => 'The file has more than :max rows.',
    'cidr_invalid' => 'Enter an IP address or a range such as 10.0.0.0/24.',
    'import_reason' => [
        'unknown_employee' => 'Unknown employee number',
        'bad_timestamp' => 'Unreadable date and time',
        'future' => 'Date and time in the future',
        'bad_direction' => 'Direction must be in or out',
        'period_validated' => 'Month already validated',
    ],
];
