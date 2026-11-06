# Regression checklist - COPS Phase 1 (append to docs/REGRESSION-CHECKLIST.md)

- [ ] A user who is not owner, member, or covered by view-all / department scope gets 404 (not 403) for a project, its tasks and files.
- [ ] Task keys are sequential per project (PRJ-1, PRJ-2) even with two tasks created at once.
- [ ] Assignee must be the owner or a project member; removing a member unassigns their tasks.
- [ ] Todo -> In progress -> In review raises a `task_review` approval; reviewers/managers/owner can decide; the requester cannot.
- [ ] Approved review => Done (completed_at set); rejected / returned / cancelled => back to In progress; every move is in the immutable status history.
- [ ] Only a project manager can close a task directly, reopen a done task, or revive a cancelled one.
- [ ] A task with unfinished blockers cannot enter review or Done; a dependency loop is refused.
- [ ] Viewers can read but not edit or comment; restricted files cannot be attached; attachments follow Controlled Files visibility.
- [ ] My tasks lists only open tasks in projects I can see.
