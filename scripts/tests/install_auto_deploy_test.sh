#!/usr/bin/env bash
# What `install-auto-deploy.sh` does to a crontab, proved rather than
# assumed.
#
# It edits the crontab of whoever runs it, on a machine that serves a
# bakery. Two things there are unforgiving: doubling its own line every
# time somebody runs it again, and throwing away a line somebody else put
# there. Neither is visible until a night goes wrong.
#
# Nothing real is touched. `crontab`, `sudo` and `git` are stubs on PATH
# that write down what they were asked, and the script is pointed at a
# temporary directory.
set -u

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PASS=0
FAIL=0

check() {
  if [ "$2" = "$3" ]; then
    PASS=$((PASS + 1)); echo "  ✓ $1"
  else
    FAIL=$((FAIL + 1)); echo "  ✗ $1"; echo "      انتظار: $3"; echo "      واقعی : $2"
  fi
}

contains() {
  if grep -q -e "$2" "$3"; then
    PASS=$((PASS + 1)); echo "  ✓ $1"
  else
    FAIL=$((FAIL + 1)); echo "  ✗ $1"; echo "      در: $(cat "$3")"
  fi
}

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/app/.git" "$WORK/app/scripts" "$WORK/bin"

CRONTAB="$WORK/crontab.txt"
: > "$CRONTAB"

cat > "$WORK/bin/crontab" <<'STUB'
#!/usr/bin/env bash
# -l prints the table; a bare `-` reads a new one from stdin.
if [ "${1:-}" = "-l" ]; then
  [ -s "$CRONTAB_FILE" ] || exit 1
  cat "$CRONTAB_FILE"
  exit 0
fi
cat > "$CRONTAB_FILE"
STUB

cat > "$WORK/bin/sudo" <<'STUB'
#!/usr/bin/env bash
"$@"
STUB

# The deploy itself is somebody else's test; here it only has to be
# seen to run, or not.
cat > "$WORK/app/scripts/deploy.sh" <<'STUB'
#!/usr/bin/env bash
echo "DEPLOYED" >> "$DEPLOY_LOG"
exit "${DEPLOY_EXIT:-0}"
STUB

cat > "$WORK/bin/git" <<'STUB'
#!/usr/bin/env bash
echo "abc1234"
STUB

chmod +x "$WORK/bin/"* "$WORK/app/scripts/deploy.sh"

export CRONTAB_FILE="$CRONTAB"
export DEPLOY_LOG="$WORK/deploy.log"
export PATH="$WORK/bin:$PATH"

run() {
  APP="$WORK/app" \
  AUTO_DEPLOY_LOG="$WORK/auto.log" \
  bash "$ROOT/scripts/install-auto-deploy.sh" "$@" > "$WORK/out" 2>&1
}

echo "install-auto-deploy.sh"

# --- a first install ----------------------------------------------------

: > "$DEPLOY_LOG"
run --no-deploy
check "با --no-deploy موفق تمام می‌شود" "$?" "0"

contains "خط شبانه را می‌نویسد" "auto-deploy.sh" "$CRONTAB"
contains "ساعت ۳ بامداد" "^0 3 \* \* \*" "$CRONTAB"
check "و چیزی مستقر نمی‌کند" "$(wc -l < "$DEPLOY_LOG")" "0"

# --- run it again -------------------------------------------------------

run --no-deploy
check "اجرای دوباره موفق است" "$?" "0"
check "خطش دو تا نمی‌شود" "$(grep -c 'auto-deploy.sh' "$CRONTAB")" "1"

# --- somebody else's cron line -----------------------------------------

printf '%s\n' "30 2 * * * /usr/bin/certbot renew" > "$CRONTAB"
printf '%s\n' "0 3 * * * /bin/bash $WORK/app/scripts/auto-deploy.sh" >> "$CRONTAB"

run --no-deploy
check "کارِ کسِ دیگر را نگه می‌دارد" "$(grep -c 'certbot' "$CRONTAB")" "1"
check "و خط خودش همچنان یکی است" "$(grep -c 'auto-deploy.sh' "$CRONTAB")" "1"

# --- the deploy ---------------------------------------------------------

: > "$DEPLOY_LOG"
run
check "بدون گزینه، مستقر هم می‌کند" "$?" "0"
check "یک بار، نه بیشتر" "$(grep -c DEPLOYED "$DEPLOY_LOG")" "1"
contains "پیش از بستن مغازه هشدار می‌دهد" "مغازه بسته می‌شود" "$WORK/out"

# --- a deploy that fails ------------------------------------------------

: > "$DEPLOY_LOG"
DEPLOY_EXIT=1 run
check "استقرارِ شکست‌خورده را پنهان نمی‌کند" "$?" "1"
contains "و می‌گوید امشب دوباره تلاش می‌شود" "امشب دوباره" "$WORK/out"
check "ولی شبانه سرِ جایش می‌ماند" "$(grep -c 'auto-deploy.sh' "$CRONTAB")" "1"

# --- the wrong folder ---------------------------------------------------

APP="$WORK/nowhere" bash "$ROOT/scripts/install-auto-deploy.sh" --no-deploy > "$WORK/out3" 2>&1
check "پوشهٔ اشتباه را می‌گوید و ادامه نمی‌دهد" "$?" "1"

echo
echo "  $PASS گذشت، $FAIL رد شد"
[ "$FAIL" -eq 0 ]
