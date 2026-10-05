#!/usr/bin/env bash
# Production update for one CRM copy. Run from the Laravel folder (the one with artisan):
#   bash deploy.sh
# On Cloudways, first press "Pull" under Deployment via GIT (that copy has no .git folder);
# on a server with a git checkout, the script pulls by itself.
set -euo pipefail

php artisan down --retry=30 || true
# Whatever happens below, never leave the site in maintenance mode.
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

if [ -d .git ]; then
    git pull --ff-only
else
    echo "No .git folder: using the files already pulled by the hosting panel."
fi

composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
# config first: the migrations must read the new .env values, not a stale cached config
php artisan config:clear
php artisan migrate --force
# never clear the application cache on deploy: locks and back-offs live there (F-053)
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan optimize
php artisan storage:link 2>/dev/null || true
# Stops the running schedule:run from repeating sub-minute tasks (queue:tick) with the old code.
php artisan schedule:interrupt
php artisan queue:restart
php artisan up
echo "Deployed."
