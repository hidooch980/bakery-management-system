#!/usr/bin/env bash
# What `enable-https.sh` does when things go wrong, proved rather than
# assumed.
#
# It runs once, by somebody standing in a bakery, against the server the
# shop is selling bread on. Its worst failure is not «TLS did not turn
# on» — it is «the phones stopped answering and nobody noticed until the
# morning», so that is the path with the most tests here.
#
# Nothing real is touched: curl, certbot, nginx, systemctl and flock are
# stubs on PATH that answer whatever the case under test needs.
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

build_world() {
  WORLD=$(mktemp -d)
  BIN="$WORLD/bin"
  mkdir -p "$BIN" "$WORLD/webroot" "$WORLD/nginx/sites-available" \
    "$WORLD/nginx/sites-enabled" "$WORLD/app/deploy"

  echo "tls config" >"$WORLD/app/deploy/nginx-bakery-tls.conf"
  echo "plain config" >"$WORLD/nginx/sites-enabled/bakery"

  cat >"$BIN/curl" <<'STUB'
#!/usr/bin/env bash
# Which answer to give is decided per URL by the case under test.
url=${!#}
case "$url" in
  *acme-challenge*) printf '%s' "${PROBE_ANSWER:-it-works}" ;;
  https://*) printf '%s' "${HTTPS_ANSWER-DEFAULT_OK}" ;;
  *:8000/*) printf '%s' "${PHONES_ANSWER:-200}" ;;
  http://*) printf '%s' "${REDIRECT_ANSWER:-301}" ;;
esac
STUB

  cat >"$BIN/certbot" <<STUB
#!/usr/bin/env bash
echo "certbot \$*" >>"$WORLD/log"
exit \${CERTBOT_EXIT-0}
STUB

  cat >"$BIN/nginx" <<STUB
#!/usr/bin/env bash
echo "nginx \$*" >>"$WORLD/log"
exit \${NGINX_TEST_EXIT-0}
STUB

  cat >"$BIN/systemctl" <<STUB
#!/usr/bin/env bash
echo "systemctl \$*" >>"$WORLD/log"
STUB

  chmod +x "$BIN"/*
  : >"$WORLD/log"
}

run() {
  ( export PATH="$BIN:$PATH" APP="$WORLD/app" WEBROOT="$WORLD/webroot" \
      NGINX="$WORLD/nginx" LOCK="$WORLD/lock" LE_DIR="$WORLD/letsencrypt"
    export PROBE_ANSWER HTTPS_ANSWER PHONES_ANSWER REDIRECT_ANSWER
    export CERTBOT_EXIT NGINX_TEST_EXIT PROBE_ONLY
    bash "$ROOT/scripts/enable-https.sh" 2>&1 )
}

# Defaults for one case, reset between them so a value cannot leak.
reset_case() {
  PROBE_ANSWER=it-works
  HTTPS_ANSWER=DEFAULT_OK
  PHONES_ANSWER=200
  REDIRECT_ANSWER=301
  CERTBOT_EXIT=0
  NGINX_TEST_EXIT=0
  PROBE_ONLY=0
}

echo
echo "=== مسیر سالم: TLS روشن می‌شود و گوشی‌ها سر جایشان می‌مانند ==="
build_world
reset_case
OUT=$(run); CODE=$?
check "موفق" "$CODE" 0
contains "certbot صدا زده شد" "$(cat "$WORLD/log")" "certbot certonly"
check "کانفیگ TLS فعال شد" "$([ -L "$WORLD/nginx/sites-enabled/bakery-tls" ] && echo yes)" "yes"
check "بلوک تکراری ۸۰ برداشته شد" "$([ -e "$WORLD/nginx/sites-enabled/bakery" ] || echo gone)" "gone"
contains "می‌گوید چه چیزی عمداً نشد" "$OUT" "عمداً انجام نشد"
rm -rf "$WORLD"

echo
echo "=== گواهی از قبل هست: دوباره گرفته نمی‌شود ==="
build_world
reset_case
mkdir -p "$WORLD/letsencrypt/baker.molido.ir"
OUT=$(run); CODE=$?
check "موفق" "$CODE" 0
check "certbot صدا زده نشد" "$(grep -c certbot "$WORLD/log")" 0
contains "می‌گوید از قبل هست" "$OUT" "از قبل هست"
rm -rf "$WORLD"

echo
echo "=== nginx مسیر acme را سرو نمی‌کند: قبل از certbot می‌ایستد ==="
build_world
reset_case
PROBE_ANSWER="404 not found" OUT=$(run); CODE=$?
check "شکست خورد" "$CODE" 1
contains "علتش را می‌گوید" "$OUT" "acme-challenge"
check "certbot اصلاً صدا زده نشد" "$(grep -c certbot "$WORLD/log")" 0
rm -rf "$WORLD"

echo
echo "=== گوشی‌ها بعدش جواب ندادند: همه چیز برمی‌گردد ==="
build_world
reset_case
# The one failure that stops the shop. The handsets talk to the IP on
# 8000 and know nothing about the name; if that goes quiet, every phone
# on the floor is dead and TLS is not worth it.
PHONES_ANSWER="000" OUT=$(run); CODE=$?
check "شکست خورد" "$CODE" 1
contains "گوشی‌ها را نام می‌برد" "$OUT" "گوشی‌های مغازه"
check "کانفیگ قدیمی برگشت" "$(cat "$WORLD/nginx/sites-enabled/bakery")" "plain config"
contains "nginx دوباره بارگذاری شد" "$(cat "$WORLD/log")" "systemctl reload"
rm -rf "$WORLD"

echo
echo "=== کانفیگ nginx تست نشد: هیچ بارگذاری‌ای انجام نمی‌شود ==="
build_world
reset_case
NGINX_TEST_EXIT=1 OUT=$(run); CODE=$?
check "شکست خورد" "$CODE" 1
contains "می‌گوید چیزی عوض نشد" "$OUT" "چیزی عوض نشد"
rm -rf "$WORLD"

echo
echo "=== https جواب سلامت نداد: برمی‌گردد ==="
build_world
reset_case
HTTPS_ANSWER="" OUT=$(run); CODE=$?
check "شکست خورد" "$CODE" 1
check "کانفیگ قدیمی برگشت" "$(cat "$WORLD/nginx/sites-enabled/bakery")" "plain config"
rm -rf "$WORLD"

echo
echo "=== ریدایرکت نشد: هشدار می‌دهد ولی مغازه را نمی‌خواباند ==="
build_world
reset_case
REDIRECT_ANSWER="200" OUT=$(run); CODE=$?
check "موفق" "$CODE" 0
contains "هشدار می‌دهد" "$OUT" "ریدایرکت"
rm -rf "$WORLD"

echo
echo "=== PROBE_ONLY: قبل از certbot می‌ایستد ==="
build_world
reset_case
PROBE_ONLY=1 OUT=$(run); CODE=$?
check "موفق" "$CODE" 0
check "certbot صدا زده نشد" "$(grep -c certbot "$WORLD/log")" 0
contains "آدرس آزمایش را می‌دهد" "$OUT" "خارج از ایران"
rm -rf "$WORLD"

echo
echo "$PASS قبول، $FAIL رد"
[ "$FAIL" -eq 0 ]
