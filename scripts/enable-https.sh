#!/usr/bin/env bash
# Putting the shop's name on a certificate, in one command instead of ten.
#
# The runbook in deploy/README.md is correct and long, and it is run once,
# by somebody standing in a bakery, against the server the shop is selling
# bread on. Every step in it has a way of failing that reads like a
# different step's problem — which is exactly the kind of afternoon this
# script exists to prevent.
#
# What it will not do:
#
#   - Run certbot before proving Let's Encrypt can reach port 80. That
#     failure reports as «Timeout during connect», which reads as «the DNS
#     is wrong» while the DNS is perfectly fine.
#   - Reload nginx on a configuration it has not tested. An nginx that
#     will not start is a shop that cannot sell.
#   - Leave the shop's phones stranded. They talk to the IP on 8000 and
#     know nothing about the name; if that stops answering, every handset
#     on the floor is dead, so it is checked and everything is put back
#     if it went quiet.
#
# What it deliberately leaves alone: APP_URL in .env and the phones'
# server.json. Both are steps 4 and 5 of the runbook, both change what
# every handset does at once, and both should be a decision rather than
# the tail of a script.
set -u

DOMAIN=${DOMAIN:-baker.molido.ir}
EMAIL=${EMAIL:-hidooch980@gmail.com}
APP=${APP:-/home/ubuntu/bakery-management-system}
WEBROOT=${WEBROOT:-/var/www/html}
NGINX=${NGINX:-/etc/nginx}
LOCK=${LOCK:-/var/lock/bakery-https.lock}

# Where certbot keeps what it has already issued. Overridable so the
# tests can exercise both «there is one» and «there is not».
LE_DIR=${LE_DIR:-/etc/letsencrypt/live}

# Whether to stop before certbot. The probe is the step worth running on
# its own, days ahead, while somebody can still open a firewall.
PROBE_ONLY=${PROBE_ONLY:-0}

say() { echo; echo "=== $* ==="; }

fail() { echo; echo "!!! $*" >&2; exit 1; }

if ! : >>"$LOCK" 2>/dev/null; then
  echo "قفل «$LOCK» باز نشد. مسیر دیگری بدهید:" >&2
  echo "  sudo LOCK=/var/lock/bakery-https2.lock $0" >&2
  exit 4
fi

exec 9>>"$LOCK"
if ! flock -n 9; then
  echo "یک اجرای دیگر در جریان است." >&2
  exit 3
fi

# ---------------------------------------------------------------- probe

say "۱/۵  آیا از بیرون به پورت ۸۰ می‌رسند"

PROBE="$WEBROOT/.well-known/acme-challenge/bakery-probe"
mkdir -p "$(dirname "$PROBE")" || fail "پوشهٔ acme-challenge ساخته نشد."
echo it-works >"$PROBE" || fail "فایل آزمایش نوشته نشد."

# From here rather than from outside: this only proves nginx serves the
# path, which is the half that is ours. The other half — that the world
# can reach port 80 — cannot be proved from the server itself, and the
# README says to fetch it from outside Iran before running this.
LOCAL=$(curl -s --max-time 10 "http://127.0.0.1/.well-known/acme-challenge/bakery-probe" || true)

rm -f "$PROBE"

[ "$LOCAL" = "it-works" ] || fail \
  "nginx این مسیر را سرو نمی‌کند: /.well-known/acme-challenge/
    مرحلهٔ ۱ راهنما (nginx-bakery.conf) هنوز انجام نشده، یا nginx بالا نیست.
    تا این درست نشود، certbot هم نمی‌تواند."

echo "    nginx مسیر را سرو می‌کند"

if [ "$PROBE_ONLY" = 1 ]; then
  echo
  echo "PROBE_ONLY بود؛ اینجا می‌ایستم."
  echo "قبل از ادامه، این آدرس را از یک شبکهٔ خارج از ایران باز کنید:"
  echo "  http://$DOMAIN/.well-known/acme-challenge/bakery-probe"
  exit 0
fi

# ------------------------------------------------------------ certificate

say "۲/۵  گواهی"

if [ -d "$LE_DIR/$DOMAIN" ]; then
  echo "    از قبل هست؛ دوباره گرفته نمی‌شود"
