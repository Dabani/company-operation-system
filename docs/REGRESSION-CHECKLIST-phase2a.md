# Regression checklist - COPS Phase 2a (append to docs/REGRESSION-CHECKLIST.md)

Timer
- [ ] Start, pause, close the browser, reopen on another device: the timer shows the same server elapsed time.
- [ ] Starting a second timer asks to stop the first (setting `time.single_timer`); accepting stops it and starts the new one.
- [ ] A timer past `time.max_hours` is stopped by `cops:timers-autostop`, flagged "auto-stopped", the owner is notified, and the week cannot be submitted until the entry is corrected with a reason.
- [ ] Viewers (project role) cannot track time; closed projects and cancelled tasks refuse time.

Entries
- [ ] Manual entries need a reason (5+ characters), are flagged manual, cannot be future, longer than 24 h, older than `time.manual_max_days_back`, or overlap another entry/timer (touching is fine); disabled when `time.manual_allowed` is off.
- [ ] Only draft stopped entries can be edited/deleted, only by their owner.
- [ ] Submitting a week freezes its entries; adding time to a submitted/approved week is refused.
- [ ] Approved/invoiced entries cannot be changed or deleted (model guard); a manager's adjustment is a new signed entry with a reason, and the net total can never go below zero.

Timesheets and rates
- [ ] Submit raises a `timesheet` approval (line manager; top authority when none recorded); requester cannot approve.
- [ ] Approve locks the entries; return/reject reopens them as draft so the owner can fix and resubmit.
- [ ] Team view shows only people within the viewer's department scope.
- [ ] Rate lines are effective-dated, never edited (end-date instead); task > person > project role > default; no match returns "no rate", never a guess; rates are visible only with `rates.view`.
