# Regression checklist - COPS Phase 5a leave (append to docs/REGRESSION-CHECKLIST.md)

Statutory limits and policy
- [ ] No legal value or day count exists after seeding; `GET leave/legal-rules` is empty on a new install.
- [ ] An unverified legal rule is flagged and not applied; the person who entered a rule cannot verify it (422); verification needs `rules.signoff`.
- [ ] Company value below a verified floor: effective value = floor; above a ceiling: = ceiling. The highest applicable floor wins (age / sex eligibility). A rule needing a missing profile fact is reported, not guessed.
- [ ] `GET leave/policy/violations` lists a draft or active leave policy version below a verified floor.
- [ ] A policy change never alters past requests (`rule_version_id` kept).

Requests and balances
- [ ] Preview and submit give the same blockers; nothing is created when a blocker exists.
- [ ] Weekends and public holidays are not counted (working-day types); calendar-day types count them; half days count 0.5.
- [ ] Pending days are reserved; overlapping requests are refused; the requester cannot approve; a person outside the scope gets 404.
- [ ] Approval writes day rows and usage entries; cancellation writes reversals; ledger rows are never edited or deleted.
- [ ] A month validated in attendance: new leave in it is refused; leave approved after the month was locked is cancelled and audited.
- [ ] Attendance shows `leave` (not `absent`) for approved full days; a half day is not "on leave" for the whole day.

Accrual and year end
- [ ] Accrual run twice posts once; 12 months of a 19-day entitlement sum to exactly 19.00; a mid-year hire accrues from the hire month.
- [ ] Year end with no carry-over policy forfeits nothing; with a limit, only the excess; expiry forfeits only unused carried-over days.

Security and audit
- [ ] Leave profile unreadable without `hr.leave.profile`; every read audited; date of birth encrypted in the database and absent from audit values.
- [ ] Balance adjustments need `hr.leave.manage` and a reason; audited.
- [ ] Leave documents do not appear in the global Files library; another employee gets 404 on them.
- [ ] Audit log has leave.requested/approved/rejected/cancelled, leave.cancelled_after_approval, leave.balance_adjusted, leave.legal_rule_created/verified/ended, leave.profile_viewed/updated, leave.type_updated.
