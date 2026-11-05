# COPS Enhancement - Bundle 08: Phase 0 shared foundations

Implements **Phase 0** of *COPS Enhancement - Requirements & Developer Guide v1.2* on top of the existing repositories
(`company-operation-system` API, `company-operations-systems-frontend` web). Later phases (Projects, Billing, Portal, HR, Meetings,
Procurement) consume these services; none of them should introduce their own approval, scope or settings tables.

## What is in this bundle
| Guide section | Delivered |
|---|---|
| 3.1 Approval engine | `approval_flows/steps/requests/actions/delegations`, `ApprovalEngine` (role / department head / line manager / named user steps, delegation, escalation job, no self-approval, no two-step approver, immutable decisions), inbox UI, flow admin with two-person activation |
| 3.2 Department scope | `user_department_assignments`, `DepartmentScope` + `HasDepartmentScope::visibleTo()`, admin screen, department-head resolution |
| 3.3 Employee master | `hr_employees` (optional one-to-one login), lookup categories, encrypted national ID / bank account, audited reveal endpoint, employees screen |
| 3.4 Clients / external identity | `clients`, `client_contacts`, separate `client_identities` + `client` guard, `BelongsToClient` scope, portal login/me/contacts with automated cross-client 404 test |
| 3.5 Money, files | `MoneyService` (decimal-string maths, rounding once), `ClamAvScanner` (fail-closed) bound when `CLAMAV_ENABLED=true` |
| 3.7 Rules engine | `rule_sets/versions/conditions/outcomes/activations`, `legal_parameters`, `RuleResolver` (specific > priority > latest, per-org cache), validator (overlap, thresholds, statutory floors, coverage), lifecycle Draft > Impact check > Pending approval > Active > Superseded/Expired, legal sign-off, rules UI |
| 8.7 CI | GitHub Actions for API and web |
| 10 | Tests, OpenAPI fragment, regression checklist, 4-language strings |

## Install (API)
```bash
cp -r api/. /path/to/company-operation-system/
python3 scripts/apply-bundle-08.py /path/to/company-operation-system   # idempotent small edits (routes, auth guard, providers, seeder, notify texts)
cd /path/to/company-operation-system
composer install                 # ext-bcmath is now required (enabled by default in Laragon)
php artisan migrate
php artisan db:seed --class=CopsFoundationSeeder
php artisan test
```
Optional `.env`: `CLAMAV_ENABLED=true CLAMAV_HOST=clamav CLAMAV_PORT=3310` (see Docker note below).
Scheduler needs the usual `php artisan schedule:run` every minute (hourly escalation, daily rule expiry).

## Install (web)
```bash
cp -r web/. /path/to/company-operations-systems-frontend/
python3 scripts/apply-bundle-08-web.py /path/to/company-operations-systems-frontend
npm run typecheck && npm test && npm run build:check
```

## Decisions I made (please confirm)
- **Safe default.** With no active rule, every request takes `default_two_level` (department head, then top authority). If a step has nobody resolvable, the engine falls back to holders of the highest role level, never the requester. No thresholds or statutory values are seeded.
- **Who may activate a rule.** The seeded `rule_activation` flow routes to role `manager` holding `rules.approve`; the author cannot approve. An organization needs at least two privileged people.
- **Flow versions** are activated by someone other than the author (two-person rule) rather than through a full approval request, to avoid a flow that approves its own change.
- **Coverage gaps** block activation only for rule sets marked "require full coverage"; otherwise they warn, because an uncovered amount safely falls back to explicit approval (Guide 3.7.2 says gaps block; this is my reading of it).
- **Portal** is the identity boundary only. Login is by organization slug + email + password; invitation e-mail, MFA (default required), project views and uploads belong to Phase 3. Create a test login with `php artisan cops:client-identity <org-slug> <client-code> <email>`.
- Employee master holds the core person record. Employment history, positions/grades and payroll fields arrive in Phase 4.
- Permissions are new and granted per role by the seeder: staff `approval.view`; team leader adds `approval.act`, employees/clients view; manager adds oversight, `rules.approve`, client manage; admin everything. Adjust in the role matrix.

## Not verified here
- PHP code is lint-clean (PHP 8.3 locally) and the pure logic (rule matching, coverage, money rounding) was run against golden scenarios. **The Laravel feature tests and migrations were not executed**: this environment cannot install Composer packages. Run `php artisan test` against MySQL and send me any failure.
- Web: typecheck, existing Vitest suite and production build pass. `build:check` reports 465 KB initial JS against the 400 KB budget; the unmodified repository already reports 458 KB (the charts chunk loads eagerly). This bundle adds about 7 KB (mostly the four-language strings). Fixing the overage belongs in the next bundle.
- rw / sw strings are first-pass; have native speakers review them. Legal and tax content must come from your counsel and accountant (Guide Appendix C).

## Docker note (ClamAV)
```yaml
services:
  clamav: { image: clamav/clamav:stable, ports: ["3310:3310"], volumes: ["clamdb:/var/lib/clamav"] }
```

## Next bundle (Phase 1 - Projects core)
Projects, departments/roles, tasks, comments, attachments via Controlled Files, Kanban; it will register `task_review` approvals through `config/cops.php` handlers.
