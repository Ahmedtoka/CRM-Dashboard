#!/usr/bin/env bash
# Go-live of the UI overhaul + Ads Hub on Cloudways, in one run (owner, 2026-10-03).
#
# From the app root over SSH (production: /home/master/applications/ryznnsupxm/public_html):
#   bash scripts/go-live.sh
#
# Safe to re-run. It asks for the Meta System User token once (hidden, never printed or logged) and
# prints the three media buyers' new passwords once: copy them, they are not shown again.

set -euo pipefail

step() { echo; echo "==> $*"; }

step "maintenance on (the migrations touch customers and orders)"
php artisan down --retry=30
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

step "git pull"
git pull --ff-only

step "composer install"
composer install --no-dev --optimize-autoloader --no-interaction

step "npm ci && npm run build"
npm ci --no-audit --no-fund
npm run build

step "migrate"
php artisan migrate --force

step "caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

step "maintenance off"
php artisan up
trap - EXIT

step "restart workers and reverb"
php artisan queue:restart
php artisan reverb:restart || echo "   (reverb restart skipped: restart it from Supervisor if it runs there)"

step "media buyers, Meta connection, account owners from 2026-10-01"
php artisan ads:setup-team

step "checks"
php artisan about --only=environment | sed -n '1,8p'
echo "   PHP upload limits: post_max_size=$(php -r 'echo ini_get("post_max_size");')  upload_max_filesize=$(php -r 'echo ini_get("upload_max_filesize");')"
echo "   (CLI values; raise both for the web from Cloudways -> Application Settings -> PHP)"

echo
echo "Done. Open /ads: the 90-day history syncs on the commercelong worker (crm-commercelong in Supervisor)."
