# Bundle 37: a chair with a custom role could not open the meeting or approve the minutes

**Cause.** `meeting.view` (every meeting page and the minutes routes) and `approval.act` (deciding approvals) were granted only to the role codes
staff, team_leader, manager, hr_manager (and admin). A role created later in the Roles page, such as "IT Manager", had neither. Notifications are
sent to the named person whatever their role, so the chair was told about the meeting and the minutes but received a 403 when opening them.

**Fix.** Both permissions only act on the person's own records (a meeting they organise, chair, keep or are invited to; an approval assigned to them),
so they are baseline rights: the seeder gives `meeting.view`, `approval.view`, `approval.act` to every existing role, and RoleController gives them to every new role.
Nothing else is widened: `meeting.view_all`, `meeting.manage_all`, `approval.view_all` stay opt-in.

**After applying.** Run `php artisan db:seed --class=MeetingBaselineAccessSeeder`. The user must reload (the permission list is read at sign-in/refresh).
Check: chair opens the meeting, Minutes tab, then Approvals inbox shows the draft minutes and Approve works.
