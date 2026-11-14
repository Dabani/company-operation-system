# COPS Phase 4c - Attendance and working time (API)

Scope (Guide 5.3): web clock-in/out, shifts and schedules, public holidays, lateness / overtime / absence, CSV import, corrections with approval, month validation for payroll.
With this bundle Phase 4 (HR core) is complete: employees and history (bundle 14), recruitment to active employee (bundle 15), attendance (bundle 16).

## Rules implemented
- **Clock**: web punches use the **server clock only** (UTC). In/out alternate; a second "in" is refused; punches closer than `punch_min_interval_seconds` (default 30) are refused as double taps. An "in" older than `pairing_window_hours` (default 20) no longer counts as open.
- **Raw events are append-only** (never edited or deleted). The daily record per employee is **derived** from the events, the employee's shift for that date, public holidays, approved leave and approved corrections.
- **Shift for a date**: a one-day override wins (a shift, or a day off); otherwise the latest dated schedule assignment on or before the date. Schedules assign a shift to each ISO weekday; a weekday without a shift is a day off. Nothing is pre-filled.
- **Lateness**: minutes after the scheduled start once the shift's grace period is exceeded (counted from the start). **Overtime**: worked minutes beyond the expected minutes (all worked minutes on a day without a shift or on a holiday) when they reach `overtime_min_minutes` (default 15). It is a measurement for payroll: no pay rate or legal limit is applied here. `auto_deduct_break` deducts the unpaid break only when a single in/out pair was punched.
- **Statuses**: present, late, absent, holiday, day_off (worked without a shift), leave, incomplete (missing clock-out). Absence is decided only after the day is over (daily job 02:30 UTC covers the last two completed local days; clocking and imports update the day at once). Night shifts: the day belongs to the date of the clock-in.
- **Leave** is read through the `LeaveLookup` interface; until Phase 5 nobody is on leave (`NullLeaveLookup`). Phase 5 rebinds the interface so approved leave is not an absence.
- **Corrections**: a full replacement of one day (in and out, in the organization's timezone) with a mandatory reason, approved by the line manager (falls back to the top authority); the proposer never approves. The raw events stay untouched; the day shows it is corrected. One pending correction per day.
- **Month validation**: a month can be validated only after it is over, with no pending correction and no incomplete day. Validation recomputes the month once more and **freezes** it: clocking, imports, corrections, overrides and recomputation are refused or skipped for that month. Reopening needs `hr.attendance.reopen` (admin only by default) and a reason; both actions are audited. Payroll (Phase 6) will use only validated months.
- **Optional network restriction** (off until a network is added): clocking only from the listed IP ranges; the address is then stored with the punch. The clock screen must tell staff that the address is checked and kept (the API returns `ip_restricted`). Geolocation is **not** implemented (needs legal review first).
- **Timezone**: the organization's timezone (lookup) decides which local date a punch belongs to; instants are stored in UTC.

## CSV import (device neutral)
Columns are found by name (header row required; `,` `;` or tab separated): `employee_number` (also employee, number, badge, id), `timestamp` (also datetime, time, punch), `direction` optional (in/out, i/o, 1/0, entry/exit).
Timestamps are local time: `2026-11-02 08:03[:05]`, `2026-11-02T08:03`, `02/11/2026 08:03`, or ISO 8601 with an offset. Without a direction column the direction alternates per employee (in, out, in...). At most 20 000 rows and 5 MB. Re-importing a file is safe: duplicates and double taps are skipped; rejected lines (unknown employee, unreadable or future date, bad direction, validated month) are listed with their line number. Excel files: save as CSV first. The guide leaves the biometric device format open (decision list, item 4): send a sample export and the column mapping can be extended.

## Permissions
hr.attendance.clock (everyone with an employee record), .view (team, scope-limited), .correct (propose corrections for others), .approve (approve corrections: manager, hr_manager, admin), .manage (shifts, schedules, holidays, networks, import: hr_manager, admin), .validate (hr_manager, admin), .reopen (admin).
Settings `overtime_min_minutes`, `pairing_window_hours`, `punch_min_interval_seconds` are edited with the other HR settings (`GET/PUT hr/settings`).

## Deployment
1. `python scripts/apply-bundle-16.py <path to cops-api>`
2. `php artisan migrate`
3. `php artisan db:seed --class=HrAttendanceSeeder` (idempotent)
4. `php artisan test`
Then HR creates the shifts, one or more schedules, assigns them, and enters the public holidays of the year.

## Known limits / decisions
- Leave integration waits for Phase 5; geolocation and device-specific import formats are not included; XLSX is not read (CSV only).
- A correction replaces a whole day; adding a single missing punch is done by correcting that day.
- Early departure is not reported separately (worked time and overtime show it).
- Notifications for missing clock-outs are not sent yet.
- No menu items are seeded yet: the screens come with the web bundle (patch adds the menu).
- rw and sw texts are first-pass: native review needed.
- No shift, holiday, rate or legal value is seeded.

## Regression checklist additions
- Clock in/out uses the server time; double clock-in and double taps refused.
- Lateness only beyond the grace period; overtime only above the minimum; night shift counted on the start date.
- Daily job marks absences after the day, never today; holiday is not an absence.
- A correction needs approval; nothing changes before it; the raw events stay.
- Validation blocked by pending corrections or incomplete days; a validated month refuses clocking, corrections and imports; only admin reopens, with a reason.
- Import: duplicates skipped, rejected lines listed, re-import changes nothing.
- Network restriction: outside address refused, address kept only while the restriction is on.
