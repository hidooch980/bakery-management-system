#!/usr/bin/env bash
# One command that says whether the code is fit to push, and that cannot
# run twice at once.
#
# Three mistakes on 2026-08-18 all came from guessing at that last part:
#
#   - `pgrep -f "vendor/bin/phpunit"` matched the *waiting shell itself*,
#     because the pattern was sitting in that shell's own command line. So
#     "is a run in progress?" always answered yes, and "wait until idle"
#     never finished. Three such loops were found watching each other with
#     nothing running at all.
#   - a second suite was started on top of a first and produced 147 errors
#     that were not real
#   - pint was checked after pushing rather than before, four times in a row
#
# flock settles the first two. The order of the steps settles the third.
set -u

SRC=/home/ubuntu/bakery-management-system/backend
T=/tmp/t
LOCK=/tmp/verify.lock
PINT_RED=0

# Opening the lock and taking it are two different failures, and
# they used to read as one. On the live server this line printed
# «Permission denied», `flock` then failed on a file descriptor
# that had never opened, and the script announced «a deploy is
# already running» — sending somebody to hunt for a deploy that
# did not exist. Every test passed a writable path, so the arm
# was never once run.
if ! : >>"$LOCK" 2>/dev/null; then
  echo "قفل «$LOCK» باز نشد. مسیر دیگری بدهید:" >&2
  echo "  sudo LOCK=/var/lock/bakery-verify.lock $0" >&2
  exit 4
fi

exec 9>>"$LOCK"
if ! flock -n 9; then
  echo "یک اجرا همین حالا در جریان است. این یکی شروع نشد." >&2
  exit 3
fi

echo "=== ۱/۴  همگام‌سازی /tmp/t از چک‌اوت گیت ==="
# Wholesale, never file by file: a tree updated by scp drifts from the
# commit it claims to test. On 2026-08-17 it was five tests short and a
# green run on it meant nothing.
for d in app tests database routes config resources; do
  rsync -a --delete "$SRC/$d/" "$T/$d/"
done
cp "$SRC/composer.json" "$SRC/composer.lock" "$T/" 2>/dev/null
echo "    $(cd "$SRC" && git log --oneline -1)"

echo
echo "=== ۲/۴  قالب‌بندی — pint ==="
if ! (cd "$SRC" && ./vendor/bin/pint --test >/tmp/pint.out 2>&1); then
  PINT_RED=1
fi
tail -5 /tmp/pint.out

echo
echo "=== ۳/۴  پایگاه تست تازه ==="
# A killed run wedges it, and every later run then fails with hundreds of
# "table already exists" errors that look like broken code and are not.
sudo mysql -e "DROP DATABASE IF EXISTS bakery_db_test; CREATE DATABASE bakery_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "    ساخته شد"

echo
echo "=== ۴/۴  مجموعهٔ کامل ==="
cd "$T" || exit 1
vendor/bin/phpunit --configuration="$T/phpunit.xml" >/tmp/suite.log 2>&1
SUITE=$?
tail -6 /tmp/suite.log

echo
echo "──────────── نتیجه ────────────"
[ "$PINT_RED" = "1" ] && echo "  pint  : قرمز — پیش از push اصلاح کنید" || echo "  pint  : سبز"
[ "$SUITE" = "0" ] && echo "  تست‌ها: سبز" || echo "  تست‌ها: قرمز — /tmp/suite.log را بخوانید"

[ "$PINT_RED" = "1" ] && exit 1
exit "$SUITE"
