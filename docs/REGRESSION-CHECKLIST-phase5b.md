# Regression checklist - COPS Phase 5b trips (append to docs/REGRESSION-CHECKLIST.md)

- [ ] No rate exists after seeding; per diem generation reports `missing_rate` and creates nothing.
- [ ] Grade rate beats general rate; the latest valid rate wins; a rate in another currency is ignored; an ended rate no longer applies to later trips but still explains older ones.
- [ ] Per diem lines are generated, never typed; regenerating refreshes the draft line and never touches a submitted/reviewed one.
- [ ] Trip request: dates, budget > 0, advance only for travellers, advances <= budget, overlapping trips for a traveller refused, travellers outside my scope refused.
- [ ] The requester cannot approve; no authorization number before approval; outsiders get 404.
- [ ] Advance paid only on an approved trip; due date = later of trip end / payment date + retirement days; a trip with a paid advance cannot be cancelled.
- [ ] Late advances appear in `unretired-advances`; the reminder is sent at most once a week per advance.
- [ ] Expenses: outside the trip dates refused; per diem category refused; receipts required for the report; no expenses after the report.
- [ ] Settlement: refused while any expense of that traveller is unreviewed; resolution must match the direction; waiving needs a reason; payroll deduction needs consent date and lawful basis and writes a consent row; advance becomes retired; trip becomes settled only when all travellers are.
- [ ] Receipts do not appear in the global Files library; another employee gets 404 on them.
- [ ] Audit log has trip.requested/approved/rejected/cancelled, trip.advance_paid, trip.expense_added/accepted/rejected, trip.report_submitted, trip.settled, trip.rate_created/ended, trip.per_diem_generated.
