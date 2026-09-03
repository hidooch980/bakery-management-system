# مستندات API — سیستم مدیریت نانوایی

**نسخه:** v1
**آدرس پایه:** `http://<host>:8000/api/v1`
**احراز هویت:** Laravel Sanctum (Bearer Token)
**منطقه زمانی:** `Asia/Tehran`

---

## قالب پاسخ

تمام پاسخ‌ها از یک ساختار یکسان پیروی می‌کنند.

**موفق:**

```json
{
  "success": true,
  "message": "OK",
  "data": { }
}
```

**ناموفق:**

```json
{
  "success": false,
  "message": "پیام خطا به فارسی",
  "errors": { "field": ["جزئیات خطا"] }
}
```

### کدهای وضعیت

| کد | معنی |
|---|---|
| `200` | موفق |
| `201` | با موفقیت ساخته شد |
| `401` | احراز هویت نشده (توکن نامعتبر یا ارسال‌نشده) |
| `403` | دسترسی ندارید / حساب غیرفعال |
| `404` | یافت نشد |
| `409` | تداخل (مثلاً ثبت تکراری) |
| `422` | خطای اعتبارسنجی |
| `429` | تعداد درخواست بیش از حد مجاز |

### هدرها

```
Accept: application/json
Content-Type: application/json
Authorization: Bearer <TOKEN>     # برای همه مسیرها به‌جز /login
```

---

## ۱. احراز هویت

### `POST /login` 🔓 عمومی

ورود با ایمیل یا شماره تلفن. محدودیت: ۱۰ درخواست در دقیقه.

**بدنه:**

```json
{ "login": "dough@bakery.test", "password": "Staff@12345" }
```

**پاسخ ۲۰۰:**

```json
{
  "success": true,
  "message": "ورود موفقیت‌آمیز بود.",
  "data": {
    "token": "1|abcdef...",
    "user": {
      "id": 2,
      "name": "رضا خمیرگیر",
      "email": "dough@bakery.test",
      "phone": "09121111111",
      "is_active": true,
      "roles": ["dough_maker"],
      "permissions": ["record-dough", "view-own-dough-history", "record-attendance", "change-password"]
    }
  }
}
```

**خطاها:** `422` اطلاعات ورود نادرست · `403` حساب غیرفعال

> ورود موفق، توکن‌های قبلی همان کاربر را باطل می‌کند (یک نشست فعال در هر لحظه).

---

### `GET /me` 🔒 همه

اطلاعات کاربر جاری به‌همراه نقش و دسترسی‌ها.

---

### `POST /logout` 🔒 همه

توکن جاری را باطل می‌کند.

---

### `POST /change-password` 🔒 `change-password`

```json
{
  "current_password": "Staff@12345",
  "new_password": "NewPass@123",
  "new_password_confirmation": "NewPass@123"
}
```

**پاسخ ۲۰۰:** `"رمز عبور با موفقیت تغییر کرد. لطفاً دوباره وارد شوید."`

> ⚠️ **همه** توکن‌های کاربر باطل می‌شوند — باید دوباره وارد شود.

**خطاها:** `422` رمز فعلی نادرست یا رمز جدید کمتر از ۸ کاراکتر

---

## ۲. حضور و غیاب

### `POST /attendance/check-in` 🔒 `record-attendance`

ثبت تیک حضور امروز. **ساعت ثبت‌شده برای مدیر در گزارش حضور قابل مشاهده است.**

بدون بدنه.

**پاسخ ۲۰۱:**

```json
{
  "success": true,
  "message": "تیک حضور ثبت شد.",
  "data": { "id": 5, "date": "2026-07-25", "checked_in_at": "2026-07-25 06:12:44" }
}
```

**خطای ۴۰۹:** `"حضور شما برای امروز قبلاً ثبت شده است."`

---

### `GET /attendance/today` 🔒 `record-attendance`

```json
{ "data": { "checked_in": true, "checked_in_at": "2026-07-25 06:12:44" } }
```

---

### `GET /attendance/my-history` 🔒 `record-attendance`

تاریخچه حضور خود کاربر (صفحه‌بندی‌شده، ۳۰ رکورد در هر صفحه).

---

## ۳. خمیر

### `POST /dough-entries` 🔒 `record-dough` — خمیرگیر

```json
{ "bag_count": 12, "note": "شیفت صبح" }
```

| فیلد | نوع | الزامی | محدوده |
|---|---|---|---|
| `bag_count` | integer | ✔ | ۱ تا ۱۰۰۰ |
| `note` | string | ✖ | حداکثر ۵۰۰ کاراکتر |

