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
  "normal_weight_kg": 180.5,
  "nanino_weight_kg": 95.25,
  "spray_flour_kg": 6.75
}
```

| فیلد | نوع | الزامی | توضیح |
|---|---|---|---|
| `dough_entry_id` | integer | ✔ | باید خمیر موجود و در وضعیت `pending` باشد |
| `chane_count` | integer | ✔ | تعداد چانه (۱ تا ۱۰۰٬۰۰۰) |
| `normal_weight_kg` | decimal | ✔ | وزن چانه عادی (کیلوگرم) |
| `nanino_weight_kg` | decimal | ✔ | وزن چانه سیستم نانینو (کیلوگرم) |
| `spray_flour_kg` | decimal | ✔ | آرد پاششی مصرف‌شده (کیلوگرم) |

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
2. آرد پاششی به‌صورت خودکار از موجودی آرد کسر می‌شود.

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

`role` یکی از: `admin`, `dough_maker`, `chane_gir`, `seller`
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
