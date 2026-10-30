#!/usr/bin/env bash
# Release script. Run on the server as the deploy user: ./deploy/deploy.sh
# Order matters: maintenance mode -> code -> migrate -> caches -> workers restart -> up. Roll back with deploy/rollback notes in docs/DEPLOYMENT.md.
set -euo pipefail
API=/var/www/company-ops-api
WEB=/var/www/company-ops-web

echo "== backup first (database + files)"; "$API/deploy/backup.sh"

echo "== API"
cd "$API"
php artisan down --retry=60 --refresh=15 || true
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\ReportsSeeder --force   # idempotent seeders only; add others as bundles ship
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
supervisorctl restart ops-reverb || true
php artisan up

echo "== Web"
cd "$WEB"
git pull --ff-only
npm ci
npm run build:check          # typecheck + build + initial-JS budget
echo "== smoke test"
curl -fsS https://ops.example.com/api/v1/health && echo
echo "Release done. Check GET /api/v1/health/details (signed in as admin), then run the smoke list in docs/REGRESSION-CHECKLIST.md (section 'After every release')."
