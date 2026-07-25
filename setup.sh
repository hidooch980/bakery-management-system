#!/usr/bin/env bash
#
# One-shot local setup for the bakery management system.
# Creates the database, installs backend dependencies, migrates and seeds.
#
# Usage:  ./setup.sh
set -euo pipefail

DB_NAME="${DB_NAME:-bakery_db}"
DB_TEST_NAME="${DB_TEST_NAME:-bakery_db_test}"
DB_USER="${DB_USER:-bakery_user}"
DB_PASS="${DB_PASS:-BakeryPass123!}"

info() { printf '\n\033[1;33m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[1;31mخطا: %s\033[0m\n' "$1" >&2; exit 1; }

command -v php >/dev/null || fail "PHP نصب نیست."
command -v composer >/dev/null || fail "Composer نصب نیست."
command -v mysql >/dev/null || fail "MySQL نصب نیست."

info "ساخت دیتابیس و کاربر"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_TEST_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

cd "$(dirname "$0")/backend"

info "نصب وابستگی‌های Composer"
composer install --no-interaction

if [ ! -f .env ]; then
    info "ساخت فایل .env"
    cp .env.example .env
    php artisan key:generate
    # Fill in the credentials this script just provisioned.
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
fi

info "اجرای migration و seeder"
php artisan migrate:fresh --seed --force

info "پاک‌سازی کش"
php artisan optimize:clear

cat <<'DONE'

✅ راه‌اندازی کامل شد.

اجرای سرور:
    cd backend && php artisan serve --host=0.0.0.0 --port=8000

پنل مدیریت:  http://localhost:8000/admin
API:         http://localhost:8000/api/v1

حساب‌های پیش‌فرض:
    مدیر      admin@bakery.test   Admin@12345
    خمیرگیر   dough@bakery.test   Staff@12345
    چانه‌گیر   chane@bakery.test   Staff@12345
    فروشنده   seller@bakery.test  Staff@12345

⚠️  در محیط عملیاتی حتماً این رمزها را تغییر دهید.
DONE