**پاسخ ۲۰۱:** رکورد ساخته‌شده با `status: "pending"`

---

### `GET /dough-entries/my-history` 🔒 `view-own-dough-history` — خمیرگیر

تاریخچه ثبت‌های خود کاربر (۲۰ رکورد در هر صفحه).

---

### `GET /dough-entries/pending` 🔒 `view-pending-dough` — چانه‌گیر

خمیرهایی که هنوز چانه نشده‌اند، به‌همراه نام خمیرگیر.

---

## ۴. چانه

### `POST /chane-entries` 🔒 `record-chane` — چانه‌گیر

```json
{
  "dough_entry_id": 1,
  "chane_count": 420,
  "nanino_chane_count": 60,
  "spray_flour_kg": 6.75
}
```

| فیلد | نوع | الزامی | توضیح |
|---|---|---|---|
| `dough_entry_id` | integer | ✔ | باید خمیر موجود و در وضعیت `pending` باشد |
| `chane_count` | integer | ✔ | تعداد چانه عادی (۱ تا ۱۰۰٬۰۰۰) |
| `nanino_chane_count` | integer | ✖ | تعداد چانه نانینو |
| `spray_flour_kg` | decimal | ✔ | آرد پاششی مصرف‌شده (کیلوگرم) |

> ⚠️ **وزن‌ها پذیرفته نمی‌شوند.** سرور آن‌ها را از فرمول نانوایی محاسبه می‌کند
> (`تعداد × وزن هر چانه`). اگر وزن چانه در تنظیمات تعریف نشده باشد، پاسخ `422` است.

**پاسخ ۲۰۱:**

```json
{
  "success": true,
  "message": "ثبت چانه انجام شد.",
  "data": {
    "entry": { "id": 3, "chane_count": 420, "status": "pending", "...": "..." },
    "total_weight_kg": 275.75
  }
}
```

**عوارض جانبی (در یک تراکنش):**
1. خمیر مرتبط به `processed` تغییر می‌کند.
2. آرد پاششی از موجودی آرد کسر می‌شود.
3. معادل وزن چانه از موجودی خمیر کسر می‌شود.

**خطای ۴۰۹:** `"برای این خمیر قبلاً چانه ثبت شده است."`

---

### `GET /chane-entries/my-history` 🔒 `view-own-chane-history` — چانه‌گیر

### `GET /chane-entries/pending` 🔒 `view-pending-chane` — فروشنده

چانه‌های آماده فروش، به‌همراه نام چانه‌گیر و اطلاعات خمیر.

---

## ۵. فروش

### `POST /sales` 🔒 `record-sale` — فروشنده

```json
{
  "chane_entry_id": 3,
  "payment_type": "card",
  "amount": 850000,
  "note": "مشتری ثابت"
}
```

| فیلد | نوع | الزامی |
|---|---|---|
| `chane_entry_id` | integer | ✔ |
| `payment_type` | enum | ✔ |
| `amount` | decimal | ✖ |
| `note` | string | ✖ |

**انواع پرداخت:**

| مقدار API | برچسب |
|---|---|
| `cash` | نقد |
| `card` | کارتخوان |
| `credit` | نسیه |
| `home` | منزل |
| `schools` | مدارس |
| `other` | سایر |

**عارضه جانبی:** چانه مرتبط به `sold` تغییر می‌کند.

**خطاها:** `409` این چانه قبلاً فروخته شده · `422` نوع پرداخت نامعتبر

---

### `GET /sales/today` 🔒 `view-own-sales` — فروشنده

```json
{
  "data": {
    "sales": [ ],
    "summary": {
      "count": 3,
      "total_amount": 2550000,
      "by_payment_type": { "card": 2, "cash": 1 }
    }
  }
}
```

---

### `GET /sales/payment-types` 🔒 همه

فهرست مقادیر مجاز نوع پرداخت.

---

## ۶. مدیریت کاربران — فقط مدیر

تمام مسیرهای این بخش نیازمند دسترسی `manage-users` هستند.

> **هیچ مسیر ثبت‌نام عمومی وجود ندارد.** این تنها راه ساخت حساب است.

### `GET /users`

پارامتر اختیاری: `?role=seller`

### `POST /users`

```json
{
  "name": "کارمند جدید",
  "email": "new@bakery.test",
  "phone": "09129999999",
  "password": "SecurePass123",
  "role": "seller"
}
```

`role` یکی از: `admin`, `dough_maker`, `chane_gir`, `shater`, `seller`
`password` حداقل ۸ کاراکتر.

