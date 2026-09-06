#!/usr/bin/env bash
# Bringing the live shop up to date, in the order that keeps it serving.
#
# Written after doing it by hand twice in one night and reaching for
# `composer install --no-dev` both times. That flag strips packages the
# app boots with, the autoloader loses Filament\PanelProvider, and the
# bakery goes down until a plain `composer install` puts it back. It is
# not available here at all.
#
# The migration step used to *report* pending migrations and carry on.
# That is the worst of the three things it could do: the new code was
# already live against the old schema, and the site went on answering
# 200 while doing it, so nothing said anything was wrong. Today it
# applies them — behind `artisan down`, so no one is served by a
# half-migrated shop — and puts the code back where it was if they fail.
set -u

# Overridable so the test can run this script against a fixture rather
# than the live shop. Unset everywhere else, which is the real server.
APP=${APP:-/home/ubuntu/bakery-management-system}
BACK=$APP/backend
# Not /tmp. The lock lived there until a single run without `sudo` left
# an ubuntu-owned file behind, after which every `sudo` run was refused:
# `fs.protected_regular` stops even root opening another user's file in a
# sticky world-writable directory. /var/lock is not world-writable, and
# this script is always run as root, so the owner never varies.
LOCK=${LOCK:-/var/lock/bakery-deploy.lock}

# What to deploy. `main` unless told otherwise — the argument is for
# putting a known-good tag back when a release turns out to be bad:
#
#   bash scripts/deploy.sh v4.88.0
REF=${1:-main}

# Opening the lock and taking it are two different failures, and
# they used to read as one. On the live server this line printed
# «Permission denied», `flock` then failed on a file descriptor
# that had never opened, and the script announced «a deploy is
# already running» — sending somebody to hunt for a deploy that
# did not exist. Every test passed a writable path, so the arm
# was never once run.
if ! : >>"$LOCK" 2>/dev/null; then
  echo "قفل «$LOCK» باز نشد. مسیر دیگری بدهید:" >&2
  echo "  sudo LOCK=/var/lock/bakery.lock $0 <tag>" >&2
  exit 4
fi

exec 9>>"$LOCK"
if ! flock -n 9; then
  echo "یک استقرار در جریان است." >&2
  exit 3
fi

say() { echo; echo "=== $* ==="; }

fail() { echo; echo "!!! $*" >&2; exit 1; }

# Artisan runs as www-data, because that is who owns bootstrap/cache and
# storage/logs and who has to read what these commands write. Run as
# ubuntu they failed on every deploy — «Operation not permitted» buried
# in a stack trace — after which the shop ran on whatever caches the
# previous deploy had left behind, and nobody noticed because the site
# still answered 200.
artisan() { sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan "$@"; }

# Set once the shop has been taken down, so the trap below knows it has
# something to undo.
DOWN=no

# Whatever happens after this point — a failed migration, a syntax error
# in this script, someone's Ctrl-C — the shop comes back up. Left to the
# happy path alone, a script that died between `down` and `up` would
# leave the bakery closed until a person noticed and knew the command.
restore() {
  if [ "$DOWN" = yes ]; then
    echo
    echo "=== باز کردن مغازه ==="
    artisan up || echo "    !!! «php artisan up» را دستی اجرا کنید" >&2
    DOWN=no
  fi
}
trap restore EXIT INT TERM

say "۱/۶  کد"
cd "$APP" || fail "پوشهٔ برنامه پیدا نشد: $APP"

# Remembered before the pull, so a migration that fails has somewhere to
# put the shop back to.
WAS=$(git rev-parse HEAD)

# Nothing is pulled over uncommitted work. A `git pull` onto a dirty
# tree either fails half way or quietly merges around the change, and
# this directory is edited by hand when something is being chased down
# at the oven. Say so and stop, rather than deciding for them.
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo
  git status --short
  fail "پوشهٔ کار روی سرور تغییرات ثبت‌نشده دارد. اول تکلیف آن‌ها را روشن کنید."
fi

git fetch -q origin "$REF" || fail "«$REF» از origin گرفته نشد؛ چیزی تغییر نکرد."

if [ "$REF" = main ]; then
  # Fast-forward only. A plain `pull` would build a merge commit out of
  # whatever had diverged on the server, and the shop would then be
  # running code that exists nowhere else.
  git merge -q --ff-only FETCH_HEAD \
    || fail "main روی سرور از origin جدا شده. با دست بررسی کنید."
