#!/usr/bin/env bash
# Bringing the shop up to date on its own, once a night.
#
# The deploy has always needed a person with a terminal, and every time
# that person was unavailable the shop ran old code: a phone release would
# land with the server still on the previous version, and the new screens
# came up empty because the fields they read were not being sent yet.
#
# **Once a night, not every few minutes.** Deploying was made a deliberate
# act for a reason — nobody wants a merge at four in the afternoon
# restarting php-fpm in the middle of the day's sales, and a migration
# takes the shop down for the seconds it runs. So this is put on cron at
# an hour when the oven is cold, and anything wanted sooner is still the
# two lines a person types.
#
# It does nothing at all when there is nothing new, which is most nights.
set -u

APP=${APP:-/home/ubuntu/bakery-management-system}
LOG=${AUTO_DEPLOY_LOG:-/var/log/bakery-auto-deploy.log}
LOCK=${AUTO_DEPLOY_LOCK:-/var/lock/bakery-auto-deploy.lock}
REF=${AUTO_DEPLOY_REF:-main}

# Its own lock, separate from the one `deploy.sh` takes. Two nightly runs
# cannot overlap, and a run that finds a person already deploying by hand
# steps aside rather than queueing behind them.
# Opening the lock and taking it are two different failures, and
# they used to read as one. On the live server this line printed
# «Permission denied», `flock` then failed on a file descriptor
# that had never opened, and the script announced «a deploy is
# already running» — sending somebody to hunt for a deploy that
# did not exist. Every test passed a writable path, so the arm
# was never once run.
if ! : >>"$LOCK" 2>/dev/null; then
  echo "قفل «$LOCK» باز نشد. مسیر دیگری بدهید:" >&2
  echo "  sudo AUTO_DEPLOY_LOCK=/var/lock/bakery-auto.lock $0" >&2
  exit 4
fi

exec 9>>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

say() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

cd "$APP" || { say "!!! پوشهٔ برنامه پیدا نشد: $APP"; exit 1; }

# Nothing is pulled over uncommitted work. `deploy.sh` refuses too, but
# refusing here as well keeps the log honest: «کسی روی سرور چیزی را دست
# نگه داشته» is a different night from «استقرار شکست خورد».
if ! git diff --quiet || ! git diff --cached --quiet; then
  say "صرف‌نظر شد: پوشهٔ کار تغییرات ثبت‌نشده دارد."
  exit 0
fi

if ! git fetch -q origin "$REF" 2>>"$LOG"; then
  say "!!! fetch ناموفق بود؛ چیزی تغییر نکرد."
  exit 1
fi

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse FETCH_HEAD)

# The ordinary night. Silent on purpose: a log that writes a line every
# night for «nothing happened» is a log nobody reads on the night
# something did.
if [ "$LOCAL" = "$REMOTE" ]; then
  exit 0
fi

say "کد تازه: ${LOCAL:0:7} → ${REMOTE:0:7}"

# `deploy.sh` does the rest, including pulling: applying pending
# migrations behind `artisan down`, rebuilding the caches, reloading, and
# exiting non-zero if the shop does not come back. Its exit code is this
# script's answer — there is deliberately no second opinion here about
# whether the deploy worked.
if bash scripts/deploy.sh "$REF" >>"$LOG" 2>&1; then
  say "استقرار انجام شد: $(git rev-parse --short HEAD)"
  exit 0
fi

say "!!! استقرار شکست خورد. مغازه روی $(git rev-parse --short HEAD) است."
say "!!! تا شب بعد دوباره تلاش نمی‌شود؛ لاگ بالا را ببینید."
exit 1
