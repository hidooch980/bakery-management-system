#!/usr/bin/env bash
# What the nightly deploy does, and — mostly — what it declines to do.
#
# It runs unattended against a live bakery at an hour when nobody is
# watching, which is the worst possible place to find out what its
# failure paths do. Nothing real is touched here: `git` and `deploy.sh`
# are stubs, and the app directory is a temporary git repository.
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
  case "$2" in
    *"$3"*) PASS=$((PASS + 1)); echo "  ✓ $1" ;;
    *) FAIL=$((FAIL + 1)); echo "  ✗ $1 — «$3» در لاگ نبود" ;;
  esac
}

# A world with an app directory, a stubbed deploy.sh, and a `git fetch`
# that either finds new commits or does not.
#
# NEW=1     origin has moved on
# DEPLOY=n  the exit code scripts/deploy.sh should give
build_world() {
  WORLD=$(mktemp -d)
  BIN=$WORLD/bin
  LOG=$WORLD/deploy.log
  mkdir -p "$BIN" "$WORLD/app/scripts"
  : > "$LOG"

  git -C "$WORLD/app" init -q
  git -C "$WORLD/app" config user.email t@t
  git -C "$WORLD/app" config user.name t
  echo one > "$WORLD/app/file"
  git -C "$WORLD/app" add -A
  git -C "$WORLD/app" commit -qm one

  # The real script is never run: what is under test is the decision to
  # run it, not what it does.
  cat > "$WORLD/app/scripts/deploy.sh" <<STUB
#!/usr/bin/env bash
echo "deploy.sh ran with ref=\$1"
exit \${DEPLOY:-0}
STUB
  chmod +x "$WORLD/app/scripts/deploy.sh"

  cat > "$BIN/git" <<STUB
#!/usr/bin/env bash
GIT=$(command -v git)
if [ "\$1" = fetch ]; then
  if [ "\${NEW:-0}" = 1 ]; then
    cur=\$("\$GIT" -C "$WORLD/app" rev-parse --abbrev-ref HEAD)
    "\$GIT" -C "$WORLD/app" checkout -q -B __incoming
    echo two > "$WORLD/app/file"
    "\$GIT" -C "$WORLD/app" commit -qam two
    "\$GIT" -C "$WORLD/app" rev-parse HEAD > "$WORLD/app/.git/FETCH_HEAD"
    "\$GIT" -C "$WORLD/app" checkout -q "\$cur"
  else
    "\$GIT" -C "$WORLD/app" rev-parse HEAD > "$WORLD/app/.git/FETCH_HEAD"
  fi
  exit \${FETCH:-0}
fi
exec "\$GIT" "\$@"
STUB
  chmod +x "$BIN/git"
}

run_auto() {
  ( export PATH="$BIN:$PATH" APP="$WORLD/app" AUTO_DEPLOY_LOG="$LOG" \
      AUTO_DEPLOY_LOCK="$WORLD/lock" NEW="${NEW:-0}" DEPLOY="${DEPLOY:-0}" \
      FETCH="${FETCH:-0}"
    bash "$ROOT/scripts/auto-deploy.sh" >/dev/null 2>&1 )
}

echo
echo "=== شب معمولی: چیز تازه‌ای نیست ==="
build_world
NEW=0 && run_auto; CODE=$?
check "بی‌سروصدا تمام شد" "$CODE" 0
check "deploy.sh اجرا نشد" "$(grep -c 'deploy.sh ran' "$LOG")" 0
# A log that writes a line every quiet night is a log nobody reads on the
# night something happened.
check "چیزی در لاگ ننوشت" "$(wc -c < "$LOG" | tr -d ' ')" 0
rm -rf "$WORLD"

echo
echo "=== کد تازه هست: مستقر می‌شود ==="
build_world
NEW=1 DEPLOY=0 && run_auto; CODE=$?
check "موفق" "$CODE" 0
check "deploy.sh یک بار اجرا شد" "$(grep -c 'deploy.sh ran' "$LOG")" 1
contains "با ref درست" "$(cat "$LOG")" "ref=main"
contains "گفت چه چیزی عوض شد" "$(cat "$LOG")" "کد تازه"
rm -rf "$WORLD"

echo
echo "=== استقرار شکست می‌خورد: با خطا تمام می‌شود و می‌گوید ==="
build_world
NEW=1 DEPLOY=1 && run_auto; CODE=$?
check "با خطا تمام شد" "$CODE" 1
contains "شکست را نوشت" "$(cat "$LOG")" "استقرار شکست خورد"
# The point: cron must not hammer a broken deploy all night.
contains "گفت دوباره تلاش نمی‌کند" "$(cat "$LOG")" "دوباره تلاش نمی‌شود"
rm -rf "$WORLD"

echo
echo "=== پوشهٔ کار کثیف است: دست نمی‌زند ==="
build_world
echo "دست‌نویس" >> "$WORLD/app/file"
NEW=1 && run_auto; CODE=$?
check "بی‌خطا کنار کشید" "$CODE" 0
check "deploy.sh اجرا نشد" "$(grep -c 'deploy.sh ran' "$LOG")" 0
contains "علتش را نوشت" "$(cat "$LOG")" "تغییرات ثبت‌نشده"
rm -rf "$WORLD"

echo
echo "=== fetch شکست می‌خورد: چیزی مستقر نمی‌شود ==="
build_world
NEW=1 FETCH=1 && run_auto; CODE=$?
check "با خطا تمام شد" "$CODE" 1
check "deploy.sh اجرا نشد" "$(grep -c 'deploy.sh ran' "$LOG")" 0
rm -rf "$WORLD"

echo
echo "=== یک استقرار در جریان است: کنار می‌کشد ==="
build_world
exec 8>"$WORLD/lock"
flock -n 8
NEW=1 && run_auto; CODE=$?
exec 8>&-
check "بی‌خطا کنار کشید" "$CODE" 0
check "deploy.sh اجرا نشد" "$(grep -c 'deploy.sh ran' "$LOG")" 0
rm -rf "$WORLD"

echo
echo "$PASS قبول، $FAIL رد"
[ "$FAIL" -eq 0 ]