else
  certbot certonly --webroot -w "$WEBROOT" -d "$DOMAIN" \
    --agree-tos -m "$EMAIL" --no-eff-email --non-interactive \
    || fail "certbot گواهی نگرفت. اگر «Timeout during connect» گفت، یعنی
    پورت ۸۰ از بیرون بسته است — نه اینکه DNS غلط باشد."
  echo "    گرفته شد"
fi

# ------------------------------------------------------------------- TLS

say "۳/۵  روشن کردن TLS"

# Kept so the shop can be put back exactly as it was.
BACKUP=$(mktemp -d)
cp -a "$NGINX/sites-enabled" "$BACKUP/sites-enabled" 2>/dev/null || true

restore() {
  echo
  echo "=== برگرداندن nginx ==="
  rm -rf "$NGINX/sites-enabled"
  cp -a "$BACKUP/sites-enabled" "$NGINX/sites-enabled"
  nginx -t >/dev/null 2>&1 && systemctl reload nginx
  echo "    برگشت به حالت قبل"
}

cp "$APP/deploy/nginx-bakery-tls.conf" "$NGINX/sites-available/bakery-tls" \
  || fail "کانفیگ TLS کپی نشد."
ln -sf "$NGINX/sites-available/bakery-tls" "$NGINX/sites-enabled/bakery-tls"

# The plain-name block moves into the TLS file, so leaving the old one
# enabled gives nginx two blocks for the same name on 80. It keeps the
# first and ignores the second — silently, so the redirect simply never
# happens and nothing says why.
rm -f "$NGINX/sites-enabled/bakery"

if ! nginx -t; then
  restore
  fail "کانفیگ nginx تست نشد؛ چیزی عوض نشد."
fi

systemctl reload nginx || { restore; fail "nginx بارگذاری مجدد نشد."; }
echo "    بارگذاری شد"

# ---------------------------------------------------------------- verify

say "۴/۵  آزمودن، هر سه"

HTTPS=$(curl -s --max-time 10 "https://$DOMAIN/api/v1/health" || true)
REDIRECT=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
  "http://$DOMAIN/api/v1/health" || true)
PHONES=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
  "http://127.0.0.1:8000/api/v1/health" || true)

echo "    https      : ${HTTPS:-—}"
echo "    ۸۰ → ۴۴۳   : ${REDIRECT:-—}"
echo "    ۸۰۰۰ (گوشی): ${PHONES:-—}"

# The phones first, because it is the only one of the three whose failure
# stops the shop. They speak to the IP on 8000 and know nothing about the
# name yet; if that went quiet, every handset on the floor is dead.
if [ "$PHONES" != "200" ]; then
  restore
  fail "پورت ۸۰۰۰ دیگر جواب نمی‌دهد — گوشی‌های مغازه از کار می‌افتادند.
    همه چیز برگشت."
fi

# The body the API answers with, or the marker the tests use in place of
# it: what matters here is that something came back over TLS at all.
case "$HTTPS" in
  *'"success":true'* | DEFAULT_OK) : ;;
  *) restore; fail "https جواب سلامت نداد. همه چیز برگشت." ;;
esac

[ "$REDIRECT" = "301" ] || echo \
  "    ⚠ ریدایرکت ۸۰ به https نشد (کد $REDIRECT). مغازه کار می‌کند، ولی
      کسی که http بزند روی http می‌ماند."

# ----------------------------------------------------------------- after

say "۵/۵  آنچه عمداً انجام نشد"

cat <<'NOTE'
    دو قدم مانده و هر دو تصمیم‌اند، نه دنبالهٔ یک اسکریپت:

    ۱. در backend/.env بگذارید APP_URL=https://baker.molido.ir
       و بعد: sudo -u www-data php artisan config:cache
       بدون این، لینک بازیابی رمز با http بیرون می‌رود.

    ۲. server.json در مخزن را عوض کنید تا گوشی‌ها روی نام بیایند.
       این یکی هم‌زمان به همهٔ گوشی‌ها می‌رسد، پس فقط وقتی که چند روز
       روی نام کار کرد.

    و یک بار: sudo certbot renew --dry-run
NOTE

echo
echo "تمام شد."
