<?php

// Notification texts. Placeholders: :ref :title :user :priority :status :app
return [
    'open' => 'Open in the platform',
    'footer' => 'This message was sent automatically. Please do not reply.',
    'chat_escalated' => 'I am connecting you to a support agent. Please wait a moment.',
    'case_created_user' => [
        'title' => 'We received your case :ref',
        'body' => 'Your case ":title" has been registered. We will keep you informed of any progress.',
    ],
    'case_created_admin' => [
        'title' => 'New case :ref',
        'body' => '(:priority) :user submitted ":title".',
        'sms' => ':app: new case :ref from :user (:priority). Please check the platform.',
    ],
    'case_status' => [
        'title' => 'Case :ref updated',
        'body' => 'The status of your case is now: :status.',
    ],
    'case_comment' => [
        'title' => 'New comment on :ref',
        'body' => ':user commented on ":title".',
    ],
    'case_assigned' => [
        'title' => 'Case :ref assigned to you',
        'body' => 'You have been assigned the case ":title".',
    ],
    'chat_waiting' => [
        'title' => 'Chat needs an agent',
        'body' => ':user is waiting for support.',
    ],
    'file_delete_requested' => [
        'title' => 'File deletion requested',
        'body' => ':user asked to delete the file ":file". Open it to approve or reject.',
    ],
    'file_delete_decided' => [
        'title' => 'Deletion request :decision',
        'body' => 'Your deletion request for ":file" was :decision by :user.',
    ],
];
