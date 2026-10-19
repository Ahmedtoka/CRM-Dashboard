#!/usr/bin/env bash
# Switch the bot router on this copy. Run from the Laravel folder (the one with artisan):
#   bash bot-router.sh status          # show the mode .env asks for and the mode the app runs
#   bash bot-router.sh hybrid          # new bot: scripts instant, Haiku for free text, Sonnet for hard cases
#   bash bot-router.sh legacy          # old bot (rollback)
#   bash bot-router.sh shadow          # old bot answers, new router only logs what it would have done
# Switching = edit CRM_BOT_ROUTER in .env, config:cache, queue:restart. Nothing else changes.
# Never prints environment values other than CRM_BOT_ROUTER.
set -euo pipefail

[ -f artisan ] || { echo "Run this from the Laravel folder (artisan not found here)."; exit 1; }
[ -f .env ] || { echo ".env not found here."; exit 1; }

mode="${1:-status}"
case "$mode" in
    status|hybrid|legacy|shadow) ;;
    *) echo "Usage: bash bot-router.sh status|hybrid|legacy|shadow"; exit 1 ;;
esac

show() {
    echo "-- .env asks for : $(grep -E '^CRM_BOT_ROUTER=' .env | tail -1 || echo 'CRM_BOT_ROUTER= (missing: the app default is shadow)')"
    echo "-- app runs      : $(php artisan tinker --execute="echo config('crm.bot.router.mode');" 2>/dev/null | tail -1)"
}

if [ "$mode" = "status" ]; then
    show
    exit 0
fi

if grep -qE '^CRM_BOT_ROUTER=' .env; then
    sed -i "s/^CRM_BOT_ROUTER=.*/CRM_BOT_ROUTER=${mode}/" .env
else
    printf '\nCRM_BOT_ROUTER=%s\n' "$mode" >> .env
fi
echo "-- .env updated"

php artisan config:cache
# workers pick up the new config only after they restart (Supervisor restarts them)
php artisan queue:restart
echo "-- config cached, workers restarting"

show

running="$(php artisan tinker --execute="echo config('crm.bot.router.mode');" 2>/dev/null | tail -1)"
if [ "$running" != "$mode" ]; then
    echo "!! the app still reports '${running}', expected '${mode}'. Check .env for a second CRM_BOT_ROUTER line."
    exit 1
fi

if [ "$mode" = "hybrid" ]; then
    cat <<'EOF'

Next:
  php artisan bot:eval                  # free, fake models: expect every scenario PASS
  php artisan bot:eval --live --force   # one real run on Anthropic (under 1 USD)
  then message the test page as a customer and open "ليه البوت رد كده؟" under the bot's reply.
Rollback at any time:  bash bot-router.sh legacy
EOF
fi
