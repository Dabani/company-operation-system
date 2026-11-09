# COPS Phase 4a - HR core, part 1 (API)

Scope of this bundle (Guide 5.2, 5.7, 8): position and grade framework, effective-dated employment history with approvals,
career-progression criteria, performance reviews, contract/probation reminders, HR roles and self-service.
Not in this bundle: recruitment (bundle 15) and attendance (bundle 16). Phase 4 is complete when the
recruitment-to-active-employee journey works.

## Behaviour
- Every employee starts with a **hire** row (created automatically). History is **append-only**: a change adds a dated row, past rows are never edited or deleted (enforced in the model).
- The state in force on a date = latest *effective* row on or before it (`GET employees/{id}/as-of?date=`). Payroll will use this to reproduce past periods.
- `hr_employees` is only a **snapshot** of the latest effective row whose date has arrived. Future-dated changes are applied by `cops:hr-apply-effective` (daily 00:10).
- Change types: promotion, confirmation, salary_change, category_change (**need approval**, subject `employment_change`, default flow = HR manager step, falls back to the top authority; the proposer can never approve) and transfer, manager_change (effective once recorded).
- One pending change per employee. A new row must be dated after the latest effective row.
- `PATCH employees/{id}` no longer changes category, department, manager or contract dates: it answers 422 and points to an employment change (an unchanged value is accepted, so existing forms keep working).
- Salary and pay bands need `hr.salary.view`; every read that includes them is audited (`employment.salary_viewed`). Salary is stored encrypted.
- Career: `GET employees/{id}/career` returns months in the current grade, the latest submitted review rating and, per progression criterion, whether the employee qualifies and what is missing.
- Reviews: HR creates a cycle and assigns reviews (reviewer defaults to the line manager); the reviewer edits and submits; the employee sees it only after submission and acknowledges it; HR reads are audited (`review.viewed`).
- Reminders (`cops:hr-reminders`, daily 07:00): probation end and contract end at 30, 14, 7 and 1 days. Each (employee, kind, date, step) is sent once; missed runs catch up. Recipients: holders of `hr.history.manage` and the line manager's login. In-app only (see known issues).

## Permissions (separate from general management)
hr.structure.manage, hr.history.view, hr.history.manage, hr.salary.view, hr.approve, hr.reviews.manage, hr.reviews.write, hr.self.view.
New role **hr_manager** (created once, never overwritten). admin receives all of them. manager: view history, write reviews. team_leader: write reviews. all staff: own record.

## Deployment
1. `python scripts/apply-bundle-14.py <path to cops-api>` (registers HrServiceProvider in bootstrap/providers.php)
2. `php artisan migrate`
3. `php artisan db:seed --class=HrSeeder` (idempotent; also writes a hire row for employees that already exist)
4. `php artisan test`
The scheduler already runs `schedule:run`; the two new commands are registered by the provider.

## Known issues / decisions
- rw and sw texts are first-pass: native review needed.
- Reminders are in-app only: e-mail needs keys added to `lang/*/notify.php` and a switch to `Notifier::send`.
- Which change types need approval is a constant (`EmploymentService::APPROVAL_REQUIRED`, conservative). Approval flows themselves are configurable in the existing flow/rule screens.
- No salary, grade, pay band or legal value is seeded.
- Frontend screens for this bundle are not included yet (need the web repo structure).

## Regression checklist additions
- Create employee -> history shows one hire row.
- Promotion stays pending until a different person approves; employee record unchanged until then.
- Future-dated transfer is applied on its day only.
- As-of date returns the old department after a transfer.
- Salary invisible without hr.salary.view; visible and audited with it.
- Draft review invisible to the employee; submitted one visible; another employee gets 404.
- Reminder run twice sends once.
