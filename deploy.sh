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
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan storage:link 2>/dev/null || true
php artisan queue:restart
php artisan up
echo "Deployed."
