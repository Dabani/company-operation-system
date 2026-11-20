# COPS Phase 6a - Payroll configuration, calculation engine and dry-run (API, bundle 20, revision 2)

Revision 2 (delivered with bundle 21): the config resolver accepts a proration factor and tags earnings by source (used by payroll runs; the dry-run behaves as before), and scheme `decimals` is limited to 0-2 because payslip amounts are stored with 2 decimals. Re-apply this bundle before bundle 21; it is idempotent.

Scope (Guide 5.6): pay components, statutory schemes with effective-dated versions and progressive bands, category assignments with approved employee exceptions,
recurring employee items, a pure calculation engine, golden-file tests, and a dry-run that persists nothing.
**Not in this bundle** (bundles 21-22): payroll runs and their lifecycle (Draft -> Calculated -> Reviewed -> Approved -> Locked -> Paid -> Declared), payslips and PDF,
employee self-service, bank / PAYE / RSSB exports, accounting journal, adjustment runs, proration, web screens.
This guide is a product document, not tax advice: nothing here may be activated until the accountant has entered and a second person has verified the real values.

## Principles enforced by the code
- **Nothing is pre-filled.** No component, rate, threshold, band, assignment or exemption is seeded. `HrPayrollSeeder` creates lookups, permissions and a menu item only.
- **Every value that changes money is entered unverified and counts only after a DIFFERENT person verifies it** (scheme versions, assignments, recurring items). The preparer cannot verify their own entry. Unverified values are shown and reported, never applied.
- **A verified value is final**: it is ended (`valid_to`) and a new one entered; it cannot be edited or deleted. Mistakes in unverified rows can be deleted.
- **Effective-dated, with provenance**: every version stores its source (official notice / the accountant's signed schedule), preparer, verifier and dates.
- **Nothing is assumed**: no assignment of a scheme to an employee's category = the scheme does NOT apply, and the result says so (`no_assignment`). A taxable / pensionable flag has no default. A missing salary or basic-salary component is a blocker, not a zero.
- **Structural settings are fixed once created** (component tax flags, scheme order / base / rounding / `reduces_taxable_base`): changing them later would rewrite how past payslips were built. Create a new component or scheme instead.
- **Exact arithmetic**: decimal strings and bcmath, never floats. Rounding mode (nearest / down / up), decimals (0 for the franc) and "round once or per band" are scheme settings, because "to the franc" depends on them.
- **Reproducible**: every calculated line carries its base, rate, rounding and the id of the scheme version used.

## How a calculation works (`PayrollEngine`, pure)
1. Earnings are summed: gross, taxable earnings (components flagged taxable), pensionable earnings (flagged pensionable).
2. Schemes run in `calc_order`. A scheme's base is one of: gross, taxable, pensionable, or **taxable after deductible contributions** (taxable earnings minus the employee contributions of earlier schemes flagged `reduces_taxable_base`). An optional cap limits the base.
3. A flat scheme = base x rate (employee and employer separately). A bands scheme taxes only the part of the base inside each band (bands must be contiguous: no gap, no overlap, last band open).
4. After-tax deductions reduce net pay only. Net = gross - employee contributions - deductions; employer cost = gross + employer contributions. A negative net is flagged.

## Inputs to a period (`PayrollConfigResolver`)
Basic = the monthly `salary_amount` of the employment record in force on the **last day of the period** (the single active component whose source is `basic_salary`); recurring items (verified, overlapping the period); optional variable lines in the dry-run; schemes with a verified version valid on the last day and a verified assignment (employee exception beats category).

## Dry-run
`POST payroll/dry-run {employee_id, period_from, period_to, lines?[{component_id, amount}]}` returns blockers, warnings, the full result (lines + totals) and meta (version ids applied, schemes excluded). It writes to no payroll table; the read is audited (employee and period, never amounts).

## Permissions (admin only by default)
`hr.payroll.view`, `hr.payroll.configure` (prepare), `hr.payroll.verify` (second person), `hr.payroll.prepare` (dry-run; later: runs). Create separate roles in the Roles screen so the preparer, the verifier and (later) the approvers are different people. Salary data stays under `hr.salary.view` rules elsewhere.

## Golden files (Guide section 10, point 9)
`tests/fixtures/payroll/*.json` are worked examples reproduced exactly by `tests/Unit/PayrollGoldenTest.php`. The two `synthetic-*` files use invented numbers. **Add the accountant's signed examples as new files** (README in that folder): no code change needed. Production activation requires them.

## Deployment
1. `python scripts/apply-bundle-20.py <path to cops-api>` (backups in `.bundle-20-backup/`; idempotent; `--dry-run`, `--revert`)
2. `php artisan migrate` then `php artisan db:seed --class=HrPayrollSeeder`
3. `php artisan test`

## Decisions and known issues (please confirm)
1. **Who supplies and signs the rates** (Guide 11.1 #2): nothing can be entered meaningfully until the accountant provides the current RRA/RSSB notices and the company's signed schedule.
2. **Salary unit**: the employment record's `salary_amount` is treated as the **monthly** gross basic. Hourly / daily / casual categories, intern stipends, volunteer allowances and probation salary need the accountant's decision (no exemption is assumed).
3. **Proration** (hire / termination mid-period, unpaid leave, attendance) is NOT applied: the dry-run warns `partial_period`. Bundle 21 adds a configurable proration rule and explicit adjustment lines.
4. **Rounding "to the franc"**: confirm the accountant's rule (round the total once or each band; nearest / down / up) when entering each scheme.
5. **Declaration and payment deadlines, bank file format, journal accounts** are bundle 21 and must be confirmed with the accountant and the bank.
6. **Two-factor authentication** for staff accounts must exist before HR/payroll go-live (Guide 8.6). The repository has TOTP only for client-portal identities: this is a go-live gate to schedule.
7. Trip deductions: a payroll deduction from a trip settlement may only be taken with the consent row recorded in bundle 18; the runs bundle will read it.
8. rw and sw texts are first-pass: native review needed.
9. Feature tests (`tests/Feature/PayrollConfigTest.php`) were written but **not executed** (no Laravel runtime here). Executed: `PayrollMathTest` (14 tests, calculation, rounding, bands, validation) and `PayrollGoldenTest` (3).
