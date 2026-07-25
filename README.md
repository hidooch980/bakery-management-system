# سیستم مدیریت نانوایی 🥖

سیستم جامع مدیریت نانوایی شامل **بک‌اند Laravel 11**، **پنل مدیریت Filament 3** و **اپلیکیشن موبایل Flutter** با کنترل دسترسی کامل نقش‌محور.

---

## فهرست

- [قابلیت‌ها](#قابلیتها)
- [نقش‌ها و دسترسی‌ها](#نقشها-و-دسترسیها)
- [تکنولوژی‌ها](#تکنولوژیها)
- [ساختار پروژه](#ساختار-پروژه)
- [نصب و راه‌اندازی بک‌اند](#نصب-و-راهاندازی-بکاند)
- [راه‌اندازی اپلیکیشن موبایل](#راهاندازی-اپلیکیشن-موبایل)
- [حساب‌های پیش‌فرض](#حسابهای-پیشفرض)
- [مستندات API](#مستندات-api)
- [اجرای تست‌ها](#اجرای-تستها)
- [عیب‌یابی](#عیبیابی)

---

## قابلیت‌ها

| ماژول | توضیح |
|---|---|
| 🔐 احراز هویت | ورود با ایمیل یا شماره تلفن، توکن Sanctum، خروج، تغییر رمز |
| 👥 مدیریت کاربران | ساخت/ویرایش/حذف/فعال‌سازی حساب — **فقط توسط مدیر** |
| 🏪 اطلاعات نانوایی | نام، آدرس، تلفن، لوگو، توضیحات |
| 🌾 ثبت خمیر | ثبت تعداد کیسه خمیرگیری‌شده + تاریخچه |
| ⚪ ثبت چانه | تعداد چانه + وزن عادی + وزن نانینو + آرد پاششی |
| 💰 ثبت فروش | ۶ نوع پرداخت: نقد، کارتخوان، نسیه، منزل، مدارس، سایر |
| ⏰ حضور و غیاب | تیک حضور کارکنان، ساعت ورود قابل مشاهده برای مدیر |
| 📦 موجودی آرد | ورود/خروج آرد، کسر خودکار آرد پاششی |
| 📊 گزارش‌ها | داشبورد، تولید، فروش، مصرف آرد، راندمان، حضور |
| 🌓 تم روشن/تاریک | در هر دو بخش اپلیکیشن و پنل ادمین |

### زنجیره کاری تولید

```
خمیرگیر              چانه‌گیر                 فروشنده
   │                     │                       │
   ├─ ثبت کیسه خمیر ──► صف «در انتظار چانه»      │
                         │                       │
                         ├─ ثبت چانه ────────► صف «در انتظار فروش»
                         │  (۳ وزن)              │
                         │                       ├─ ثبت فروش
                         └─ کسر خودکار            │  (نوع پرداخت)
                            آرد پاششی            │
                                                 ▼
                                          گزارش‌های مدیر
```

هر مرحله فقط یک‌بار قابل انجام است — سیستم از ثبت تکراری جلوگیری می‌کند (HTTP 409).

---

## نقش‌ها و دسترسی‌ها

| نقش | دسترسی‌ها |
|---|---|
| **مدیر** (`admin`) | همه چیز: مدیریت کاربران، نانوایی، تمام گزارش‌ها، حضور و غیاب، موجودی آرد، پنل Filament |
| **خمیرگیر** (`dough_maker`) | ثبت خمیر، تاریخچه خودش، تیک حضور، تغییر رمز، خروج |
| **چانه‌گیر** (`chane_gir`) | مشاهده خمیرهای در انتظار، ثبت چانه، تاریخچه خودش، تیک حضور، تغییر رمز، خروج |
| **فروشنده** (`seller`) | مشاهده چانه‌های آماده، ثبت فروش، فروش‌های امروز، تیک حضور، تغییر رمز، خروج |

> ⚠️ **هیچ مسیر ثبت‌نام عمومی وجود ندارد.** ساخت حساب فقط از طریق مدیر (API یا پنل Filament) ممکن است.

**۱۴ دسترسی تعریف‌شده:** `manage-users`, `manage-bakery`, `view-all-reports`, `view-attendance-reports`, `record-dough`, `view-own-dough-history`, `view-pending-dough`, `record-chane`, `view-own-chane-history`, `view-pending-chane`, `record-sale`, `view-own-sales`, `record-attendance`, `change-password`

---

## تکنولوژی‌ها

**بک‌اند**
- PHP 8.3 · Laravel 11.6
- Laravel Sanctum 4.3 (احراز هویت توکنی)
- spatie/laravel-permission 6.25 (نقش و دسترسی)
- Filament 3.3 (پنل مدیریت)
- MySQL 8.0

**موبایل**
- Flutter 3.44.8 · Dart 3.12
- provider (مدیریت state) · dio (شبکه)
- flutter_secure_storage (ذخیره امن توکن) · shared_preferences (تنظیمات تم)
- فونت وزیرمتن (bundle شده — بدون نیاز به اینترنت)

---

## ساختار پروژه

```
bakery-management-system/
├── backend/                      # Laravel 11 + Filament 3
│   ├── app/
│   │   ├── Filament/
│   │   │   ├── Pages/            # صفحه تنظیمات نانوایی
│   │   │   ├── Resources/        # ۶ Resource مدیریتی
│   │   │   └── Widgets/          # ۴ ویجت داشبورد
│   │   ├── Http/Controllers/Api/ # ۸ کنترلر API
│   │   ├── Models/               # ۷ مدل
│   │   └── Traits/ApiResponse.php
│   ├── database/
│   │   ├── migrations/           # ۷ migration
│   │   └── seeders/              # نقش‌ها، مدیر، کارکنان نمونه
│   ├── routes/api.php            # ۳۴ مسیر نقش‌محور
│   └── tests/Feature/            # ۲۷ تست
│
├── mobile-app/                   # Flutter
│   ├── lib/
│   │   ├── models/               # AppUser, DoughEntry, ChaneEntry, Sale
│   │   ├── services/             # ApiClient, BakeryApi
│   │   ├── providers/            # AuthProvider, ThemeProvider
│   │   ├── screens/              # صفحات هر نقش
│   │   ├── theme/app_theme.dart  # تم روشن/تاریک
│   │   └── widgets/              # ویجت‌های مشترک
│   ├── assets/fonts/             # وزیرمتن
│   └── test/                     # ۱۵ تست
│
├── docs/API.md                   # مستندات کامل API
└── README.md
```

---

## نصب و راه‌اندازی بک‌اند

### 🚀 راه‌اندازی سریع (یک دستور)

```bash
./setup.sh
```

این اسکریپت دیتابیس، وابستگی‌ها، فایل `.env`، migration و seeder را به‌صورت خودکار آماده می‌کند. برای مراحل دستی، ادامه را بخوانید.

### پیش‌نیازها

```bash
sudo apt-get install -y php php-cli php-mysql php-mbstring php-xml php-bcmath php-curl php-zip php-gd php-intl mysql-server
```

Composer:

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

### ۱. ساخت دیتابیس

```bash
sudo mysql -e "CREATE DATABASE bakery_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'bakery_user'@'localhost' IDENTIFIED BY 'BakeryPass123!'; GRANT ALL PRIVILEGES ON bakery_db.* TO 'bakery_user'@'localhost'; FLUSH PRIVILEGES;"
```

### ۲. نصب وابستگی‌ها

```bash
cd backend && composer install
```

### ۳. تنظیم فایل محیط

```bash
cp .env.example .env && php artisan key:generate
```

سپس در `.env` این مقادیر را تنظیم کنید:

```env
APP_TIMEZONE=Asia/Tehran
APP_LOCALE=fa
DB_CONNECTION=mysql
DB_DATABASE=bakery_db
DB_USERNAME=bakery_user
DB_PASSWORD=BakeryPass123!
```

### ۴. اجرای migration و seeder

```bash
php artisan migrate --seed
```

### ۵. اجرای سرور

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

- **پنل مدیریت:** http://localhost:8000/admin
- **API:** http://localhost:8000/api/v1

---

## راه‌اندازی اپلیکیشن موبایل

### پیش‌نیازها

- Flutter 3.44+ ([نصب](https://docs.flutter.dev/get-started/install))
- Android SDK (API 36) + JDK 17

### نصب و اجرا

```bash
cd mobile-app && flutter pub get
```

اجرا روی امولاتور/دستگاه:

```bash
flutter run
```

### آدرس سرور

به‌صورت پیش‌فرض `http://10.0.2.2:8000/api/v1` (آدرس میزبان از دید امولاتور اندروید).

برای دستگاه واقعی، آدرس سرور را موقع اجرا یا بیلد بدهید:

```bash
flutter run --dart-define=API_BASE_URL=http://192.168.1.10:8000/api/v1
```

### ساخت APK

```bash
flutter build apk --release --dart-define=API_BASE_URL=http://192.168.1.10:8000/api/v1
```

فایل خروجی: `build/app/outputs/flutter-apk/app-release.apk`

برای APK سبک‌تر به تفکیک معماری:

```bash
flutter build apk --split-per-abi --release
```

---

## حساب‌های پیش‌فرض

پس از اجرای `php artisan migrate --seed`:

| نقش | ایمیل | رمز عبور |
|---|---|---|
| مدیر | `admin@bakery.test` | `Admin@12345` |
| خمیرگیر | `dough@bakery.test` | `Staff@12345` |
| چانه‌گیر | `chane@bakery.test` | `Staff@12345` |
| فروشنده | `seller@bakery.test` | `Staff@12345` |

> 🔒 **در محیط عملیاتی حتماً این رمزها را تغییر دهید** و seeder کارکنان نمونه (`DemoStaffSeeder`) را اجرا نکنید.

ورود با شماره تلفن هم پشتیبانی می‌شود (مثلاً `09120000000` برای مدیر).

---

## مستندات API

مستندات کامل با نمونه درخواست/پاسخ: [`docs/API.md`](docs/API.md)

### قالب یکدست پاسخ

موفق:

```json
{
  "success": true,
  "message": "OK",
  "data": { }
}
```

ناموفق:

```json
{
  "success": false,
  "message": "شما دسترسی لازم برای این عملیات را ندارید.",
  "errors": null
}
```

### نمونه سریع

```bash
# ورود
curl -X POST http://localhost:8000/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"login":"dough@bakery.test","password":"Staff@12345"}'

# تیک حضور (با توکن دریافتی)
curl -X POST http://localhost:8000/api/v1/attendance/check-in \
  -H "Authorization: Bearer <TOKEN>"

# ثبت خمیر
curl -X POST http://localhost:8000/api/v1/dough-entries \
  -H "Authorization: Bearer <TOKEN>" \
  -H 'Content-Type: application/json' \
  -d '{"bag_count":12,"note":"شیفت صبح"}'
```

---

## اجرای تست‌ها

### بک‌اند (۲۷ تست)

دیتابیس تست را بسازید:

```bash
sudo mysql -e "CREATE DATABASE bakery_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON bakery_db_test.* TO 'bakery_user'@'localhost'; FLUSH PRIVILEGES;"
```

اجرا:

```bash
cd backend && php artisan test
```

پوشش: بارگذاری تمام صفحات پنل، رد دسترسی کارکنان به پنل، زنجیره کامل تولید، تیک حضور، مرزهای نقش‌ها، تغییر رمز.

### موبایل (۱۵ تست)

```bash
cd mobile-app && flutter test && flutter analyze
```

---

## عیب‌یابی

| مشکل | راه‌حل |
|---|---|
| اپ به سرور وصل نمی‌شود | آدرس `API_BASE_URL` را بررسی کنید. امولاتور اندروید: `10.0.2.2`، نه `localhost` |
| `SQLSTATE[HY000] [1045]` | نام کاربری/رمز دیتابیس در `.env` اشتباه است |
| پنل ادمین ۴۰۳ می‌دهد | فقط کاربران با نقش `admin` و `is_active = true` اجازه ورود دارند |
| بعد از تغییر رمز، ۴۰۱ می‌گیرم | طبیعی است — تغییر رمز همه توکن‌ها را باطل می‌کند، دوباره وارد شوید |
| زمان‌ها اشتباه است | `APP_TIMEZONE=Asia/Tehran` را در `.env` تنظیم و `php artisan config:clear` را اجرا کنید |
| بیلد Gradle خطای شبکه می‌دهد | در مناطق محدودشده، میرور Maven را در `~/.gradle/init.gradle` تنظیم کنید |

---

## نکات امنیتی

- رمزها با bcrypt هش می‌شوند.
- تغییر رمز و غیرفعال‌سازی حساب، **همه توکن‌های فعال** کاربر را باطل می‌کند.
- مسیر ورود با `throttle:10,1` محدود شده است.
- کاربر غیرفعال نه می‌تواند وارد شود و نه توکن قبلی‌اش کار می‌کند.
- مدیر نمی‌تواند حساب خودش را حذف یا غیرفعال کند.
- توکن در اپ داخل `flutter_secure_storage` (Keystore اندروید) ذخیره می‌شود.

---

## لایسنس

MIT
