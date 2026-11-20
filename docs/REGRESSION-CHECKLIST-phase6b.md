# Regression checklist - COPS Phase 6b payroll runs (append to docs/REGRESSION-CHECKLIST.md)

- [ ] One regular run per period; a period over 62 days or inverted is refused.
- [ ] Calculate persists a payslip per employed person, every line with base, rate, rounding and scheme version id; recalculating before review replaces them; employees not employed in the period get none.
- [ ] A blocked payslip (no salary / no basic component / no hire date / currency mismatch) prevents review; excluding with a reason or fixing + recalculating unblocks.
- [ ] Review: not by the preparer or the calculator. Finance approval: not by preparer, calculator or reviewer. MD approval: after Finance, not by any earlier person, only when required. Lock and pay: not by the preparer or calculator.
- [ ] Steps out of order are refused (review before calculate, lock before approval, pay before lock, declare before pay).
- [ ] Return to draft needs a reason, clears review and approvals, and is impossible after lock; cancel impossible after review/lock.
- [ ] After lock: calculate, inputs, exclusions, adjustments to the same run are refused AND a direct model update/delete of a payslip, line, band, flag or input throws.
- [ ] Inputs only for active variable components; changing an input changes the payslip only after recalculation; variable lines and deductions are never prorated.
- [ ] Proration mode 0/1/2 frozen per run; mode 0 flags `partial_period_unprorated`; modes 1/2 flag `prorated` with worked/total days.
- [ ] Adjustment run: only from a locked regular run, only for employees with a calculated original, records `net_difference` and `original_payslip_id`.
- [ ] Self-service: only own payslips, only Paid/Declared, someone else's id = 404 (also for the PDF), review flags hidden; no access to the payroll desk.
- [ ] Payslip views and PDF downloads are audited without amounts; PDF renders in en / fr / rw / sw.
- [ ] Audit log has payroll.run_created/calculated/reviewed/approved_finance/approved_md/returned/locked/paid/declared/cancelled, adjustment_created, input_added/removed, employee_excluded/included, payslip_viewed/downloaded (+ _by_owner).
