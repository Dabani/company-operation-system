# Regression checklist - COPS Phase 3 client portal (append to docs/REGRESSION-CHECKLIST.md)

Access and identity
- [ ] A portal login is a separate identity: it cannot call any staff route (401) and staff cannot use portal routes.
- [ ] Invitation: single-use link, expires after `ops.invitation_days`, one live link per person, resend throttled to once a minute; wrong, used and expired links are refused with a clear message.
- [ ] Forgot password gives the same answer for known and unknown e-mails; the reset link works once.
- [ ] Wrong password, unknown organization, inactive, invited-but-not-activated and deactivated users all get the same "invalid credentials" answer; login and MFA are rate limited.
- [ ] With `portal.mfa_required` on (default): no portal data before two-factor is set up and verified; codes cannot be re-used within the same 30 s step; each recovery code works once; staff can reset a client's two-factor (audited).

What a client can see (leak tests)
- [ ] Only projects of its own client that staff marked "show in portal"; another client's id and an unshared project both answer 404.
- [ ] Progress is a percentage of non-cancelled tasks; the JSON contains no task titles, internal comments, time entries, hours, rates, costs or staff details.
- [ ] Team comments are internal unless flagged visible; client comments are plain text (markup stripped) and notify the project managers.
- [ ] Invoices and uploads are hidden/forbidden until enabled for the client or the project; drafts and approvals-in-progress never show; the client invoice PDF has no time annex.
- [ ] Files: only files staff share (or the client uploaded to that project) can be downloaded; another client's file id answers 404.

Uploads
- [ ] Type allow-list and size limit from settings (never above the global limits); content type verified, virus scan applied (enable ClamAV before go-live), original stored as a Controlled File linked to the project, upload audited with the client identity.

Operations
- [ ] Session cookies: SESSION_SECURE_COOKIE=true and SameSite=lax in production; portal and API on the same site.
- [ ] Audit log has portal.login, portal.mfa_*, portal.file_*, portal.invoice_downloaded, portal.invited, client.portal_set, project.portal_set.
