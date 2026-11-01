# Deployment guide (Laragon development -> production server)

Target: Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM, MySQL 8.4, Node 24 (build only), Supervisor, one host name serving SPA + API + websocket
(same origin, so Sanctum cookies need no CORS). Config files are in `deploy/`.

## 1. Server preparation
1. Packages: `nginx php8.4-fpm php8.4-{mysql,mbstring,xml,curl,zip,gd,intl,bcmath} mysql-server supervisor certbot python3-certbot-nginx gnupg unzip git`; Composer 2.10; Node 24 + npm.
2. MySQL: create database `company_ops` and a **dedicated** user `ops_app` with privileges on that database only. Never use `root` in `.env`.
3. Firewall: allow 22 (restrict by IP if possible), 80, 443 only. MySQL (3306) and Reverb (8080) listen on localhost only.
4. Directories: `/var/www/company-ops-api`, `/var/www/company-ops-web`, `/var/log/ops`, `/var/backups/ops` (mode 700). `storage` and `bootstrap/cache` writable by `www-data`; nothing else is.
5. Time: server clock on UTC with NTP enabled (audit timestamps and the scheduler depend on it).

## 2. First install
```bash
cd /var/www/company-ops-api
composer install --no-dev --optimize-autoloader
cp deploy/.env.production.example .env      # fill every empty value; php artisan key:generate
php artisan migrate --force
php artisan db:seed --force                  # first install only; prints the admin password if OPS_ADMIN_PASSWORD is empty
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```
Remove `OPS_ADMIN_PASSWORD` from `.env` afterwards, sign in, and change the admin password.

Web: `cp deploy/.env.web.production.example company-ops-web/.env.production`, then `npm ci && npm run build:check` and serve `dist/`.

Nginx: install `deploy/nginx.conf`, then `certbot --nginx -d ops.example.com`. Supervisor: `deploy/supervisor-ops.conf` (queue workers + Reverb).
Cron: the single line in `deploy/crontab.txt` runs the Laravel scheduler (deadline checks, file purge, heartbeat, pruning).

## 3. Verify the installation
* `curl https://ops.example.com/api/v1/health` -> `{"status":"ok",...}`
* Signed in as admin: `GET /api/v1/health/details` -> every check `ok: true` (scheduler needs ~2 minutes after cron starts; backup shows `false` until the first verified backup is recorded).
* Send yourself a test case and confirm the e-mail and SMS arrive (the Notification delivery report (Reports) shows failures).
* Run the "After every release" list in `REGRESSION-CHECKLIST.md`.

## 4. Releases
Run `deploy/deploy.sh`. It takes a backup, enters maintenance mode, pulls code, installs dependencies, migrates, rebuilds caches, restarts workers, builds the web app with the typecheck and bundle-size budget, and calls the health endpoint.
* Migrations must be additive when possible (add column -> deploy code -> drop later) so a rollback of the code does not need a database rollback.
* The service worker updates only when the user accepts the "new version" prompt; old hashed chunks are replaced after the reload (the app reloads itself once if a stale chunk is requested).

## 5. Rollback
1. `php artisan down`.
2. Code: `git checkout <previous tag>`; `composer install --no-dev --optimize-autoloader`; rebuild web (`npm ci && npm run build`).
3. Database: only if the release added a destructive migration - restore the pre-release dump taken by `deploy.sh` (`gunzip -c db-<stamp>.sql.gz | mysql ...`). Otherwise leave the schema as is.
4. Rebuild caches, `php artisan queue:restart`, `php artisan up`, run the health check.

## 6. Backups and restore drills (guide 15.3)
* `deploy/backup.sh` (cron, nightly): database dump + uploaded files, encrypted with `gpg` when `BACKUP_PASSPHRASE_FILE` is set, 30-day local retention. **Copy the archives off the server** (second site / bucket) and keep the passphrase somewhere else.
* Set targets and write them down: RPO (maximum data loss, suggest 24 h) and RTO (time to restore, suggest 4 h). Measure them in the drill.
* Drill at least quarterly on a **separate** machine: restore the latest dump + files, sign in, open a case with an attachment, download a file, check the audit log shows history. Record the result in *Compliance > Backups* - this feeds the "days since last verified backup" indicator, the health endpoint and the executive report.

| Drill record | Value |
|---|---|
| Date / performed by | |
| Backup used (timestamp) | |
| Time to restore (RTO measured) | |
| Data age at restore (RPO measured) | |
| Files readable / checksums OK | |
| Issues and corrective actions | |

## 7. Monitoring
* Uptime monitor on `/api/v1/health` (alert on non-200 for 2 consecutive minutes).
* Weekly look at `/api/v1/health/details` and the **Notification delivery** report (failure rate, top errors).
* Logs: `storage/logs/laravel-*.log` (daily, 14 days kept by default), `/var/log/ops/*`, Nginx logs. Rotate with logrotate.
* Provider limits: Brevo free e-mail plan is 300/day - watch the delivery report if volume grows.

## 8. Routine operations
| Task | How |
|---|---|
| Restart workers after config change | `php artisan queue:restart` |
| Failed jobs | `php artisan queue:failed`, `queue:retry all`; health shows the count |
| Lock out a user quickly | Administration > Users > suspend |
| Rotate secrets (Brevo key, DB password, APP_KEY) | change `.env`, `php artisan config:cache`, `queue:restart`. Rotating APP_KEY signs everyone out and invalidates encrypted values - plan it. |
| Add a language | add the language in the lookups, then a UI file in `src/i18n/` and `lang/<code>/*.php` for server texts |
