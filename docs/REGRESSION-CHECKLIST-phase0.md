# Regression checklist - COPS Phase 0 (append to docs/REGRESSION-CHECKLIST.md)

Approvals
- [ ] A request with no applicable rule uses `default_two_level` and shows the "safe default" note.
- [ ] Requester cannot approve own request; same person cannot approve two steps of one request.
- [ ] Reject / return without a comment is refused; decisions cannot be edited or deleted.
- [ ] Delegate can act within the delegation dates; the action records "on behalf of".
- [ ] Escalation reminder is sent once per step (`php artisan cops:approvals-escalate`).

Rules
- [ ] Draft -> impact check -> submit -> independent approval -> active; author cannot approve.
- [ ] Legal-profile version cannot activate without verification and a sign-off by someone other than the author.
- [ ] Outcome below a stored statutory floor / above a ceiling blocks submission.
- [ ] Overlapping rule (same conditions, priority, dates, other set) blocks; amount gap blocks only on "full coverage" sets.
- [ ] Activating a newer version supersedes the older one from the day before; old transactions keep their `rule_version_id`.

Scope and identity
- [ ] Team leader assigned to department A cannot list, open (404) or export employees of department B.
- [ ] Granting organization scope requires the `scope.organization` permission.
- [ ] National ID / bank account never appear in lists; `/employees/{id}/sensitive` is audited.
- [ ] Portal login works only via `/api/v1/portal/auth/login`; another client's contact id returns 404; portal session cannot call staff routes.

Files
- [ ] With `CLAMAV_ENABLED=true` the EICAR test file is rejected; with the daemon stopped uploads are refused (fail-closed).