else
  # A tag, for putting a known-good version back. Detached on purpose:
  # this is a checkout of one exact release, not a branch to carry on
  # from.
  git checkout -q --detach FETCH_HEAD \
    || fail "checkout «$REF» ناموفق بود."
  echo "    ⚠ سرور روی «$REF» است، نه main."
  echo "      برای برگشتن: git checkout main"
fi

git log --oneline -1

say "۲/۶  وابستگی‌ها"
cd "$BACK" || fail "پوشهٔ بک‌اند پیدا نشد: $BACK"
# Never --no-dev. See the note at the top; it has taken the shop down
# twice.
#
# And --no-scripts, because composer's post-autoload-dump runs three
# artisan commands as whoever invoked composer — which is ubuntu, who
# cannot write bootstrap/cache. They failed on every deploy behind a
# «returned with error code 1» that said nothing about which command or
# why, and the package manifest quietly stayed as it was. Run below as
# the user who owns the files.
composer install --no-interaction --prefer-dist --quiet --no-scripts 2>&1 | tail -3
artisan clear-compiled -q          # what ComposerScripts::postAutoloadDump does
artisan package:discover -q
artisan filament:upgrade -q
echo "    نصب شد"

say "۳/۶  قالب‌بندی"
./vendor/bin/pint --test 2>&1 | tail -2

say "۴/۶  مهاجرت‌ها"
PENDING=$(artisan migrate:status 2>/dev/null | grep -ci pending || true)

if [ "$PENDING" -eq 0 ]; then
  # The common case, and the one worth keeping quick: no schema change
  # means no reason to close the shop at all.
  echo "    هیچ مهاجرت معلقی نیست — مغازه بسته نمی‌شود"
else
  echo "    $PENDING مهاجرت معلق"
  artisan migrate:status 2>/dev/null | grep -i pending

  # Closed for the duration. Between the migration and the reload the
  # old code is still the code being served, and a migration that drops
  # or renames a column breaks it for exactly that window — which is
  # short, and is the middle of somebody's sale.
  echo
  echo "    بستن موقت مغازه"
  artisan down --render=errors::503 2>/dev/null || artisan down || fail "بستن مغازه ممکن نشد."
  DOWN=yes

  if artisan migrate --force; then
    echo "    مهاجرت‌ها اجرا شدند"
  else
    # Back to the code that matches the schema on disk. A failed
    # migration can leave the database half-changed, which is not
    # something this script can put right — but serving the *old* code
    # against it is far closer to correct than serving the new.
    echo
    echo "    !!! مهاجرت شکست خورد — کد به $WAS برمی‌گردد" >&2
    cd "$APP" && git checkout -q --detach "$WAS" && git reset -q --hard "$WAS"
    cd "$BACK" || true
    artisan config:cache -q || true
    artisan route:cache -q || true
    fail "استقرار متوقف شد. وضعیت دیتابیس را بررسی کنید؛ ممکن است نیمه‌کاره مانده باشد."
  fi
fi

say "۵/۶  کش"
artisan config:cache -q
artisan route:cache -q
artisan view:cache -q
artisan filament:cache-components -q 2>/dev/null
sudo systemctl reload php8.3-fpm nginx 2>/dev/null
echo "    ساخته و بارگذاری شد"

# Before the health check, or every check below answers 503 by design.
restore

say "۶/۶  آیا کار می‌کند"
HEALTH=$(curl -s -m 10 http://127.0.0.1/api/v1/health || true)
PANEL=$(curl -s -o /dev/null -w "%{http_code}" -m 10 http://127.0.0.1/admin/login || true)

printf "    API   : %s\n" "$HEALTH"
printf "    پنل   : %s\n" "$PANEL"
printf "    ۸۰۰۰  : "; curl -s -o /dev/null -w "%{http_code}\n" -m 10 http://127.0.0.1:8000/api/v1/health || true
echo

# The exit code means something now. It printed the answer and returned
# success either way before, so a deploy that had left the shop broken
# looked exactly like one that had not — including to anything running
# this from a script.
case "$HEALTH" in
  *'"service":"bakery"'*) ;;
  *) fail "API سالم جواب نداد. مغازه بالا نیامده است." ;;
esac

[ "$PANEL" = 200 ] || fail "پنل $PANEL برگرداند."

artisan stock:audit 2>&1 | tail -20
