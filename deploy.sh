#!/usr/bin/env bash
# Production update for one CRM copy. Run from the Laravel folder (the one with artisan):
#   bash deploy.sh
set -euo pipefail

php artisan down --retry=30 || true
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan storage:link 2>/dev/null || true
php artisan queue:restart
php artisan up
echo "Deployed $(git rev-parse --short HEAD)"
