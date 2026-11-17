# Phase 7c: Meeting minutes (Guide 6.6)

Lifecycle: draft → pending_approval → approved → published → superseded. Returned minutes go back to draft.

- Start: secretary / organizer / manage_all create the minutes once the meeting is in progress, held or closed. The text starts from the agenda and attendance sheet.
- Edit: draft only, with optimistic locking (`revision`). Reviewers (participants only) and the chair can comment; comments can be resolved.
- Approval: `ApprovalEngine` flow `meeting_minutes` with one step of type `meeting_chair` (the chair of the meeting). The requester can never approve; if the chair cannot be resolved the engine falls back to the top authority.
- Publish: needs visibility (participants, department, organization, named). Confidential meetings allow only participants or named people. A controlled PDF is stored; the previous published version becomes superseded; a held meeting becomes closed.
- Corrections: `revise` creates a new draft from the latest published text, with a reason (3+ characters). Earlier versions stay readable by those who may read them.
- Library: `GET /api/v1/minutes` lists published minutes the user may read, independent of meeting visibility. The PDF is downloaded through `GET /minutes/{id}/pdf` (audited, marks the distribution row).

After applying: `composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsMinutesSeeder && php artisan test --filter=Minutes`
