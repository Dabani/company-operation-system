# Regression checklist - COPS Phase 2b (append to docs/REGRESSION-CHECKLIST.md)

Draft generation
- [ ] Time and materials: only APPROVED, BILLABLE, not-yet-invoiced entries are picked; each is priced from the project's rate card (task > person > role > default, effective-dated).
- [ ] Any entry with no applicable rate blocks the draft with a count; rates are never guessed.
- [ ] Rounding happens once: billed minutes = raw time rounded to `billing.rounding_minutes` / `billing.rounding_mode`; amount = minutes / 60 x rate rounded to 2 decimals; tax rounded once on the subtotal.
- [ ] Fixed price bills the project amount once; milestone billing bills each completed milestone once; one open draft per project.
- [ ] A time entry can appear on only one invoice (unique source); cancelling a draft releases its entries and milestones.

Issue and approval
- [ ] An invoice cannot be issued without a verified, currently valid tax rate (or an explicit tax-exempt flag).
- [ ] With no rule, every invoice needs approval by the managing director role; the requester cannot approve. A rule in set `invoice-approval` with outcome `approval_required = false` lets small invoices issue directly.
- [ ] Approval issues the invoice: number INV-YYYY-NNNN (gap-free, assigned at issue), client details frozen, due date from terms, time entries become Invoiced.
- [ ] Issued invoices cannot be edited or deleted (model guard); lines are locked once the invoice leaves draft.

Money after issue
- [ ] Partial payments move the status to Partially paid; over-payment is refused; payments are immutable and corrected only by a reversal row with a reason.
- [ ] Past the due date unpaid invoices become Overdue (`cops:invoices-overdue`, daily) and the creator is notified.
- [ ] Credit notes: bounded by the still-creditable subtotal, tax proportional, approved by someone other than the requester, number CN-YYYY-NNNN; fully credited and unpaid => Cancelled.

Project lifecycle and visibility
- [ ] Deliver is refused with open tasks or pending task reviews; delivering creates a draft invoice when billing is configured (a failure reports the reason but does not undo delivery).
- [ ] Close is refused while invoices are draft / pending approval, unless a user with `billing.close_exception` gives a reason (audited). Delivered and closed projects accept no new time.
- [ ] Invoices outside the user's project/department scope return 404 unless the user has `billing.view_all`; staff without billing access get 403.
- [ ] PDF shows the stored snapshot (not live rates), has a DRAFT watermark before issue, and includes the time annex.