### `GET /users/{id}`

### `PUT /users/{id}`

همه فیلدها اختیاری‌اند: `name`, `email`, `phone`, `password`, `is_active`, `role`

### `DELETE /users/{id}`

**خطای ۴۲۲:** مدیر نمی‌تواند حساب خودش را حذف کند.

### `PATCH /users/{id}/toggle-active`

فعال/غیرفعال کردن حساب. با غیرفعال شدن، همه توکن‌های کاربر باطل می‌شوند.

**خطای ۴۲۲:** مدیر نمی‌تواند حساب خودش را غیرفعال کند.

### `GET /users/roles`

فهرست نقش‌های موجود.

---

## ۷. اطلاعات نانوایی

### `GET /bakery` 🔒 همه

قابل خواندن توسط همه کاربران احراز هویت‌شده تا اپ بتواند نام و لوگو را نمایش دهد.

**پاسخ ۲۰۰:**

```json
{
  "data": {
    "name": "نانوایی سنتی",
    "address": "تهران، خیابان ...",
    "phone": "02155555555",
    "normal_chane_weight_kg": "0.430",
    "nanino_chane_weight_kg": "0.380",
    "bread_price": "3000.00"
  }
}
```

### `PUT /bakery` 🔒 `manage-bakery` — مدیر

```json
{
  "name": "نانوایی سنتی",
  "address": "تهران، خیابان ...",
  "phone": "02155555555",
  "description": "...",
  "normal_chane_weight_kg": 0.430,
  "nanino_chane_weight_kg": 0.380,
  "bread_price": 3000
}
```

**تعاریف تولید و قیمت:**

| فیلد | نوع | محدوده | کاربرد |
|---|---|---|---|
| `normal_chane_weight_kg` | decimal | ۰ تا ۱۰۰ | وزن **هر یک** چانه عادی — اپ وزن کل را از `تعداد × این مقدار` پیش‌پر می‌کند |
| `nanino_chane_weight_kg` | decimal | ۰ تا ۱۰۰ | وزن **هر یک** چانه نانینو — همان محاسبه |
| `bread_price` | decimal | ۰ تا ۱۰۰٬۰۰۰٬۰۰۰ | قیمت **هر نان** — اپ مبلغ فروش را از `تعداد چانه × این مقدار` پیشنهاد می‌دهد |

> هر سه فیلد اختیاری‌اند. اگر تنظیم نشوند، فرم‌های اپ خالی می‌مانند و کاربر دستی وارد می‌کند.

---

## ۸. موجودی آرد — مدیر

نیازمند `view-all-reports`.

### `GET /flour/balance`

```json
{ "data": { "total_in_kg": 500, "total_out_kg": 6.75, "balance_kg": 493.25 } }
```

### `GET /flour/movements`

### `POST /flour/movements`

```json
{ "type": "in", "amount_kg": 500, "note": "خرید آرد" }
```

`type` یکی از `in` (ورود) یا `out` (خروج).

---

## ۹. گزارش‌ها — مدیر

نیازمند `view-all-reports` (به‌جز گزارش حضور که `view-attendance-reports` می‌خواهد).

همه گزارش‌ها پارامترهای اختیاری `?from=YYYY-MM-DD&to=YYYY-MM-DD` می‌پذیرند. پیش‌فرض: امروز.

### `GET /reports/dashboard`

```json
{
  "data": {
    "today": {
      "dough_bags": 12,
      "chane_count": 420,
      "sales_count": 1,
      "sales_amount": 850000,
      "attendance_count": 3
    },
    "queues": { "pending_dough": 0, "pending_chane": 0 },
    "staff": { "total": 4, "active": 4 },
    "flour_balance_kg": 493.25
  }
}
```

### `GET /reports/production`

مجموع کیسه خمیر، تعداد چانه، وزن عادی، وزن نانینو، آرد پاششی.

### `GET /reports/sales`

تفکیک بر اساس نوع پرداخت و بر اساس فروشنده.

### `GET /reports/flour`

ورود، خروج، آرد پاششی و موجودی فعلی.

### `GET /reports/efficiency`

```json
{
  "data": {
    "total_bags": 12,
    "total_chane": 420,
    "total_weight_kg": 275.75,
    "chane_per_bag": 35.0,
    "weight_per_bag_kg": 22.98
  }
}
```

### `GET /reports/attendance` 🔒 `view-attendance-reports`

**اینجا مدیر ساعت دقیق تیک حضور هر کارمند را می‌بیند.**

