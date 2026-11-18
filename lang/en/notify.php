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
    'record_transfer_due' => [
        'title' => 'Records overdue for transfer',
        'body' => ':count inactive record(s) missed the transfer-to-archive window.',
    ],
    'record_retention_due' => [
        'title' => 'Records ready for disposal review',
        'body' => ':count archived record(s) have reached the end of their retention period.',
    ],
    'archive_access_requested' => [
        'title' => 'Archive access request :ref',
        'body' => ':user asks to access record :ref.',
    ],
    'archive_access_decided' => [
        'title' => 'Archive access :decision',
        'body' => ':user: your access request for :ref was :decision.',
    ],
    'inventory_discrepancy' => [
        'title' => 'Inventory discrepancy: :title',
        'body' => ':missing record(s) were not found in the inventory ":title" (checked by :user).',
    ],
    'disposal_proposed' => [
        'title' => 'Disposal batch :ref',
        'body' => ':user proposed :count record(s) for disposal. Your approval is needed.',
    ],
    'disposal_decided' => [
        'title' => 'Disposal batch :ref :decision',
        'body' => 'Your disposal proposal :ref was :decision by :user.',
    ],
    'backup_issue' => [
        'title' => 'Backup verification: :result',
        'body' => ':user verified the :date backup at :loc: :result. Action needed.',
    ],
    'management_report' => [
        'title' => 'Management report: :title',
        'body' => ':user submitted the report :title (:period).',
    ],
    'training_invited' => [
        'title' => 'Training: :title',
        'body' => 'You are invited to the training ":title" on :date.',
    ],
    'audit_finding_assigned' => [
        'title' => 'Audit finding assigned (:ref)',
        'body' => ':user assigned you the finding ":title".',
    ],
    'obligation_due' => [
        'title' => 'Compliance duty due: :title',
        'body' => '":title" was due on :date.',
    ],
    // COPS Phase 0 approvals
    'approval_requested' => [
        'title' => 'Approval needed: :title',
        'body' => '\':requester\' submitted ":title" and your decision is needed.',
    ],
    'approval_approved' => [
        'title' => 'Approved: :title',
        'body' => '":title" was approved by :actor.',
    ],
    'approval_rejected' => [
        'title' => 'Rejected: :title',
        'body' => '":title" was rejected by :actor. Reason: :comment',
    ],
    'approval_returned' => [
        'title' => 'Returned for changes: :title',
        'body' => '":title" was returned by :actor. Comment: :comment',
    ],
    'approval_escalated' => [
        'title' => 'Reminder: :title is waiting',
        'body' => '":title" from :requester has waited more than :hours hours for a decision.',
    ],
    // COPS Phase 1
    'task_assigned' => [
        'title' => 'Task assigned: :title',
        'body' => ':actor assigned you ":title".',
    ],
    // COPS Phase 2a
    'timer_auto_stopped' => [
        'title' => 'Timer stopped automatically',
        'body' => 'Your timer on :title ran past :hours hours and was stopped. Please correct the entry before submitting your timesheet.',
    ],
    // COPS Phase 2b
    'invoice_overdue' => [
        'title' => 'Invoice overdue: :title',
        'body' => 'Invoice :title for :client is overdue. Balance: :balance.',
    ],
    // COPS Phase 3
    'portal_client_comment' => [
        'title' => 'Client comment: :title',
        'body' => ':client wrote a comment on :title.',
    ],
    'portal_client_upload' => [
        'title' => 'Client upload: :title',
        'body' => ':client uploaded a file to :title. It was virus-scanned and is waiting in the project files.',
    ],
    'meeting_invited' => [
        'title' => 'Invitation: :title (:ref)',
        'body' => ':organizer invites you to ":title" on :when. Where: :where.',
    ],
    'meeting_updated' => [
        'title' => 'Meeting updated: :title',
        'body' => ':title (:ref) changed. Now: :when, :where.',
    ],
    'meeting_cancelled' => [
        'title' => 'Meeting cancelled: :title',
        'body' => ':title (:ref) planned for :when was cancelled. :reason',
    ],
    'meeting_reminder' => [
        'title' => 'Reminder: :title',
        'body' => ':title starts in :in (:when). Where: :where.',
    ],
    'meeting_agenda_circulated' => [
        'title' => 'Agenda circulated: :title',
        'body' => 'The agenda of ":title" (:ref) is available.',
    ],
    'meeting_rsvp_declined' => [
        'title' => 'Declined: :title',
        'body' => ':who declined ":title". :note',
    ],
    'meeting_rsvp_proposed' => [
        'title' => 'New time proposed: :title',
        'body' => ':who proposes another time for ":title". :note',
    ],
    'meeting_minutes_review' => [
        'title' => 'Minutes to review: :title',
        'body' => 'Please review the minutes of ":title" (:ref). Requested by :by.',
    ],
    'meeting_minutes_returned' => [
        'title' => 'Minutes returned: :title',
        'body' => 'The minutes of ":title" (:ref) were returned for changes. :note',
    ],
    'meeting_minutes_published' => [
        'title' => 'Minutes published: :title',
        'body' => 'The minutes of ":title" (:ref), version :version, are available.',
    ],
    'meeting_followup_assigned' => [
        'title' => 'Assigned to you: :title',
        'body' => '":title" (:ref) from the meeting ":meeting" was assigned to you by :by. Due: :due.',
    ],
    'meeting_followup_due' => [
        'title' => 'Due soon: :title',
        'body' => '":title" (:ref) from the meeting ":meeting" is due on :due.',
    ],
    'meeting_followup_overdue' => [
        'title' => 'Overdue: :title',
        'body' => '":title" (:ref) from the meeting ":meeting" was due on :due (:days days late).',
    ],
    'meeting_followup_done' => [
        'title' => 'Action done: :title',
        'body' => '":title" (:ref) from the meeting ":meeting" was marked done and is waiting for your check.',
    ],
    'event_registered' => [
        'title' => 'Registered: :title',
        'body' => 'You are registered for ":title" (:ref) on :when. Place: :where.',
    ],
    'event_waitlisted' => [
        'title' => 'On the waitlist: :title',
        'body' => 'The event ":title" (:ref) is full. You are on the waitlist and will be told if a seat frees up.',
    ],
    'event_waitlist_promoted' => [
        'title' => 'A seat is available: :title',
        'body' => 'Good news: a seat freed up and you are now registered for ":title" (:ref) on :when. Place: :where.',
    ],
    'event_cancelled' => [
        'title' => 'Event cancelled: :title',
        'body' => 'The event ":title" (:ref) planned for :when was cancelled. :reason',
    ],
    'event_expense_returned' => [
        'title' => 'Expense not approved: :title',
        'body' => 'The expense ":what" for ":title" was :outcome. :note',
    ],
    'event_expense_approved' => [
        'title' => 'Expense approved: :title',
        'body' => 'The expense ":what" for ":title" was approved.',
    ],
    'supplier_decided' => [
        'title' => 'Supplier review: :name',
        'body' => 'The review of supplier :name ended: :outcome. :note',
    ],
    'requisition_decided' => [
        'title' => 'Purchase requisition :code: :outcome',
        'body' => 'Your requisition ":title" was :outcome. :note',
    ],
];
