#!/usr/bin/env bash
# Bringing the live shop up to date, in the order that keeps it serving.
#
# Written after doing it by hand twice in one night and reaching for
# `composer install --no-dev` both times. That flag strips packages the
# app boots with, the autoloader loses Filament\PanelProvider, and the
# bakery goes down until a plain `composer install` puts it back. It is
# not available here at all.
set -u

APP=/home/ubuntu/bakery-management-system
BACK=$APP/backend
LOCK=/tmp/deploy.lock

exec 9>"$LOCK"
if ! flock -n 9; then
  echo "یک استقرار در جریان است." >&2
  exit 3
fi

say() { echo; echo "=== $* ==="; }

# Artisan runs as www-data, because that is who owns bootstrap/cache and
# storage/logs and who has to read what these commands write. Run as
# ubuntu they failed on every deploy — «Operation not permitted» buried
# in a stack trace — after which the shop ran on whatever caches the
# previous deploy had left behind, and nobody noticed because the site
# still answered 200.
artisan() { sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan "$@"; }

say "۱/۶  کد"
cd "$APP" || exit 1
git pull -q origin main
git log --oneline -1

say "۲/۶  وابستگی‌ها"
cd "$BACK" || exit 1
# Never --no-dev. See the note at the top; it has taken the shop down
# twice.
composer install --no-interaction --prefer-dist --quiet 2>&1 | tail -3
echo "    نصب شد"

say "۳/۶  قالب‌بندی"
./vendor/bin/pint --test 2>&1 | tail -2

say "۴/۶  مهاجرت‌های معلق"
artisan migrate:status 2>/dev/null | grep -i pending | head -10 || echo "    هیچ مهاجرت معلقی نیست"

say "۵/۶  کش"
artisan config:cache -q
artisan route:cache -q
artisan view:cache -q
artisan filament:cache-components -q 2>/dev/null
sudo systemctl reload php8.3-fpm nginx 2>/dev/null
echo "    ساخته و بارگذاری شد"

say "۶/۶  آیا کار می‌کند"
printf "    API   : "; curl -s -m 10 http://127.0.0.1/api/v1/health; echo
printf "    پنل   : "; curl -s -o /dev/null -w "%{http_code}\n" -m 10 http://127.0.0.1/admin/login
printf "    ۸۰۰۰  : "; curl -s -o /dev/null -w "%{http_code}\n" -m 10 http://127.0.0.1:8000/api/v1/health
echo
artisan stock:audit 2>&1 | tail -20