پارامتر اختیاری اضافی: `?user_id=2`

```json
{
  "data": {
    "data": [
      {
        "id": 5,
        "date": "2026-07-25",
        "checked_in_at": "2026-07-25T06:12:44.000000Z",
        "user": { "id": 2, "name": "رضا خمیرگیر" }
      }
    ],
    "total": 3
  }
}
```

---

## جدول کامل مسیرها

| متد | مسیر | دسترسی لازم | نقش |
|---|---|---|---|
| POST | `/login` | — | عمومی |
| GET | `/me` | احراز هویت | همه |
| POST | `/logout` | احراز هویت | همه |
| POST | `/change-password` | `change-password` | همه |
| GET | `/bakery` | احراز هویت | همه |
| PUT | `/bakery` | `manage-bakery` | مدیر |
| POST | `/attendance/check-in` | `record-attendance` | کارکنان |
| GET | `/attendance/today` | `record-attendance` | کارکنان |
| GET | `/attendance/my-history` | `record-attendance` | کارکنان |
| POST | `/dough-entries` | `record-dough` | خمیرگیر |
| GET | `/dough-entries/my-history` | `view-own-dough-history` | خمیرگیر |
| GET | `/dough-entries/pending` | `view-pending-dough` | چانه‌گیر |
| POST | `/chane-entries` | `record-chane` | چانه‌گیر |
| GET | `/chane-entries/my-history` | `view-own-chane-history` | چانه‌گیر |
| GET | `/chane-entries/pending` | `view-pending-chane` | فروشنده |
| POST | `/sales` | `record-sale` | فروشنده |
| GET | `/sales/today` | `view-own-sales` | فروشنده |
| GET | `/sales/payment-types` | احراز هویت | همه |
| GET | `/users` | `manage-users` | مدیر |
| POST | `/users` | `manage-users` | مدیر |
| GET | `/users/{id}` | `manage-users` | مدیر |
| PUT | `/users/{id}` | `manage-users` | مدیر |
| DELETE | `/users/{id}` | `manage-users` | مدیر |
| PATCH | `/users/{id}/toggle-active` | `manage-users` | مدیر |
| GET | `/users/roles` | `manage-users` | مدیر |
| GET | `/flour/balance` | `view-all-reports` | مدیر |
| GET | `/flour/movements` | `view-all-reports` | مدیر |
| POST | `/flour/movements` | `view-all-reports` | مدیر |
| GET | `/reports/dashboard` | `view-all-reports` | مدیر |
| GET | `/reports/production` | `view-all-reports` | مدیر |
| GET | `/reports/sales` | `view-all-reports` | مدیر |
| GET | `/reports/flour` | `view-all-reports` | مدیر |
| GET | `/reports/efficiency` | `view-all-reports` | مدیر |
| GET | `/reports/attendance` | `view-attendance-reports` | مدیر |

---

## ۱۰. امور مالی — مدیر

نیازمند دسترسی `manage-finance`.

> تمام تاریخ‌ها **شمسی** پذیرفته و برگردانده می‌شوند (`۱۴۰۵/۰۵/۰۳` — ارقام فارسی یا لاتین).
> تمام مبالغ **به تومان** ذخیره و ارسال می‌شوند؛ فیلد `*_formatted` مقدار را با واحد تنظیم‌شده نمایش می‌دهد.

### `GET /expenses`

پارامترهای اختیاری: `?category=fuel&from=1405/05/01&to=1405/05/31`

### `POST /expenses`

```json
{
  "category": "flour",
  "title": "خرید ۱۰ کیسه آرد",
  "amount": 5000000,
  "spent_on": "1405/05/03",
  "note": "از تعاونی"
}
```

**دسته‌بندی‌ها:**

| مقدار | برچسب |
|---|---|
| `flour` | خرید آرد |
| `fuel` | سوخت |
| `utilities` | آب، برق، گاز |
| `rent` | اجاره |
| `maintenance` | تعمیرات |
| `salary` | حقوق کارکنان |
| `other` | سایر |

### `PUT /expenses/{id}` · `DELETE /expenses/{id}` · `GET /expenses/categories`

---

## ۱۱. حقوق کارکنان — مدیر

### `GET /salaries`

پارامترهای اختیاری: `?user_id=2&status=unpaid`

### `POST /salaries`

```json
{
  "user_id": 2,
  "period_start": "1405/05/01",
  "base_amount": 10000000,
  "bonus": 2000000,
  "deduction": 500000,
  "paid_on": "1405/05/28"
}
```

