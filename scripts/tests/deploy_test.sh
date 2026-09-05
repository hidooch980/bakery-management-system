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
  cat > "$BIN/git" <<STUB
#!/usr/bin/env bash
if [ "\$1" = pull ]; then
  echo two > "$WORLD/app/file"
  $(command -v git) -C "$WORLD/app" commit -qam two
  exit 0
fi
exec $(command -v git) "\$@"
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
echo "$PASS قبول، $FAIL رد"
[ "$FAIL" -eq 0 ]
