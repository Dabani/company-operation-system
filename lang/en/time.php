<?php

// COPS Phase 2a messages. rw/sw are first-pass translations pending native review.
return [
    'entry' => [
        'locked' => 'Approved or invoiced time cannot be changed. Ask a manager for an adjustment.',
        'overlap' => 'This time overlaps another entry or a running timer.',
        'week_locked' => 'That week\'s timesheet is already submitted or approved. Ask a manager for an adjustment.',
        'project_closed' => 'This project is closed and no longer accepts time.',
        'task_invalid' => 'This task is not available for time entries.',
        'times_fixed' => 'Times recorded by the timer cannot be edited unless the entry was auto-stopped.',
    ],
    'timer' => [
        'already_running' => 'A timer is already running. Stop it first.',
        'none' => 'There is no running timer.',
        'already_paused' => 'The timer is already paused.',
    ],
    'manual' => [
        'disabled' => 'Manual time entries are disabled by your organization.',
        'reason_required' => 'A reason of at least 5 characters is required.',
        'end_before_start' => 'The end must be after the start.',
        'too_long' => 'A single entry cannot be longer than 24 hours.',
        'future' => 'An entry cannot end in the future.',
        'too_old' => 'This date is older than your organization allows for manual entries.',
    ],
    'adjust' => [
        'only_locked' => 'Only approved or invoiced entries can be adjusted.',
        'range' => 'An adjustment must be non-zero and within 24 hours.',
        'below_zero' => 'The adjustment would make the total negative.',
    ],
    'timesheet' => [
        'not_draft' => 'Only a draft timesheet can be submitted.',
        'timer_open' => 'Stop or discard the running timer before submitting the week.',
        'empty' => 'There are no draft entries to submit for this week.',
        'needs_correction' => 'Correct the entries flagged as auto-stopped before submitting.',
        'negative' => 'The week total cannot be negative.',
    ],
    'rate' => [
        'scope_value_required' => 'This scope needs a role, person or task.',
    ],
];
