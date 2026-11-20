# Regression checklist - COPS Phase 6a payroll configuration (append to docs/REGRESSION-CHECKLIST.md)

- [ ] After seeding there is no component, scheme, rate, band, assignment or item; `setup-status.ready` is false.
- [ ] Payroll endpoints are 403 for staff, manager and hr_manager; the dry-run needs `hr.payroll.prepare`; verification needs `hr.payroll.verify`.
- [ ] A component cannot be created without explicit taxable and pensionable flags; the flags, kind and source cannot be changed afterwards; only one active basic-salary component.
- [ ] Band validation refuses a gap, an overlap, a first band not at 0, a closed last band, an open band that is not last, a rate outside 0-100.
- [ ] A flat scheme needs a rate; a bands scheme refuses an employee rate; versions of a scheme cannot overlap.
- [ ] The preparer cannot verify their own entry; a verified version / assignment / item cannot be deleted or edited, only ended.
- [ ] Unverified version, assignment or item: reported as a warning and NOT applied in the dry-run.
- [ ] A scheme with no (verified) assignment for the category is not applied and is reported; a verified employee exception beats the category; an exception needs a reason.
- [ ] Missing salary or missing basic component = blocker, no result.
- [ ] Golden files reproduce exactly; synthetic fixtures are never marked signed off; signed ones need `signed_off_by`.
- [ ] The dry-run leaves every pay_* table unchanged; the audit log has `payroll.dry_run` without amounts; recurring items reads are audited.
- [ ] Audit log has payroll.component_created/updated, scheme_created/updated, version_created/verified/ended/deleted, assignment_created/verified/ended/deleted, item_created/verified/ended/deleted/viewed.
