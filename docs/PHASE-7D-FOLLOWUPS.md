# Phase 7d: Resolutions and action items (Guide 6.7)

- Resolution: number (RES-0001 org-wide, R1 inside the meeting), title, text, agenda item, responsible person (a participant) and/or department, due date, status (open, in progress, completed, cancelled), supporting files (Controlled Files, link type `meeting_resolution`).
- Action item: number (ACT-0001), owner (a participant), due date, optional resolution, status (open, in progress, done, closed, cancelled). Marking an action done needs a result note (3+ characters) or an attached file. Closing is a check by a meeting runner who is not the owner. Re-opening and cancelling need a reason.
- Who: organizer, secretary, chair and manage-all run the registers; owners report progress and completion on their own items. Items follow the visibility of their meeting (404 otherwise).
- Conversions: `convert-task` creates a project task (needs edit rights on the project) and `convert-obligation` creates a compliance duty (needs `compliance.manage`, due date required). Both keep a link back (task text / obligation link route to the meeting); the resolution shows the task status.
- Reminders: `cops:meetings-followups` runs hourly. Owners are told once when an item comes due soon (`meeting.followup_due_soon_days`, default 2) and again every `meeting.followup_overdue_repeat_days` days (default 3) while overdue; the secretary and organizer are told about overdue items.
- Dashboard: `GET /api/v1/meeting-followups/summary` (counts, completion rate, overdue by owner, mine) and `GET /api/v1/meeting-followups?kind=action|resolution&mine=1&overdue=1&status=&meeting_id=&search=`.
- Every change is in `meeting_followup_history` and in the audit log.

After applying: `composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsFollowUpSeeder && php artisan test --filter=FollowUp`
