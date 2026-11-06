<?php

// COPS Phase 1 messages. rw/sw are first-pass translations pending native review.
return [
    'task' => [
        'transition_not_allowed' => 'This move is not allowed from the task\'s current status.',
        'blocked' => 'Finish or cancel the tasks this one depends on first.',
        'dependency_same_project' => 'A task can only depend on a task in the same project.',
        'dependency_cycle' => 'That dependency would create a loop.',
    ],
    'file' => [
        'not_restricted' => 'Restricted files cannot be attached to projects or tasks.',
    ],
];
