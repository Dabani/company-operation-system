# COPS Phase 4b - Recruitment and onboarding (API)

Journey (Guide 5.1): workforce requisition -> approval -> vacancy -> applicants -> screening -> interviews and scorecards -> selection (approval) -> offer (PDF) -> acceptance -> **one-click conversion to an employee** with an onboarding checklist.
Exit test of Phase 4: a hired applicant becomes an active employee with the right category, position, dates and salary, and a checklist.

## Rules implemented
- Requisition: department, title, headcount, justification; submitted for approval at once (default flow: HR manager; the requester never approves; configurable in the approval flow screens). A vacancy can only be opened from an **approved** requisition, one per requisition.
- Applicants: consent to keep their data is mandatory and timestamped; one application per e-mail per vacancy; stage history is kept (applied, screened, interview, assessment, offer, hired, rejected). Stages are moved by hand only up to assessment; **offer** comes only from an approved selection, **hired** only from conversion, **rejected** from the reject action with a reason.
- Interviews: panel of employees; each panelist submits **one** scorecard (score 1-5, recommendation, strengths, concerns); a panelist sees the others' scorecards only after submitting their own; the interview completes when the whole panel has scored.
- Selection: needs at least one submitted scorecard and a free place (headcount minus selected/hired); approval default: head of the requisition's department (falls back to the top authority). The place is re-checked at approval time.
- Offers: only for a selected applicant, one active offer at a time; salary needs `hr.salary.view`; a salary outside the chosen grade's pay band is refused unless an exception reason is given (kept and flagged); validity defaults to a setting; answering after the validity date is refused; a declined or withdrawn offer closes the application. PDF letter in en, fr, rw, sw (audited download).
- Conversion: accepted offer -> employee (category, department, position, grade, start date, probation end from the offer's months, contract end) with the offer's **salary and contract type on the hire row** of the employment history; applicant closed as hired; onboarding items generated from the editable template (due = start date + offset); requisition filled and vacancy closed when all places are taken.
- Personal data: retention is a setting (`applicant_retention_months`, default 12, range 1-120) counted from the application, restarted at the decision; a daily job flags records past their date **once** and tells HR; nothing is deleted automatically. HR can extend (with a reason) or **anonymise** a closed application (identity, contact, notes, references and free-text comments erased; stage history and scores stay for statistics). Hired applicants' data lives on the employee record. Reading an application and downloading an offer are audited.

## Permissions
hr.requisition.create (manager, hr_manager, admin), hr.requisition.approve (manager, hr_manager, admin), hr.recruitment.manage (hr_manager, admin), hr.interview.score (manager, team_leader, hr_manager, admin), hr.selection.approve (manager, admin, hr_manager).
Settings: `GET/PUT hr/settings` (applicant_retention_months, offer_validity_days). Onboarding template: `hr/onboarding-template`.

## Deployment
1. `python scripts/apply-bundle-15.py <path to cops-api>`
2. `php artisan migrate`
3. `php artisan db:seed --class=HrRecruitmentSeeder` (idempotent)
4. `php artisan test`

## Known limits / decisions
- CV and document upload for applicants is not included (needs the controlled-file pipeline); notes and references are text.
- Scorecards use one overall score; weighted criteria per vacancy are a later refinement.
- A declined/withdrawn offer closes the application (rejected, reason recorded); reopening a candidate means a new application.
- Retention notifications are in-app only (e-mail needs `lang/*/notify.php` keys).
- No menu items are seeded yet: the screens come with the web bundle, which adds them.
- rw and sw texts are first-pass: native review needed. Onboarding default items are English data, editable by HR.
- No salary, headcount, grade or legal value is seeded.

## Regression checklist additions
- Vacancy cannot be opened before the requisition is approved.
- Applicant without consent / duplicate e-mail refused.
- Selection refused without a scorecard and when all places are taken.
- Offer outside the pay band refused without a reason.
- Panelist cannot see other scorecards before scoring; non-panelist gets 404.
- Declined offer rejects the applicant; retention follows the setting.
- Retention job flags once; anonymise erases identity but keeps history.
- Conversion creates the employee with salary on the hire row and the onboarding checklist; second conversion refused.
