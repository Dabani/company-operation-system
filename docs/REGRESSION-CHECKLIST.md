# Final regression checklist (all bundles)

Run on a **staging copy** with demo data before the first go-live and after major changes. For each area log: result (pass/fail), tester, date, defect id.
Automated first: API `php artisan test` (needs a MySQL test DB, `.env.testing.example`), web `npm run typecheck && npm test && npm run build:check`.

## After every release (10 minutes)
1. `/api/v1/health` is `ok`; `/api/v1/health/details` all `ok`.
2. Sign in as admin, staff, manager - each lands on its own dashboard; side menu shows only permitted items.
3. Open a case as staff, comment as admin; the staff member receives the notification (in-app, e-mail).
4. Upload a file, download it, try to delete it as a non-owner (must be refused / request flow).
5. Open Reports: the hub lists reports for the manager; each opens with charts; CSV export works for manager, no export button for team-leader.
6. Switch language to French, Kinyarwanda, Swahili: menu, dashboard and a report render without raw keys.
7. Install the PWA / reload with the network off: app shell opens, offline banner shows, a save attempt shows the "not sent" message.

## Full pass by area
| Area (bundle) | What to prove |
|---|---|
| Foundation (1) | Login/logout, password reset, role matrix edit takes effect on next request, new role can be created and given a dashboard, menu 3 levels accordion, language switch persists, brand font/colours, responsive at 360 px |
| Service desk (2) | Case numbers unique; comments with Tiptap; internal vs public comment visibility; assignee change notified; admin e-mail + SMS on new case; chat opens and the bot answers; failed delivery visible in delivery log |
| Controlled files (3) | Each of upload / view / download / archive / restore / replace / delete honours the permission; deletion only by owner or admin after a formal request; every action in file history; image placeholder shown when no avatar/logo |
| IT infrastructure (4) | Asset lifecycle, assignment and return, overdue equipment flagged, lost/stolen opens an incident, access register entries expire, warranty dates drive the report |
| Archive and records (5) | Record create -> inactive -> transfer -> archived; retention date computed; legal hold blocks disposal; disposal needs approval and keeps evidence; physical access request authorise -> return; restricted records invisible to unauthorised roles |
| Compliance (6) | Obligation completion moves next due date; audit finding workflow; backup verification record updates the "days since" figure; training session attendance and quarterly report; legal references show Law 058/2021 (not 12/2014) |
| Reports and hardening (7) | See below |

## Bundle 7 specifics
- [ ] Executive overview: figures equal what the module screens show (spot-check 3: open cases, overdue equipment, open findings).
- [ ] Service desk report: create two cases, resolve one, comment as agent -> counts, first-response and resolution time change as expected; SLA % matches a manual calculation for 3 cases.
- [ ] Period selector: 30/90/180/365 and custom range; a range over 400 days is cut to 400; from > to is swapped; weekly buckets up to 120 days, monthly beyond.
- [ ] Tenant isolation: with two organizations, a manager in A never sees B's numbers (compare totals).
- [ ] Permissions: team-leader (view only) has no export buttons and `GET /reports/x/export` returns 403; a user without `audit_log.view` gets 403 on the delivery report; staff have no Reports menu.
- [ ] Export: CSV opens correctly in Excel (UTF-8 accents, Kinyarwanda); a case title beginning with `=1+1` is exported as text; audit log shows `report.exported` with the filters.
- [ ] Print: browser print preview of a report shows header, KPIs, charts, table; no navigation chrome.
- [ ] Offline/update: with a new build deployed the "A new version is available" prompt appears and "Update now" reloads; stopping the API shows a clear error, not a blank page; an old browser tab after a deploy reloads itself once instead of failing.
- [ ] Health: stop the cron -> scheduler check turns false within 3 minutes; stop the queue worker -> pending-jobs age rises and queue check fails after 10 minutes.
- [ ] Prune: `php artisan ops:prune --dry-run` lists counts; audit log is untouched.

## Accessibility and usability (spot checks)
- [ ] Keyboard-only: tab through login, case form, report period selector; focus visible; modals trap focus and close with Esc.
- [ ] Contrast of brand colours on buttons/badges (WCAG AA); charts also have a table with the same data.
- [ ] 360 px phone, tablet and desktop widths: no horizontal page scroll (tables scroll inside their own container).
- [ ] Screen text in all four languages fits buttons and cards without clipping.

## Sign-off
| Role | Name | Date | Decision (go / no-go) |
|---|---|---|---|
| Product owner | | | |
| Technical lead | | | |
| Security / compliance | | | |
