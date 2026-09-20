#!/usr/bin/env bash
# Bringing the shop up to date now, and keeping it that way afterwards.
#
# The nightly deploy already existed and needed no key, no secret and no
# permission from GitHub — but installing it meant three commands and an
# editor, and `crontab -e` over a phone is not a thing anybody does
# twice. So it was never installed, and the shop ran old code for weeks
# while a release sat on main.
#
# This is that install as one paste. It is safe to run again: the cron
# line is replaced, not added twice, and a second run of a shop that is
# already current deploys nothing.
#
# It deploys immediately as well as nightly, because the person running
# it is asking for the shop to be up to date — not to be up to date at
# three tomorrow morning. That restarts php-fpm, so it is said plainly
# before it happens and skipped with --no-deploy.
set -u

APP=${APP:-/home/ubuntu/bakery-management-system}
LOG=${AUTO_DEPLOY_LOG:-/var/log/bakery-auto-deploy.log}
HOUR=${AUTO_DEPLOY_HOUR:-3}
DEPLOY=1

for arg in "$@"; do
    case "$arg" in
        --no-deploy) DEPLOY=0 ;;
        *)
            echo "گزینهٔ ناشناخته: $arg" >&2
            echo "تنها گزینه: --no-deploy (فقط شبانه را نصب کن، حالا مستقر نکن)" >&2
            exit 2
            ;;
    esac
done

if [ ! -d "$APP/.git" ]; then
    echo "پوشهٔ برنامه پیدا نشد: $APP" >&2
    echo "اگر جای دیگری است: APP=/path/to/app bash $0" >&2
    exit 1
fi

cd "$APP" || exit 1

echo "نصب استقرار شبانه"
echo "  برنامه: $APP"
echo "  ساعت:   $HOUR بامداد"
echo

# --- the log ------------------------------------------------------------

# Written by the nightly run as whoever owns the crontab, so it has to be
# writable by them rather than by root.
if [ ! -w "$LOG" ]; then
    if ! sudo touch "$LOG" 2>/dev/null || ! sudo chown "$(id -un)" "$LOG" 2>/dev/null; then
        echo "لاگ «$LOG» ساخته نشد." >&2
        echo "دستی بسازید، یا مسیر دیگری بدهید:" >&2
        echo "  AUTO_DEPLOY_LOG=\$HOME/bakery-auto-deploy.log bash $0" >&2
        exit 1
    fi
fi

echo "  ✓ لاگ: $LOG"

# --- the cron line ------------------------------------------------------

LINE="0 $HOUR * * * /bin/bash $APP/scripts/auto-deploy.sh"

# Every line but this script's own is kept, so a crontab with other jobs
# in it survives — and running this twice replaces rather than doubles.
# `crontab -l` exits non-zero when there is no crontab at all, which is
# not an error here.
CURRENT=$(crontab -l 2>/dev/null || true)
KEPT=$(printf '%s\n' "$CURRENT" | grep -v 'scripts/auto-deploy.sh' || true)

if printf '%s\n%s\n' "$KEPT" "$LINE" | sed '/^$/d' | crontab -; then
    echo "  ✓ هر شب ساعت $HOUR بامداد"
else
    echo "cron نصب نشد." >&2
    exit 1
fi

# --- now ----------------------------------------------------------------

if [ "$DEPLOY" -eq 0 ]; then
    echo
    echo "چیزی مستقر نشد — فقط شبانه نصب شد."
    echo "امشب ساعت $HOUR، اگر کد تازه‌ای باشد، خودش می‌رود بالا."
    exit 0
fi

echo
echo "حالا یک بار مستقر می‌کنم."
echo "چند ثانیه مغازه بسته می‌شود — اگر وسط فروش هستید، Ctrl+C و بعداً."
echo

# Five seconds is enough to stop, and short enough that nobody walks
# away from it.
sleep 5

if bash scripts/deploy.sh; then
    echo
    echo "مغازه روی $(git rev-parse --short HEAD) است."
    echo
    echo "حالا یک بار همه‌چیز را بپرسید — چیزی را عوض نمی‌کند:"
    echo "  bash scripts/checkup.sh"
    exit 0
fi

echo
echo "استقرار شکست خورد. بالا را بخوانید." >&2
echo "شبانه نصب شده و امشب دوباره تلاش می‌کند." >&2
exit 1
