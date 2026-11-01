# Security hardening checklist (guide section 12)

Tick each line on the production server and keep the signed copy with the release record.
"In code" = already done by Bundles 1-7; "Operator" = you must do it on the server.

## Application (in code)
- [x] Authentication by Sanctum cookie session; login/password-reset rate limits; roles and permissions enforced on the server for every route (`perm:` middleware) - the UI hiding a button is never the control.
- [x] Organization (tenant) global scope on all business models; child tables reached only through scoped parents (Bundle 7 reports follow the same rule).
- [x] Rich text sanitised on input and output (SanitizeRichText + SafeHtml).
- [x] Files: private disk, authorised download endpoint, controlled actions (view / download / archive / restore / delete-by-request), history kept.
- [x] Audit log for sensitive actions; Bundle 7 adds `report.viewed` and `report.exported` with the filters used.
- [x] Report exports neutralise spreadsheet formula injection (cells starting with `= + - @`).
- [x] Aggregate reports never contain record titles, incident details, internal notes, recipients or personal data; delivery errors are scrubbed of e-mails and phone numbers.
- [x] API responses carry `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`; JSON is `Cache-Control: no-store`; HSTS on HTTPS.
- [x] Rate limits: `reports` 30/min, `report-export` 6/min, `health` 60/min per user/IP.
- [x] Service worker caches only the app shell and the two public branding endpoints - never authenticated API data; writes are never queued offline.

## Operator
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`; `.env` mode 600, owned by the deploy user, never committed.
- [ ] HTTPS only (certbot renewal tested: `certbot renew --dry-run`); HTTP redirects to HTTPS; `SESSION_SECURE_COOKIE=true`.
- [ ] `TRUSTED_PROXIES` set so the app sees HTTPS correctly behind Nginx.
- [ ] Nginx CSP from `deploy/nginx.conf` active (check the browser console for violations after each release; extend `connect-src` only for hosts you really use).
- [ ] Dedicated MySQL user with minimum rights; MySQL listens on 127.0.0.1; separate user for backups if possible.
- [ ] Firewall: 22 (restricted), 80, 443 only; SSH keys only, no password login, fail2ban on SSH.
- [ ] `storage/` and `bootstrap/cache` writable by `www-data` only; uploads directory not directly web-served (Nginx has no `location` for it).
- [ ] Unattended security updates enabled (`unattended-upgrades`); PHP, MySQL, Nginx patched monthly.
- [ ] Admin account: strong unique password, 2-step process for admin changes (second admin reviews role changes), seed password removed from `.env`.
- [ ] Brevo/Africa's Talking keys scoped to sending only, stored only in `.env`; sender domain has SPF, DKIM and DMARC (otherwise e-mails land in spam).
- [ ] Dependency audit before each release: `composer audit` and `npm audit --omit=dev`; review before upgrading.
- [ ] Backups: encrypted, copied off-site, restore drill done this quarter (see DEPLOYMENT.md section 6).
- [ ] Log retention and access agreed (who can read `storage/logs`, how long kept) - logs may contain user identifiers.
- [ ] Personal-data law: privacy notice published; retention schedule and legal basis recorded (Law 058/2021 on personal data protection); breach-response contact named.

## Recommended later (not in this bundle)
- Two-factor authentication for admin and manager roles.
- Antivirus scanning of uploads (e.g. ClamAV queue job) before files become downloadable.
- Per-IP login throttling at Nginx (`limit_req`) in addition to the application limit.
- A web application firewall / managed DDoS protection in front of the server.
