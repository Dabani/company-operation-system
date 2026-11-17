# Phase 7e: Events with registration (Appendix A.4)

The Guide names the entities (events, event registrations, event attendance, event expenses) but gives no detailed rules, so the design below is an assumption to confirm with the product owner.

- Event: code EVT-nnnn, type (training, conference, workshop, social, open day, other), organizer, department / project link, time (UTC, shown in the organization time zone), venue and/or online link, capacity (empty = unlimited), waitlist on/off, registration window, optional budget (used by the expenses bundle), public sign-up for external guests on/off.
- Lifecycle: draft -> open (registration) <-> closed -> in progress -> completed; draft/open/closed -> cancelled (reason needed, registrants are told). Editing stops when the event starts.
- Registration: staff register themselves (`event.view`); the organizer adds colleagues and guests at any time. Capacity counts registered people; when full, new people go on the waitlist (if enabled) and are promoted oldest first when a seat frees up or the capacity is raised. Capacity is checked under a row lock.
- Guests: when enabled the organizer shares a public link (`/event-register/{token}`, rotatable). Guests get a personal ticket link (QR) to view or cancel their registration. Public routes are rate limited and show no list of other people.
- Check-in: the organizer (or manage-all) scans the ticket code/link or ticks people while the event is in progress; a late correction after completion is allowed for managers and audited; undoing a check-in needs a reason.
- Figures: registered, waitlisted, cancelled, attended, no-shows, attendance rate, free seats. Attendance export (csv/xlsx) needs `data.export` and is audited.
- Permissions: `event.view` (everyone), `event.create` (team leaders, managers, HR), `event.manage_all` (HR, admin).

After applying: `composer dump-autoload && php artisan migrate && php artisan db:seed --class=EventsSeeder && php artisan test --filter=EventRules`
