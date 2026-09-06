#!/usr/bin/env bash
# What `deploy.sh` does when things go wrong, proved rather than assumed.
#
# The script it tests runs once a release against a live bakery, which is
# the worst possible place to find out what its failure paths do — and
# its migration step spent its whole life *reporting* pending migrations
# and carrying on, serving new code against an old schema behind a site
# that still answered 200.
#
# Nothing real is touched. `git`, `php`, `sudo`, `curl`, `composer`,
# `systemctl` and `flock` are stubs on PATH that write down what they
# were asked to do, and the script is pointed at a temporary directory.
set -u

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PASS=0
FAIL=0

check() {
  if [ "$2" = "$3" ]; then
    PASS=$((PASS + 1))
    echo "  ✓ $1"
  else
    FAIL=$((FAIL + 1))
    echo "  ✗ $1"
    echo "      انتظار: $3"
    echo "      واقعی : $2"
  fi
}

contains() {
  case "$2" in
    *"$3"*) PASS=$((PASS + 1)); echo "  ✓ $1" ;;
    *) FAIL=$((FAIL + 1)); echo "  ✗ $1 — «$3» در خروجی نبود" ;;
  esac
}

# A world the script can run in: an app directory that looks like a
# checkout, and stubs for everything that would reach outside it.
#
# PENDING     how many migrations `migrate:status` should report
# MIGRATE     the exit code `migrate --force` should give
# HEALTH      what the health endpoint answers
build_world() {
  WORLD=$(mktemp -d)
  BIN=$WORLD/bin
  LOG=$WORLD/log
  mkdir -p "$BIN" "$WORLD/app/backend/vendor/bin"
  : > "$LOG"

  cat > "$BIN/php" <<'STUB'
#!/usr/bin/env bash
echo "php $*" >> "$LOG"
case "$*" in
  *"migrate:status"*)
    n=${PENDING:-0}
    while [ "$n" -gt 0 ]; do echo "  Pending ....... 2026_01_01_000000_a_change"; n=$((n - 1)); done
    ;;
  *"migrate --force"*) exit "${MIGRATE:-0}" ;;
esac
exit 0
STUB

  # www-data is not a user here, so `sudo -u www-data … php artisan` has
  # to fall through to the stub above rather than actually switching.
  cat > "$BIN/sudo" <<'STUB'
#!/usr/bin/env bash
while [ $# -gt 0 ]; do
  case "$1" in
    -u|HOME=*|XDG_CONFIG_HOME=*) [ "$1" = -u ] && shift; shift ;;
    *) break ;;
  esac
done
exec "$@"
STUB

  cat > "$BIN/systemctl" <<'STUB'
#!/usr/bin/env bash
echo "systemctl $*" >> "$LOG"
STUB

  cat > "$BIN/composer" <<'STUB'
#!/usr/bin/env bash
echo "composer $*" >> "$LOG"
STUB

  cat > "$BIN/curl" <<'STUB'
#!/usr/bin/env bash
echo "curl $*" >> "$LOG"
case "$*" in
  *"admin/login"*) printf '%s' "${PANEL_CODE:-200}" ;;
  *"api/v1/health"*)
    case "$*" in
      *"-o /dev/null"*) printf '%s' "${PANEL_CODE:-200}" ;;
      *) printf '%s' "${HEALTH:-\{\"service\":\"bakery\"\}}" ;;
    esac
    ;;
esac
STUB

  cat > "$WORLD/app/backend/vendor/bin/pint" <<'STUB'
