<div align="center">

# COPS API

**Company Operations Platform - backend**

Service desk, controlled files, IT infrastructure, archive and records, and compliance - in one role-based, multilingual platform.

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![PRs welcome](https://img.shields.io/badge/PRs-welcome-3773b8)

[Frontend repository](https://github.com/Dabani/company-operations-systems-frontend) · [Report a bug](https://github.com/Dabani/company-operation-system/issues) · [Request a feature](https://github.com/Dabani/company-operation-system/issues)

</div>

---

## What is COPS?

Most organizations run their support requests, equipment lists, paper archives and compliance duties in separate spreadsheets, inboxes and shared folders.
**COPS** brings them together in one auditable system that any organization can adopt - it is not tied to a single company.

This repository is the **REST API** (Laravel). The user interface lives in [cops-web](https://github.com/Dabani/company-operations-systems-frontend) (React PWA).

### What it does

| Module | Highlights |
|---|---|
| **Service desk** | Every request is a *case* with a unique reference, status workflow, comments (internal and public), assignment, due dates and history. Live chat with a support bot. E-mail and SMS notifications (Brevo or Africa's Talking). |
| **Controlled files** | Upload, view, download, replace, archive, restore and delete under permission rules. Deletion is allowed only to the owner or an admin after a formal request. Full file history. |
| **IT infrastructure** | Asset register and lifecycle, assignments, equipment movements, access register, security incidents. |
| **Archive and records** | Records register with retention rules and legal references, physical access requests, inventory counts, disposal batches with printable destruction certificates. |
| **Compliance** | Recurring duties calendar, backup verification, training sessions and attendance, audits and findings, management reports. |
| **Reports** | Seven cross-module reports with charts, CSV/Excel export, and a documented calculation method. View and export are separate permissions. |
| **Administration** | Dynamic role and permission matrix (add your own roles), users with e-mail invitation and activation, departments, locations, lookups, languages, organization settings, audit log, notification delivery log, health endpoint. |

### Design principles

- **Server-side authority.** Every route checks permissions on the server; hiding a button in the UI is never the control.
- **Multi-tenant by design.** Business data is scoped to an organization through a global scope.
- **No enums, no JSON columns.** Every list of choices (statuses, priorities, types...) is a row in a lookup table, so administrators can extend them without a deployment.
- **Everything important is audited**, including report views and exports.
- **Multilingual.** English (default), French, Kinyarwanda and Swahili; more languages can be added from data plus a translation file.

## Tech stack

Laravel 13 · PHP 8.4 · MySQL 8.4 · Sanctum (cookie sessions) · Reverb (WebSockets) · Laravel Excel · Dompdf · queue and scheduler.

## Getting started

### Requirements

PHP 8.4 (with `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `pdo_mysql`), Composer 2, MySQL 8.4, and a mail account (SMTP, for example Brevo) for invitations and notifications.
On Windows, [Laragon](https://laragon.org) provides all of this.

### Install

```bash
git clone https://github.com/Dabani/company-operation-system.git
cd cops-api
composer install
cp .env.example .env
php artisan key:generate
```

Create an empty MySQL database, then edit `.env` (the real-time keys `REVERB_*` and the mail/SMS settings are described in the `.env.*` files shipped with the project):

```ini
DB_DATABASE=cops
DB_USERNAME=root
DB_PASSWORD=

APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173

MAIL_MAILER=smtp            # or "log" to read e-mails in storage/logs while developing
SMS_DRIVER=log              # log | brevo | africastalking
OPS_ADMIN_EMAIL=you@example.com
OPS_ADMIN_PASSWORD=choose-a-strong-password
```

```bash
php artisan migrate
php artisan db:seed          # lookups, roles, permissions, menu, first administrator
php artisan serve            # http://localhost:8000
```

### Background services (separate terminals)

```bash
php artisan queue:work       # e-mail / SMS delivery
php artisan schedule:work    # deadline alerts, clean-up, health heartbeat
php artisan reverb:start     # live chat and notifications (optional in development)
```

Then start the frontend ([cops-web](https://github.com/Dabani/company-operations-systems-frontend)) and sign in with the administrator you seeded.

### Your first steps

1. Sign in as the administrator and open **Administration > Organization** to set your name, logo, time zone and currency.
2. **Administration > Roles** - review the permission matrix or create your own roles.
3. **Administration > Users > New user** - invite colleagues. They receive an activation e-mail, choose their password and are signed in.
4. Optional: seed the Rwanda starter data (legal references and retention rules) with `php artisan db:seed --class=RwandaStarterSeeder`.

### Tests and quality

```bash
php artisan test
```

Use a **separate** database for tests (see `.env.testing.example`); the test suite wipes it.

## Project layout

```
app/Http/Controllers/Api/V1   thin controllers, one per resource
app/Models                    Eloquent models (tenant scope via BelongsToOrganization)
app/Services                  business rules (AuditLogger, Notifier, InvitationService, ReportService ...)
database/migrations           schema (read in file-name order)
database/seeders              lookups, roles, permissions, menu, starter data
lang/{en,fr,rw,sw}            server-side texts (API messages, e-mails, reports, PDFs)
routes/api.php                every endpoint with its required permission
```

## Deployment

Production material (Nginx, Supervisor, cron, release and backup scripts, hardening and regression checklists) is in the `deploy/` and `docs/` folders - see `docs/DEPLOYMENT.md`.
A route inventory with required permissions is in `docs/openapi.yaml`.

## Contributing

Contributions of every size are welcome - code, translations, documentation, bug reports, ideas and testing in real organizations.

1. Look at the [open issues](https://github.com/Dabani/company-operation-system/issues), especially those labelled `good first issue` and `help wanted`, or open one to discuss your idea first.
2. Fork, create a branch (`feature/short-name`), make your change.
3. Keep to the project rules: **permissions checked on the server**, **no enums or JSON columns** (use lookup tables), **every user-facing text translated** in all four languages, **sensitive actions audited**.
4. Add or update tests, run `php artisan test`, and open a pull request describing what and why.

Please read `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md` before your first pull request. Security problems: do **not** open a public issue - follow `SECURITY.md`.

### Where help is most valuable

- Native-speaker review of the **Kinyarwanda** and **Swahili** translations (first-pass today), and new languages.
- Tests: feature tests for every module, and end-to-end scenarios.
- Real-world feedback: tell us what your organization needed that was missing.

## Roadmap

**Now available**: service desk, controlled files, IT infrastructure, archive and records, compliance, reports, invitations and user management, PWA, health monitoring.

**Next**

- [ ] Two-factor authentication (administrators and managers first)
- [ ] Antivirus scanning of uploaded files before they become downloadable
- [ ] Complete OpenAPI schemas (requests and responses) and generated API documentation
- [ ] Continuous integration (tests, static analysis, dependency audit) and a Docker development setup
- [ ] Scheduled report delivery by e-mail and dashboard widgets fed by reports
- [ ] Asynchronous exports for very large datasets
- [ ] Single sign-on (OIDC / SAML) and directory import
- [ ] Bulk role changes, user offboarding checklist
- [ ] Web push notifications
- [ ] Configurable thresholds for report indicators
- [ ] Additional languages

Have an idea that is not here? [Open an issue](https://github.com/Dabani/company-operation-system/issues) - this roadmap is meant to be shaped by the people who use the platform.

## Project status

COPS is feature-rich and used as a foundation for real deployments, but it is young: expect rough edges, and please run the regression checklist in `docs/REGRESSION-CHECKLIST.md` on a staging copy before going live. Kinyarwanda and Swahili translations are first-pass.

## License

Released under the [MIT License](LICENSE). *(Choose the license that fits your goals before publishing and add the `LICENSE` file.)*

## Acknowledgements

Built with [Laravel](https://laravel.com), [Laravel Excel](https://laravel-excel.com), [Dompdf](https://github.com/dompdf/dompdf) and the open-source community.