> **`net_amount` هرگز از ورودی خوانده نمی‌شود** — همیشه `پایه + پاداش − کسورات` محاسبه می‌شود.

**خطای ۴۰۹:** برای این کارمند در این دوره قبلاً حقوق ثبت شده است.

### `PATCH /salaries/{id}/mark-paid`

ثبت پرداخت با تاریخ امروز. اگر قبلاً پرداخت شده باشد `409` برمی‌گرداند.

### `PUT /salaries/{id}` · `DELETE /salaries/{id}` · `GET /salaries/employees`

### `GET /salaries/mine` 🔒 همه کاربران

فیش‌های حقوقی خود کاربر — بدون نیاز به دسترسی مالی.

---

## ۱۲. گزارش‌های مالی — مدیر

نیازمند `view-financial-reports`. همه `?from=` و `?to=` شمسی می‌پذیرند.

### `GET /reports/financial`

```json
{
  "data": {
    "from_jalali": "1405/05/01",
    "to_jalali": "1405/05/31",
    "currency_label": "تومان",
    "income": { "sales": 45000000, "sales_formatted": "45,000,000 تومان", "sales_count": 120 },
    "expenses": {
      "recorded": 12000000,
      "salaries_paid": 20000000,
      "total": 32000000,
      "total_formatted": "32,000,000 تومان",
      "by_category": [
        { "category": "flour", "label": "خرید آرد", "amount": 10000000, "count": 3 }
      ]
    },
    "profit": {
      "amount": 13000000,
      "formatted": "13,000,000 تومان",
      "is_positive": true,
      "margin_percent": 28.9
    },
    "outstanding_salaries": { "amount": 5000000, "count": 1 }
  }
}
```

### `GET /reports/financial-trend`

سری روزانه درآمد، هزینه و سود (حداکثر ۱۲۰ روز).

### `GET /reports/payroll`

جمع حقوق دوره به تفکیک کارمند، با تفکیک پرداخت‌شده و پرداخت‌نشده.

---

## ۱۳. واحد پول

`GET /bakery` فیلد `currency` را برمی‌گرداند (`toman` یا `rial`).
`PUT /bakery` آن را می‌پذیرد.

مبالغ همیشه به تومان ذخیره می‌شوند؛ ریال یعنی نمایش ده برابر. اپلیکیشن ورودی کاربر را قبل از ارسال به تومان تبدیل می‌کند.

---

## ۱۴. فرمول تولید

`GET /bakery` علاوه بر تنظیمات، فرمول را هم برمی‌گرداند:

```json
{
  "data": {
    "flour_bag_weight_kg": "40.000",
    "water_ratio": "0.600",
    "salt_ratio": "0.0150",
    "dough_loss_ratio": "0.0000",
    "calendar": "jalali",
    "calendar_label": "شمسی (هجری خورشیدی)",
    "formula": {
      "flour_bag_weight_kg": 40,
      "normal_chane_weight_kg": 0.85,
      "per_bag": {
        "flour_kg": 40,
        "water_kg": 24,
        "salt_kg": 0.6,
        "dough_kg": 64.6,
        "normal_chane_count": 76
      }
    }
  }
}
```

`PUT /bakery` این فیلدها را می‌پذیرد: `flour_bag_weight_kg`, `water_ratio`, `salt_ratio`, `dough_loss_ratio`, `calendar`.

### ⚠️ تغییر در ثبت چانه

`POST /chane-entries` دیگر وزن نمی‌پذیرد:

```json
{
  "dough_entry_id": 1,
  "chane_count": 300,
  "nanino_chane_count": 60,
  "spray_flour_kg": 4.5
}
```

وزن‌ها سمت سرور از فرمول محاسبه می‌شوند. اگر وزن چانه در تنظیمات تعریف نشده باشد، پاسخ `422` است.

---

## ۱۵. انبار

نیازمند `view-inventory` برای خواندن و `manage-inventory` برای نوشتن.

### `GET /inventory`

```json
{
  "data": [
    { "key": "flour", "name": "آرد", "unit": "kg", "balance": 420.0, "is_low": false },
    { "key": "salt",  "name": "نمک", "unit": "kg", "balance": 48.8,  "is_low": false },
    { "key": "dough", "name": "خمیر", "unit": "kg", "balance": 129.2, "is_low": false }
  ]
}
```

موجودی از دفتر گردش محاسبه می‌شود، نه یک ستون ذخیره‌شده.

### `GET /inventory/movements` · `POST /inventory/movements`

```json
{ "item": "flour", "direction": "in", "quantity": 500, "reason": "purchase" }
```

