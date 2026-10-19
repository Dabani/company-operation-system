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
    'movement_requested' => [
        'title' => 'Equipment movement :ref',
        'body' => 'The request :ref by :user (to :dest) awaits your approval.',
    ],
    'movement_decided' => [
        'title' => 'Movement :ref :decision',
        'body' => 'Your equipment movement request :ref was :decision by :user.',
    ],
    'movement_overdue' => [
        'title' => 'Equipment overdue :ref',
        'body' => 'Equipment from :ref (:user) was due back on :date.',
    ],
    'incident_reported' => [
        'title' => 'Incident :ref (:priority)',
        'body' => '(:priority) :user reported ":title".',
        'sms' => ':app: INCIDENT :ref (:priority) from :user. Please check the platform.',
    ],
    'incident_sla_containment' => [
        'title' => 'Containment overdue :ref',
        'body' => 'Incident :ref ":title" has not been contained within its target.',
        'sms' => 'Containment overdue for :ref. Please act now.',
    ],
    'incident_sla_rca' => [
        'title' => 'Root cause analysis overdue :ref',
        'body' => 'Incident :ref ":title" has no completed root cause analysis within its target.',
    ],
    'incident_contained' => [
        'title' => 'Incident :ref contained',
        'body' => 'The incident ":title" has been contained by :user.',
    ],
    'access_granted' => [
        'title' => 'Access granted',
        'body' => 'You were granted access (:level) to :res by :user.',
    ],
    'access_revoked' => [
        'title' => 'Access revoked',
        'body' => 'Your access to :res was revoked by :user.',
    ],
];