#!/usr/bin/env bash
echo "pint $*"
STUB

  chmod +x "$BIN"/* "$WORLD/app/backend/vendor/bin/pint"

  # A real repository, so `rev-parse` and `reset --hard` are real.
  git -C "$WORLD/app" init -q
  git -C "$WORLD/app" config user.email t@t
  git -C "$WORLD/app" config user.name t
  echo one > "$WORLD/app/file"
  git -C "$WORLD/app" add -A
  git -C "$WORLD/app" commit -qm one
  BEFORE=$(git -C "$WORLD/app" rev-parse HEAD)

  # `git pull origin main` has no remote here. A wrapper makes it a
  # commit instead, so the script really does move forward and really can
  # be reset back.
  # `fetch` has no remote here, so it becomes «make a commit and point
  # FETCH_HEAD at it» — after which the script's own merge and checkout
  # are the real thing, against a real repository.
  cat > "$BIN/git" <<STUB
#!/usr/bin/env bash
GIT=$(command -v git)
if [ "\$1" = fetch ]; then
  cur=\$("\$GIT" -C "$WORLD/app" rev-parse --abbrev-ref HEAD)
  "\$GIT" -C "$WORLD/app" checkout -q -B __incoming
  echo two > "$WORLD/app/file"
  "\$GIT" -C "$WORLD/app" commit -qam two
  # Real git writes «<sha>\t\tbranch 'x' of <url>» here, and `git merge
  # FETCH_HEAD` will not read a bare sha.
  printf '%s\t\tbranch '"'"'main'"'"' of origin\n' \
    "\$("\$GIT" -C "$WORLD/app" rev-parse HEAD)" > "$WORLD/app/.git/FETCH_HEAD"
  "\$GIT" -C "$WORLD/app" checkout -q "\$cur"
  exit 0
fi
exec "\$GIT" "\$@"
STUB
  chmod +x "$BIN/git"
}

run_deploy() {
  ( export PATH="$BIN:$PATH" APP="$WORLD/app" LOG="$LOG" \
      LOCK="$WORLD/lock" PENDING="${PENDING:-0}" MIGRATE="${MIGRATE:-0}" \
      HEALTH="${HEALTH:-}" PANEL_CODE="${PANEL_CODE:-200}"
    bash "$ROOT/scripts/deploy.sh" 2>&1 )
}

echo
echo "=== نسخه‌ای بدون مهاجرت: مغازه بسته نمی‌شود ==="
build_world
PENDING=0 && OUT=$(run_deploy); CODE=$?
check "استقرار موفق" "$CODE" 0
contains "می‌گوید مهاجرتی نیست" "$OUT" "هیچ مهاجرت معلقی نیست"
check "مغازه بسته نشد" "$(grep -c 'artisan down' "$LOG")" 0
check "کش ساخته شد" "$(grep -c 'config:cache' "$LOG")" 1
rm -rf "$WORLD"

echo
echo "=== مهاجرت معلق: اجرا می‌شود، پشت درِ بسته ==="
build_world
PENDING=2 MIGRATE=0 && OUT=$(run_deploy); CODE=$?
check "استقرار موفق" "$CODE" 0
contains "مهاجرت‌ها اجرا شدند" "$OUT" "مهاجرت‌ها اجرا شدند"
check "مغازه بسته شد" "$(grep -c 'artisan down' "$LOG")" 1
check "مهاجرت اجرا شد" "$(grep -c 'migrate --force' "$LOG")" 1
check "مغازه دوباره باز شد" "$(grep -c 'artisan up' "$LOG")" 1
rm -rf "$WORLD"

echo
echo "=== مهاجرت شکست می‌خورد: کد برمی‌گردد و مغازه باز می‌شود ==="
build_world
PENDING=1 MIGRATE=1 && OUT=$(run_deploy); CODE=$?
check "استقرار شکست خورد" "$CODE" 1
contains "علتش را می‌گوید" "$OUT" "مهاجرت شکست خورد"
check "کد به قبل برگشت" "$(git -C "$WORLD/app" rev-parse HEAD)" "$BEFORE"
check "مغازه باز شد" "$(grep -c 'artisan up' "$LOG")" 1
# The whole point of the trap: a deploy that dies must not leave the
# bakery closed.
check "کش‌ها بازسازی شدند" "$(grep -c 'config:cache' "$LOG")" 1
rm -rf "$WORLD"

echo
echo "=== سرور بالا نمی‌آید: استقرار موفق اعلام نمی‌شود ==="
build_world
PENDING=0 HEALTH='<html>502 Bad Gateway</html>' && OUT=$(run_deploy); CODE=$?
check "استقرار شکست خورد" "$CODE" 1
contains "می‌گوید مغازه بالا نیامده" "$OUT" "بالا نیامده"
rm -rf "$WORLD"

echo
echo "=== پنل خطا می‌دهد ==="
build_world
PENDING=0 PANEL_CODE=500 && OUT=$(run_deploy); CODE=$?
check "استقرار شکست خورد" "$CODE" 1
rm -rf "$WORLD"

echo
echo "=== پوشهٔ کار کثیف است: چیزی روی کار دست‌نویس کشیده نمی‌شود ==="
build_world
echo "دست‌نویس" >> "$WORLD/app/file"
PENDING=0 && OUT=$(run_deploy); CODE=$?
check "استقرار شکست خورد" "$CODE" 1
contains "علتش را می‌گوید" "$OUT" "تغییرات ثبت‌نشده"
# The point: it stopped before touching anything, so the hand-edit and
# the running code are both still there.
check "چیزی نصب نشد" "$(grep -c composer "$LOG")" 0
check "کشی ساخته نشد" "$(grep -c 'config:cache' "$LOG")" 0
rm -rf "$WORLD"

echo
echo "=== برگرداندن به یک تگ: سرور روی همان تگ می‌ایستد ==="
build_world
# A release that turned out bad, put back to the last good one. The
# workflow's «ref» input is only worth having if the script honours it —
# it deployed main whatever it was told, and the rollback documented in
# docs/DEPLOY-FROM-GITHUB.md would silently have redeployed the bad code.
PENDING=0 && OUT=$( export PATH="$BIN:$PATH" APP="$WORLD/app" LOG="$LOG" \
      LOCK="$WORLD/lock" PENDING=0 MIGRATE=0 HEALTH="" PANEL_CODE=200
    bash "$ROOT/scripts/deploy.sh" v4.88.0 2>&1 ); CODE=$?
check "استقرار موفق" "$CODE" 0
contains "می‌گوید روی main نیست" "$OUT" "نه main"
check "از main جدا شد" "$(git -C "$WORLD/app" rev-parse --abbrev-ref HEAD)" "HEAD"
rm -rf "$WORLD"

echo
echo "=== قفلی که باز نمی‌شود: می‌گوید قفل، نه «استقرار در جریان» ==="
build_world
# What the live server actually did. The lock could not be opened, and
# the script reported that another deploy was running — so the person at
# the terminal went looking for a deploy that did not exist instead of
# reading the one line that would have told them the truth.
# A directory that is not there: the open fails for every user, root
# included, so the test means the same thing in CI as on a workstation.
OUT=$( export PATH="$BIN:$PATH" APP="$WORLD/app" LOG="$LOG" \
      LOCK="$WORLD/nowhere/lock" PENDING=0 MIGRATE=0 HEALTH="" PANEL_CODE=200
    bash "$ROOT/scripts/deploy.sh" 2>&1 ); CODE=$?
check "کد خروج قفل، نه کد رقابت" "$CODE" 4
contains "می‌گوید قفل باز نشد" "$OUT" "باز نشد"
if echo "$OUT" | grep -q "در جریان است"; then
  echo "  رد: خطای قفل را «استقرار در جریان» گزارش کرد"
  FAIL=$((FAIL + 1))
else
  PASS=$((PASS + 1))
fi
check "مغازه بسته نشد" "$(grep -c 'artisan down' "$LOG")" 0
rm -rf "$WORLD"

echo
echo "$PASS قبول، $FAIL رد"
[ "$FAIL" -eq 0 ]