`reason` یکی از: `manual`, `purchase`, `production`, `spray`, `waste`, `consignment_in`, `consignment_out`

### `PATCH /inventory/{key}/threshold`

تعیین حد هشدار موجودی.

---

## ۱۶. سهمیه آرد

### `GET /flour-allocations/current`

سهمیه بازه امروز با مصرف هر دوره:

```json
{
  "data": {
    "month_label": "مرداد 1405",
    "total_kg": 3000,
    "current_period_number": 1,
    "periods": [
      {
        "number": 1,
        "label": "دوره اول (۵ تا ۱۴)",
        "starts_on_display": "1405/05/05",
        "ends_on_display": "1405/05/14",
        "allocated_kg": 1000,
        "used_kg": 420,
        "remaining_kg": 580,
        "usage_percent": 42.0,
        "is_over": false,
        "is_current": true
      }
    ]
  }
}
```

### `POST /flour-allocations`

```json
{ "month_start": "1405/05/01", "total_kg": 3000 }
```

سه دوره خودکار ساخته می‌شوند و باقیمانده گرد کردن به دوره سوم می‌رود.

---

## ۱۷. آرد امانی

### `GET /consignment-flour/balance`

```json
{ "data": { "borrowed_kg": 200, "lent_kg": 50, "net_kg": -150 } }
```

`net_kg` منفی یعنی به همکار بدهکارید.

### `POST /consignment-flour`

```json
{ "partner_name": "نانوایی رضایی", "direction": "borrowed", "amount_kg": 200 }
```

`direction` یکی از `borrowed` (دریافتی) یا `lent` (تحویلی). موجودی انبار خودکار به‌روز می‌شود.

### `PATCH /consignment-flour/{id}/settle`

---

## ۱۸. مشتریان (مدارس و ادارات)

### `GET /customers` 🔒 همه

فروشنده برای انتخاب مشتری هنگام فروش به آن نیاز دارد.

### `POST /customers` 🔒 `manage-customers`

```json
{ "name": "دبستان شهید بهشتی", "type": "school", "phone": "05433333333" }
```

`type` یکی از `school`, `office`, `other`.

---

## ۱۹. تابلوی تولید (شاطر)

### `GET /chane-board` 🔒 `view-chane-board`

```json
{
  "data": {
    "waiting": { "chane_count": 420, "batches": 3 },
    "today": {
      "normal_count": 300,
      "nanino_count": 100,
      "normal_share_percent": 75.0,
      "nanino_share_percent": 25.0
    },
    "queues": { "pending_dough_batches": 2, "pending_dough_bags": 24 }
  }
}
```

شاطر، چانه‌گیر و فروشنده به این مسیر دسترسی دارند.

---

## ۲۰. تغییر در ثبت فروش

`POST /sales` دو فیلد جدید می‌پذیرد:

```json
{
  "chane_entry_id": 3,
  "payment_type": "schools",
  "bread_count": 250,
  "customer_id": 4,
  "amount": 750000
}
```

| فیلد | توضیح |
|---|---|
| `bread_count` | تعداد نان؛ اگر ارسال نشود، تعداد چانه همان دسته در نظر گرفته می‌شود |
| `customer_id` | **برای `schools` و `credit` الزامی است** |

---

## ۲۱. تعطیلات

### `GET /holidays` 🔒 همه · `GET /holidays/today` · `GET /holidays/types`

پارامترهای اختیاری: `?this_month=1&upcoming=1`

### `POST /holidays` 🔒 `manage-bakery`

```json
{
  "date": "1405/05/10",
  "title": "تعطیلی هفتگی نانوایی",
  "type": "shop",
  "repeats_monthly": true
}
```

| نوع | تکرار ماهانه |
|---|---|
| `official` (تعطیل رسمی) | ✖ |
| `religious` (مناسبت مذهبی) | ✖ |
| `shop` (تعطیلی نانوایی) | ✔ |

> تعطیلات رسمی و مذهبی هر سال جابه‌جا می‌شوند، پس فقط تعطیلی نانوایی تکرار می‌شود. با فعال بودن تکرار، همان روز در ۱۲ ماه آینده ساخته می‌شود؛ روزهایی که در ماهی وجود ندارند رد می‌شوند و روزی که قبلاً ثبت شده بازنویسی نمی‌شود.

### `GET /reports/attendance-summary` 🔒 `view-attendance-reports`

روزهای کاری = کل روزها منهای تعطیلات، پس یک روز تعطیل غیبت محسوب نمی‌شود.

---

## ۲۲. آرد سنوات و تراز نانینو

