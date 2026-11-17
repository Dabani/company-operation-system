# Phase 7a: Meetings and events, core (backend bundle 17)

Scope (Guide module C, first slice): rooms, meetings with recurrence, room booking with conflict detection, agenda (draft, circulated, approved by the chair),
invitations and RSVP (staff and external guests by personal link), ICS calendar files, reminders, confidential meetings.
Not in this bundle (next Phase 7 bundles): minutes, attendance and check-in, resolutions and actions, reports.

## Apply
    python3 apply_b17_backend.py --repo <backend repo> --dry-run
    python3 apply_b17_backend.py --repo <backend repo>
    composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsSeeder
    php artisan test --filter=MeetingsPureLogic
Existing files edited (backups in .cops-backup/b17): routes/api.php, CodeGenerator, SettingsService, DatabaseSeeder, config/ops.php (rich text field `purpose`),
bootstrap/providers.php, lang/*/notify.php. Add `.cops-backup/` to .gitignore or delete it after review.

## Rules
- Lifecycle: draft, scheduled, in_progress, held, closed; cancel from draft or scheduled. A meeting is editable only in draft or scheduled.
- Room booking: row lock on the room; back-to-back is allowed; cancelling releases the booking.
- Recurrence: daily, weekly, monthly, wall clock kept in the organization timezone, at most 52 occurrences.
- Visibility: host (organizer, chair, secretary, facilitator), invitee, meeting.view_all, department scope. Confidential meetings need meeting.view_confidential (audited); a meeting outside your visibility answers 404.
- Settings: meeting.reminder_offsets (default 1440,60 minutes), meeting.reminder_sms (default off). Job: cops:meetings-remind every 5 minutes.
- Permissions: meeting.view, create, view_all, view_confidential, manage_all, room.manage. Defaults: staff view; team leader and manager add create; HR manager adds view_all and room.manage; admin all.

## Dependencies and open points
- Phase 5 and 6 are not required by this bundle. Project and department links use the existing tables.
- rw and sw labels are first-pass and need native review.
- Not run here: Laravel feature tests and migrations (composer could not install in the build sandbox). Run the migration and the unit tests on your side before use.
