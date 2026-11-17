# Phase 7b: Meeting attendance (backend bundle 18, Guide 6.5)

Needs bundle 17 (meetings core). Not yet in this slice: minutes (6.6), resolutions and actions (6.7), reports.

## Apply
    python3 apply_b18_backend.py --repo <backend repo> --dry-run
    python3 apply_b18_backend.py --repo <backend repo>
    composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsAttendanceSeeder
    php artisan test --filter=MeetingAttendanceRules
Existing files edited (backups in .cops-backup/b18): routes/api.php, SettingsService, DatabaseSeeder, MeetingService (start and hold now build the expected list), lang/*/meetings.php.

## Rules
- The expected list is built from the invitations when the meeting starts (or is held without being started). "Refresh the list" adds late invitees; nothing is reset.
- Statuses (lookup meeting_attendance_status): expected, present, late, online, absent, excused. Methods: secretary, self, walk_in.
- Recording is open from the start. After the meeting is held or closed every change is a correction: a reason (3+ characters) is required, the history row is flagged and the audit log records it.
- Self check-in: the secretary shows the QR code or link (only while the meeting is in progress). The participant must be signed in and on the invitation list (anyone else gets 404). Arriving within meeting.late_after_minutes (default 10) of the start is "present", later is "late"; "online" if they say so. External guests are checked in by the secretary. "Renew the link" invalidates the old QR code.
- Acknowledgement: with meeting.attendance_ack on, attendees confirm their own line; the confirmation date appears on the sheet and exports.
- Visibility: the organizer, secretary, chair and manage-all holders see the full sheet; other participants see only their own line.
- Export (PDF landscape with a confirmation column, XLSX, CSV) needs data.export and sheet access; every export is audited.
- New dependency (frontend): qrcode.react. Run npm install after applying the frontend bundle.