`POST /flour-allocations` دو فیلد اضافه می‌پذیرد:

```json
{
  "month_start": "1405/05/01",
  "total_bags": 75,
  "carryover_bags": 20,
  "carryover_note": "مانده سنوات ۱۴۰۴"
}
```

پاسخ شامل:

| فیلد | معنی |
|---|---|
| `total_bags` / `total_kg` | سهمیه خود ماه |
| `carryover_bags` / `carryover_kg` | مانده از دوره‌های قبل |
| `available_bags` / `available_kg` | جمع هر دو — کل قابل استفاده |
| `bag_weight_kg` | وزن کیسه که مبنای تبدیل است |

> سنوات بین سه دوره تقسیم **نمی‌شود** — ذخیره‌ای است که هر زمان قابل برداشت است.

هر دوره این فیلدها را هم دارد:

| فیلد | معنی |
|---|---|
| `nanino_chane_count` | چانه نانینوی ثبت‌شده در بازه دوره |
| `nanino_flour_kg` | آردی که آن تولید حساب می‌کند (فرمول برعکس) |
| `nanino_balance_kg` | سهمیه منهای آن — مثبت یعنی سهمیه بیشتر بوده |

---

## ۲۳. بدهی‌ها

فروش با نوع پرداخت `credit` یا `schools` تا زمان وصول بدهی است.

### `GET /reports/debts` 🔒 `view-financial-reports`

```json
{
  "data": {
    "month_label": "مرداد 1405",
    "total": { "amount": 800000, "formatted": "800,000 تومان", "count": 2 },
    "this_month": { "amount": 500000, "count": 1 },
    "previous_months": {
      "amount": 300000,
      "count": 1,
      "oldest_date": "1405/04/12"
    },
    "by_customer": [
      { "customer": "دبستان الف", "amount": 350000, "oldest_date": "1405/04/12" }
    ]
  }
}
```

بدهی ماه‌های قبل جدا گزارش می‌شود چون پیگیری متفاوتی می‌خواهد. `by_customer` بر اساس مبلغ مرتب است، بزرگ‌ترین بدهکار اول.

**ثبت تسویه:** فیلد `settled_on` در `PUT /sales/{id}` یا از پنل با دکمه «تسویه».

---

## ۲۴. انبار — نمایش کیسه

`GET /inventory` برای آرد فیلد `balance_bags` هم برمی‌گرداند (برای نمک و خمیر `null` است، چون کیسه‌ای نیستند).

---

## ۲۵. طرف‌حساب‌ها

`Customer` حالا همکاران نانوایی را هم پوشش می‌دهد:

| `type` | برچسب | کاربرد |
|---|---|---|
| `school` | مدرسه | خریدار |
| `office` | اداره | خریدار |
| `partner` | همکار / نانوایی | آرد امانی |
| `other` | سایر | خریدار |

`GET /customers?buyers_only=1` فقط خریداران و `?partners_only=1` فقط همکاران را برمی‌گرداند.

`POST /consignment-flour` حالا `customer_id` می‌پذیرد؛ `partner_name` فقط وقتی لازم است که همکار تعریف‌نشده باشد.

---

## ۲۶. خرید و تأمین‌کنندگان

یک محموله تا امروز سه رکورد بی‌ارتباط می‌شد: یک هزینه با دستهٔ «خرید آرد»
برای پول، یک ورودی انبار برای کیسه‌ها، و یک نرخ آرد برای قیمت. هیچ‌کدام به
هم وصل نبودند، هیچ‌جا نمی‌گفت از چه کسی خریده شده، و «به کارخانه چقدر
بدهکاریم» جوابی در سیستم نداشت.

حالا یک فاکتور یک رکورد است: ردیف‌هایش انبار را پر می‌کنند، آنچه دم در
پرداخت شده از حساب بانکی کم می‌شود، و تفاوتش بدهی‌ای است با نام روی آن.
ردیف آرد، نرخ خرید همان روز را هم ثبت می‌کند — همان نرخی که بهای تمام‌شدهٔ
نان از آن خوانده می‌شود.

**دستهٔ هزینهٔ «خرید آرد»، «خرید نمک» و «خرید خمیر» بازنشسته شدند:** دیگر
پیشنهاد نمی‌شوند و روی رکورد جدید رد می‌شوند، اما روی رکوردهای قدیمی هنوز
پذیرفته می‌شوند و همچنان در سود و زیان شمرده می‌شوند.

دو دسترسی، چون دو کارند:

