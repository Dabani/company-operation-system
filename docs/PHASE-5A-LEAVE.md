# COPS Phase 5a - Leave core (API, bundle 17)

Scope (Guide 5.4, 3.7, 8): leave types, statutory (legal) rules with verification, company policy through the rules engine, balances as an append-only ledger,
monthly accrual, carry-over limit and expiry, requests with the approval engine, team calendar, attendance integration, documents.
Not in this bundle: business trips and expenses (bundle 18), web screens (bundle 19).

## How the three layers fit (nothing legal is hard-coded)
| Layer | Where it lives | Who changes it |
|---|---|---|
| Statutory floors / ceilings | `legal_parameters` + `hr_legal_leave_rules` (leave type, policy key, eligibility by sex and age) | entered by `hr.leave.legal.manage`, **verified by a different person with `rules.signoff`** |
| Company policy | rule sets of the **leave** domain in the shared rules engine (conditions: `leave_type`, `employee_category`; outcomes: the keys of `GET leave/policy/keys`) | versioned, effective-dated, approved by someone other than the author (existing Rules screen) |
| Employee facts | `hr_leave_profiles` (date of birth encrypted, sex, weekly hours) | `hr.leave.profile`, every read audited |

**Effective value of a policy key** = the company value, raised to the highest applicable verified floor and lowered to the lowest applicable verified ceiling. With no company value, a floor *is* the value; a ceiling alone creates none.
**Unverified legal rules are shown with an "unverified" flag and are never applied** (Guide 5.4: blocked from production). A rule that needs a fact the profile lacks (age, sex) is reported as `missing_profile`, never guessed.
`GET leave/policy/violations` lists every draft/pending/active policy version that breaks a verified limit (Phase 5 exit criterion: no policy below the statutory floor). `GET leave/policy/preview` is the HR/legal worked-example tool.

## Behaviour
- **Balances** = sum of `hr_leave_ledger` (accrual, usage, reversal, adjustment, carry_forfeit, carry_expiry). Rows are immutable (model enforced). Pending requests reserve days; nothing is deducted until approval.
- **Request**: preview (`POST leave/requests/preview`) returns days, blockers, warnings and balance after; submit repeats the same checks server-side. Blockers: dates, before hire / after end, overlap, locked attendance month, blackout period, retroactive, notice, minimum service, min/max per request, max per year, insufficient balance. Warnings: team conflict (max simultaneous absences), document expected.
- HR entering leave for someone (`hr.leave.manage`) skips notice, retroactive and blackout limits only; legal limits, overlap, balance and locked months still apply.
- **Days counted**: working days of the employee's schedule (Monday-Friday when none is assigned) minus public holidays, or calendar days, per type; half day at the start and/or end. Public holidays inside the range are never deducted.
- **Approval**: subject `leave`, default flow "line manager" (falls back to the top authority); routing rules can use attributes `leave_type`, `leave_days`, `employee_category`. The requester can never approve. On approval: day rows, usage ledger entries (split by leave year) and an attendance recompute (validated months are never touched; a month locked while waiting cancels the request and audits it).
- **Cancel**: pending = requester withdraws via the engine; approved = reversal entries + attendance recompute (requester before it starts; HR any time; blocked in a validated month).
- **Attendance**: `LeaveLookup` is now `DbLeaveLookup`: a full approved leave day is `leave`, never `absent`.
- **Accrual** (`cops:leave-accrue`, daily 01:30, idempotent): for every completed month of the current leave year, posts a twelfth of the annual entitlement (running total = round(annual x months / 12), so 19 days sums to exactly 19.00; a mid-year hire accrues from the hire month, no back-pay); never claws back. Earlier years are an opening balance (HR adjustment).
- **Year end** (`cops:leave-year-end`, daily 02:00, idempotent): forfeits the balance above `carry_over_max_days`, and carried-over days unused after `carry_over_expiry_months` (days taken use carried-over days first). **No policy value = nothing is forfeited.**
- Leave year starts in month `leave_year_start_month` (HR setting, default 1).
- Documents: Controlled Files linked as `leave_request` (classification confidential, hidden from the global Files library).
- Policy versions used are stored on the request (`rule_version_id`), so later policy changes never rewrite history.

## Permissions
`hr.leave.request` (staff), `hr.leave.view` (team leader, manager, HR), `hr.leave.approve` (manager, HR), `hr.leave.manage` (HR), `hr.leave.profile` (HR), `hr.leave.legal.manage` (HR); verification uses the existing `rules.signoff`. admin receives all.

## Deployment
1. `python scripts/apply-bundle-17.py <path to cops-api>`  (backs up every file it touches to `.bundle-17-backup/`; safe to run twice)
2. `php artisan migrate`
3. `php artisan db:seed --class=HrLeaveSeeder`  (idempotent; type settings are created once, never overwritten)
4. `php artisan test`
5. Scheduler already runs `schedule:run`; the two commands register themselves.

## Go-live checklist (also `GET leave/setup-status`)
1. HR/legal enter the statutory values with source citations; a second person verifies each (**nothing is pre-filled**).
2. Admin creates and activates the company leave policy rule set(s) (e.g. `entitlement_days` for `leave_type = annual`).
3. HR fills leave profiles (date of birth, sex, weekly hours) wherever a statutory rule depends on them.
4. HR confirms each leave type's settings (day basis, paid, document, retroactive, blackout); defaults are conservative guesses.
5. Opening balances entered as adjustments; blackout / recommended periods entered; public holidays entered (attendance setup).
6. Run the worked examples approved by HR/legal through `GET leave/policy/preview` and compare.

## Known issues / decisions
- Defaults of `hr_leave_type_settings` (e.g. maternity/paternity on calendar days, all types paid except unpaid) are placeholders to confirm; payroll (phase 6) will read `is_paid`.
- Compensatory leave for public holidays inside annual leave is a manual adjustment for now (legal validation pending, Guide 5.4).
- `min_annual_block_days` is stored in the policy but only reported later (screens), not enforced.
- Team conflict counts colleagues of the same department by calendar range (weekends included) - a warning, never a blocker.
- Notifications use the approval engine's messages only; leave-specific reminders are not included.
- rw and sw texts are first-pass: native review needed.
- Feature tests (`tests/Feature/LeaveTest.php`) were written but **not executed** in the build environment (no Laravel runtime); the pure logic tests (`tests/Unit/LeaveMathTest.php`, 30 tests) were executed.
