# COPS Phase 6b - Payroll runs and payslips (API, bundle 21)

Scope (Guide 5.6.1): payroll runs with the lifecycle Draft -> Calculated -> Reviewed (HR) -> Approved (Finance, then the Managing Director where required) -> Locked -> Paid -> Declared,
persisted payslips with every input, proration, variable inputs, deliberate exclusions, adjustment runs, payslip PDF and employee self-service.
**Not in this bundle** (next): bank-transfer file, PAYE / RSSB declaration files, accounting journal, web screens, queued calculation for very large payrolls.
Requires bundle 20. Nothing here may be used for real pay until the accountant's values are entered, verified, and the golden-file / parallel-run checks pass.

## Lifecycle and separation of duties (enforced by the service, not by role names)
| Step | Permission | Rule |
|---|---|---|
| create, inputs, exclusions, calculate, cancel | hr.payroll.prepare | one regular run per period; inputs and exclusions only before review |
| review | hr.payroll.review | not the preparer or the person who calculated; no blocked payslip may remain |
| approve-finance | hr.payroll.approve | not the preparer, calculator or reviewer |
| approve-md | hr.payroll.approve_md | after Finance; not the preparer, calculator, reviewer or finance approver; only when the run requires it |
| lock | hr.payroll.lock | run must be Approved; not the preparer / calculator |
| pay | hr.payroll.pay | run must be Locked; not the preparer / calculator; records date and reference |
| declare | hr.payroll.declare | run must be Paid; records date and reference |
| return to draft | review / approve / approve_md | reason required; clears review and approvals; **never after lock** |

All stage permissions go to **admin only** by default. Create separate roles for each stage; the code refuses one person doing consecutive stages even if a role has several permissions.
"Approved" means every required approval is in. MD approval is required unless the HR setting `payroll_md_approval_required` is 0 (default 1); the value is frozen into the run when it is created.

## Immutability
Locked, Paid and Declared runs cannot change: the services refuse, **and every payslip, line, band, flag, input, member and exclusion model refuses** to be created, updated or deleted for such a run (`PayRunLock`).
A correction is an **adjustment run** for chosen employees of a locked run: it recalculates them with current verified configuration plus its own inputs and records `net_difference` against the original payslip.

## Calculation
For every employee employed in the period (hire date <= period end, no end date before the period start): proration -> configuration (bundle 20 resolver) -> engine -> payslip with
lines (base, rate, rounding, decimals, statutory type, **scheme version id**), progressive bands, totals and flags. Blockers (missing salary, no basic component, no hire date, currency mismatch) make the payslip `blocked`
(the run cannot be reviewed until fixed or the employee is excluded with a reason); warnings (unverified or missing configuration, partial period) are kept as flags. Payslips snapshot the employee name, number and bank account (encrypted).
Calculation is synchronous: for very large payrolls it should move to the queue (noted below).

### Proration (the accountant's decision)
HR setting `payroll_proration_mode`: **0 none (default)**, 1 calendar days, 2 working days (Monday-Friday minus public holidays). Only basic and recurring earnings are prorated; variable lines and deductions never are.
`payroll_earnings_decimals` (default 2) is the rounding of a prorated earning. With mode 0 a part-period employee is paid in full and flagged `partial_period_unprorated`. Both settings are frozen into each run.
Unpaid leave, absences and other adjustments are entered by the preparer as **variable input lines** (the leave module's `is_paid` flag is not applied automatically).

## Payslips
`GET payroll/runs/{id}/payslips`, `GET payroll/payslips/{id}` (audited), `GET payroll/payslips/{id}/pdf` (audited). The PDF and the screens read the STORED payslip, never recompute it.
Self-service (`hr.payslip.own`, granted to every employee role): `GET payroll/me/payslips[/{id}[/pdf]]` - **only my own** (the employee is found from the signed-in user, never from a parameter; someone else's id gives 404) and **only once the run is Paid or Declared**. Review flags are hidden from the employee.

## Deployment
1. `python scripts/apply-bundle-21.py <path to cops-api>` (backups in `.bundle-21-backup/`; idempotent; `--dry-run`, `--revert`). **Requires bundle 20 revision 2** (re-apply the updated bundle 20 zip first: its resolver gained proration support and scheme decimals are limited to 0-2). This bundle replaces no file of another bundle; the script stops with a clear message if revision 2 is missing.
2. `php artisan migrate` then `php artisan db:seed --class=HrPayrollRunSeeder`
3. `php artisan test`

## Decisions and known issues (please confirm)
1. **Proration rule**, **earnings rounding**, and whether the **MD approval** is required: the accountant / management decide; defaults are the cautious ones above.
2. **Who holds each stage**: create the reviewer, finance approver, MD and payer roles; the code only guarantees they are different people.
3. **Unpaid leave and trip deductions** are not automatic. A trip payroll deduction may only be taken with the consent recorded in bundle 18; a later bundle will offer those as suggested input lines.
4. Employee **salary is treated as monthly**; hourly / daily / casual categories need the accountant's rule (see bundle 20).
5. **Calculation is synchronous** (no queue yet). For hundreds of employees move it to a queued job with progress.
6. **Payslip visibility** is Paid/Declared. Confirm that this is the wanted moment.
7. **Exports** (bank file format, PAYE / RSSB declaration formats and deadlines, journal accounts) are the next bundle and must be agreed with the accountant and the bank.
8. **Staff two-factor authentication** remains a go-live gate before real payroll.
9. The Dompdf payslip layout is a first version and was **not rendered here** (no PHP runtime with Composer): check the PDF visually in all languages.
10. rw and sw texts are first-pass: native review needed.
11. Feature tests (`tests/Feature/PayrollRunTest.php`) were written but **not executed**. Executed here: `PayrollRunLogicTest` (8 tests: proration, lifecycle, approval steps, segregation) plus the bundle 20 calculation and golden tests.
