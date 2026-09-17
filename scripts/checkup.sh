#!/usr/bin/env bash
# Asking the live shop every question we know how to ask, and changing
# nothing.
#
# After a deploy there are half a dozen commands worth running, each
# useful and each easy to forget. Typed one at a time they get typed
# wrong: the pair that move money have an `--apply` twin, and the
# difference between the two is the whole safety of them.
#
# So this runs only the read-only half, and it cannot be talked into the
# other one — nothing here takes an argument and nothing here writes.
#
# Run it after `deploy.sh`, or any morning the figures look odd.
set -u

APP=${APP:-/home/ubuntu/bakery-management-system}
BACKEND="$APP/backend"

if [ ! -d "$BACKEND" ]; then
    echo "پوشهٔ برنامه پیدا نشد: $BACKEND" >&2
    exit 1
fi

cd "$BACKEND" || exit 1

# Not «did it break» — `shop:health` answers non-zero when the *shop*
# has something to attend to, which on a real bakery is most mornings.
# Calling that a failure would teach the owner to ignore the loudest
# line on the page.
FOUND=0

say() {
    echo
    echo "─────────────────────────────────────────────"
    echo "  $1"
    echo "─────────────────────────────────────────────"
}

# Every check runs, whatever the one before it said: the point of this
# script is the whole picture, and stopping at the first problem hides
# the others behind it.
ask() {
    local title=$1
    shift

    say "$title"

    if ! php artisan "$@"; then
        FOUND=1
    fi
}

echo "بررسی نانوایی — هیچ‌چیز تغییر نمی‌کند."
echo "تاریخ: $(date '+%Y-%m-%d %H:%M')"

ask "سلامت کلی" shop:health
ask "انبار" stock:audit

# The two that have an --apply twin. Named here without it, deliberately,
# and the wording underneath says so — somebody reading this output has
# to be told that what they just saw has not happened yet.
ask "مساعده‌هایی که روی صندوق مانده‌اند" advances:off-the-till
ask "فیش‌های حقوقی که روی صندوق مانده‌اند" salaries:off-the-till

ask "تسویه‌هایی که با کسری بسته شده‌اند" settlements:forgiven

echo
echo "─────────────────────────────────────────────"
echo
echo "چیزی تغییر نکرد."
echo
echo "دو بررسی بالا فقط نشان دادند چه چیزی جابه‌جا می‌شود."
echo "اگر درست بود، برای انجامش:"
echo
echo "    php artisan advances:off-the-till --apply"
echo "    php artisan salaries:off-the-till --apply"
echo
echo "بعدش موجودی صندوق و حساب سفید را یک بار نگاه کنید."

if [ "$FOUND" -ne 0 ]; then
    echo
    echo "یک یا چند بررسی چیزی پیدا کرد — بالا را بخوانید."
fi

exit "$FOUND"
