#!/usr/bin/env bash
# What `checkup.sh` asks the shop, proved rather than assumed.
#
# The one thing this script must never do is write. Two of the commands
# it runs have an `--apply` twin that moves real money between real
# accounts, and the whole safety of them is the flag being absent.
#
# «Absent» is not a thing a reader can check at a glance for ever: the
# next person to touch this file is adding a check, and the line above
# theirs will be one `--apply` away from a command that pays people.
# So it is checked here.
#
# Nothing real is touched. `php` is a stub on PATH that writes down what
# it was asked to run.
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
    echo "      دیدیم:  $2"
  fi
}

# `-e` so a pattern that starts with a dash is a pattern and not a flag.
# Without it `grep -q -- "--apply"` failed with «No such file or
# directory» — and a failing grep is a non-match, so the one assertion
# this whole file exists for was passing without ever looking.
contains() {
  if grep -q -e "$2" "$3"; then
    PASS=$((PASS + 1))
    echo "  ✓ $1"
  else
    FAIL=$((FAIL + 1))
    echo "  ✗ $1"
  fi
}

absent() {
  if grep -q -e "$2" "$3"; then
    FAIL=$((FAIL + 1))
    echo "  ✗ $1"
    echo "      پیدا شد: $(grep -e "$2" "$3")"
  else
    PASS=$((PASS + 1))
    echo "  ✓ $1"
  fi
}

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/app/backend" "$WORK/bin"
LOG="$WORK/artisan.log"

cat > "$WORK/bin/php" <<'STUB'
#!/usr/bin/env bash
# Everything after `artisan`, one line per call.
shift
echo "$*" >> "$ARTISAN_LOG"
exit "${ARTISAN_EXIT:-0}"
STUB
chmod +x "$WORK/bin/php"

export ARTISAN_LOG="$LOG"
export PATH="$WORK/bin:$PATH"

echo "checkup.sh"

# --- the run itself -----------------------------------------------------

: > "$LOG"
APP="$WORK/app" bash "$ROOT/scripts/checkup.sh" > "$WORK/out" 2>&1
check "با همهٔ بررسی‌های سالم، صفر برمی‌گرداند" "$?" "0"

contains "سلامت کلی را می‌پرسد" "shop:health" "$LOG"
contains "انبار را می‌پرسد" "stock:audit" "$LOG"
contains "مساعده‌های روی صندوق را می‌پرسد" "advances:off-the-till" "$LOG"
contains "فیش‌های روی صندوق را می‌پرسد" "salaries:off-the-till" "$LOG"
contains "تسویه‌های با کسری را می‌پرسد" "settlements:forgiven" "$LOG"

# The point of the file.
absent "هیچ‌جا --apply نمی‌زند" "--apply" "$LOG"
absent "هیچ‌وقت cash:put-back نمی‌زند" "cash:put-back" "$LOG"
absent "هیچ‌وقت migrate نمی‌زند" "migrate" "$LOG"
absent "هیچ‌وقت backup نمی‌گیرد" "backup:" "$LOG"

contains "می‌گوید چیزی تغییر نکرده" "چیزی تغییر نکرد" "$WORK/out"
contains "می‌گوید برای انجامش چه بزنند" "--apply" "$WORK/out"

# --- a check that finds something ---------------------------------------

: > "$LOG"
ARTISAN_EXIT=1 APP="$WORK/app" bash "$ROOT/scripts/checkup.sh" > "$WORK/out2" 2>&1
check "وقتی بررسی‌ای چیزی پیدا کند، یک برمی‌گرداند" "$?" "1"

check "با وجود پیدا شدنِ مشکل، همهٔ بررسی‌ها اجرا شدند" "$(wc -l < "$LOG")" "5"

contains "نمی‌گوید «به خطا خورد»" "چیزی پیدا کرد" "$WORK/out2"
absent "مشکلِ مغازه را خرابیِ سیستم نمی‌نامد" "به خطا خورد" "$WORK/out2"

# --- a shop that is not there -------------------------------------------

: > "$LOG"
APP="$WORK/nowhere" bash "$ROOT/scripts/checkup.sh" > "$WORK/out3" 2>&1
check "پوشهٔ اشتباه را می‌گوید و ادامه نمی‌دهد" "$?" "1"
check "و هیچ دستوری نزده" "$(wc -l < "$LOG")" "0"

echo
echo "  $PASS گذشت، $FAIL رد شد"
[ "$FAIL" -eq 0 ]
