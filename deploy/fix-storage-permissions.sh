#!/usr/bin/env bash
#
# Lets both users that write to storage/ keep writing to it.
#
# Two accounts write there: www-data serving the site, and the deploy user
# running artisan, the scheduler and the nightly backup. Whichever writes
# first owns the file, and at the default 0644 the other is locked out of it
# until the file is replaced.
#
# It showed up as every artisan command on the server dying with
#
#     The stream or file ".../storage/logs/laravel-<date>.log" could not be
#     opened in append mode: Failed to open stream: Permission denied
#
# on any day a web request happened to create the log file first. The command
# still ran; only its logging died -- which is the worst version of this,
# because the thing that stops working is the thing that would have told you.
#
# Two halves, and it takes both:
#
#   - the file must be group-writable, which config/logging.php now asks for
#     with 'permission' => 0664
#   - the file's group must be one both accounts are in. www-data is already
#     a member of the deploy user's group, so setgid on the directories makes
#     everything created inside them inherit that group instead of www-data's
#
# Fixing only the permissions works until midnight, when the next day's log
# is created with the wrong group and it comes straight back.
#
# Safe to re-run, and worth re-running after any deploy that adds files.

set -euo pipefail

APP_DIR="${1:-/home/ubuntu/bakery-management-system}"
BACKEND="$APP_DIR/backend"
OWNER="$(stat -c '%U' "$APP_DIR")"

if [ ! -d "$BACKEND/storage" ]; then
    echo "پوشه storage پیدا نشد: $BACKEND/storage" >&2
    exit 1
fi

echo "نانوایی: $APP_DIR"
echo "کاربر مالک: $OWNER"

cd "$BACKEND"

# Everything back into the shared group. Files www-data created carry its own
# group, which is the group the deploy user is not in.
sudo chgrp -R "$OWNER" storage bootstrap/cache

# Group gets the same rights as the owner. Capital X sets execute on
# directories only, so a log file does not come out executable.
sudo chmod -R u+rwX,g+rwX storage bootstrap/cache

# The part that makes it stay fixed: anything created inside these directories
# from now on inherits the directory's group rather than the creator's.
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +

echo
echo "بررسی:"
left="$(find storage bootstrap/cache ! -perm -g+w -printf '%u:%g %m %p\n' 2>/dev/null | head -5)"

if [ -n "$left" ]; then
    echo "  هنوز قابل نوشتن نیست:" >&2
    echo "$left" >&2
    exit 1
fi

echo "  همه فایل‌ها برای گروه قابل نوشتن‌اند."
echo "  setgid روی $(find storage bootstrap/cache -type d -perm -2000 | wc -l) پوشه."
echo
echo "✅ انجام شد."