| دسترسی | چه کسی | چه کاری |
|---|---|---|
| `record-purchase` | مدیر، فروشنده | نوشتن محموله‌ای که تازه رسیده |
| `manage-purchases` | مدیر | حساب با تأمین‌کننده، پرداخت، اصلاح فاکتور |

### `POST /purchases` 🔒 `record-purchase`

```json
{
  "supplier_id": 3,
  "supplier_name": "کارخانه آرد زاهدان",
  "invoice_no": "A-1",
  "purchased_on": "1405/06/12",
  "paid_amount": 40000000,
  "paid_in_cash": false,
  "items": [
    { "item": "flour", "bags": 20, "unit_price": 200000 },
    { "title": "حمل", "amount": 15000000 }
  ]
}
```

| فیلد | نوع | لازم | توضیح |
|---|---|---|---|
| `supplier_id` | int | ✔٭ | یکی از این دو لازم است |
| `supplier_name` | string | ✔٭ | اگر تأمین‌کننده هنوز تعریف نشده، با این نام ساخته می‌شود |
| `invoice_no` | string | — | شمارهٔ خودِ کارخانه |
| `purchased_on` | string | — | شمسی یا میلادی؛ پیش‌فرض امروز |
| `paid_amount` | numeric | — | آنچه دم در پرداخت شد؛ باقی‌مانده بدهی می‌ماند |
| `bank_account_id` | int | — | پیش‌فرض حساب اصلی؛ `paid_in_cash` آن را پاک می‌کند |
| `items[]` | array | ✔ | دست‌کم یک ردیف |

هر ردیف یا کالا دارد یا عنوان:

| فیلد | توضیح |
|---|---|
| `item` | `flour` / `salt` / `yeast_dry` — ردیفی که به انبار می‌رود |
| `title` | عنوان ردیفی که کالا ندارد — حمل، تخلیه |
| `bags` | برای کالای کیسه‌ای؛ وزن از روی اندازهٔ کیسه حساب می‌شود |
| `quantity_kg` | برای کالایی که کیسهٔ ثابت ندارد |
| `unit_price` | نرخ هر کیلو؛ مبلغ ردیف از آن حساب می‌شود |
| `amount` | مبلغ ردیف؛ اگر نرخ داده نشود، نرخ از آن حساب می‌شود |

ردیفی که نه کالا دارد نه عنوان، یا مقدار دارد و مبلغ ندارد، با ۴۲۲ رد
می‌شود. کالایی که پول همراهش نیست، امانت است نه خرید — و امانت جای خودش
را دارد (`/consignment-flour`).

### بقیهٔ مسیرها

| متد | مسیر | دسترسی | توضیح |
|---|---|---|---|
| GET | `/purchases/options` | `record-purchase` | تأمین‌کننده‌ها، کالاها با اندازهٔ کیسه، و حساب‌ها — یک تماس |
| GET | `/purchases/mine` | `record-purchase` | آنچه خودِ این نفر ثبت کرده |
| GET | `/suppliers` | `record-purchase` | فهرست انتخاب؛ `?all=1` غیرفعال‌ها را هم می‌آورد |
| GET | `/purchases` | `manage-purchases` | `?supplier_id=`، `?from=`، `?to=` |
| GET | `/purchases/{id}` | `manage-purchases` | با ردیف‌ها |
| PUT | `/purchases/{id}` | `manage-purchases` | ردیف‌های ارسالی جای قبلی‌ها را می‌گیرند؛ انبار و بانک دنبالش می‌آیند |
| DELETE | `/purchases/{id}` | `manage-purchases` | کالا و پول برمی‌گردد؛ اگر پرداختی ثبت شده باشد ۴۰۹ |
| GET | `/suppliers/balances` | `manage-purchases` | هرکه تسویه نیست، و جمع بدهی |
| GET | `/suppliers/{id}/account` | `manage-purchases` | فاکتورها و پرداخت‌های یک تأمین‌کننده |
| POST | `/suppliers` | `manage-purchases` | |
| PUT | `/suppliers/{id}` | `manage-purchases` | `is_active` هم اینجاست |
| DELETE | `/suppliers/{id}` | `manage-purchases` | فقط تا وقتی سابقه ندارد؛ وگرنه ۴۰۹ |
| GET | `/supplier-payments` | `manage-purchases` | `?supplier_id=` |
| POST | `/supplier-payments` | `manage-purchases` | `purchase_id` اختیاری — خالی یعنی علی‌الحساب |
| DELETE | `/supplier-payments/{id}` | `manage-purchases` | گردش بانکی‌اش هم برمی‌گردد |
