# اتصال Power BI به سیستم نانوایی

Power BI مستقیم به اپ وصل نمی‌شود؛ به یک منبع داده وصل می‌شود. سیستم برای همین
کار یک API خروجی دارد که هر رکورد را در یک سطر تخت می‌دهد، همراه با تاریخ شمسی،
تا گزارش را بتوان روی همان تقویمی برش زد که نانوایی با آن کار می‌کند.

---

## ۱. ساخت توکن

خروجی با همان توکن Sanctum و همان دسترسی‌های پنل کار می‌کند. کاربر باید دسترسی
`view-financial-reports` داشته باشد (نقش مدیر).

```bash
curl -X POST https://YOUR-SERVER/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"login":"admin@bakery.local","password":"YOUR-PASSWORD"}'
```

`data.token` را از پاسخ بردارید و جایی امن نگه دارید. این توکن مثل رمز عبور است.

---

## ۲. اتصال در Power BI Desktop

**Get Data → Web → Advanced**

- URL: `https://YOUR-SERVER/api/v1/reports/export/sales`
- HTTP request header parameters:
  - `Authorization` = `Bearer YOUR-TOKEN`
  - `Accept` = `application/json`

بعد در Power Query:

```
= Json.Document(Web.Contents("https://YOUR-SERVER/api/v1/reports/export/sales",
    [Headers=[Authorization="Bearer YOUR-TOKEN", Accept="application/json"]]))
```

سپس `data` → `rows` → **To Table** → **Expand**.

> برای رفرش خودکار روی Power BI Service، توکن را در Data source credentials از
> نوع **Anonymous** بگذارید و هدر را داخل خود کوئری نگه دارید، یا از
> **Web.Contents با RelativePath** استفاده کنید تا سرویس آدرس پایه را بشناسد.

---

## ۳. مجموعه‌های داده

همه با `GET /api/v1/reports/export/{dataset}` و به‌صورت پیش‌فرض یک سال اخیر.
با `?from=1405/01/01&to=1405/12/29` بازه را عوض کنید (شمسی یا میلادی، هر دو).

| dataset | یک سطر برای هر | ستون‌های کلیدی |
|---|---|---|
| `sales` | فروش نان | `date_jalali`، `payment_type`، `payment_label`، `bread_count`، `amount`، `seller`، `customer` |
| `expenses` | هزینه | `date_jalali`، `category_label`، `amount` |
| `income` | فروش آرد و درآمد متفرقه | `date_jalali`، `source_label`، `amount` |
| `production` | خمیرگیری | `date_jalali`، `bag_count`، `chane_count`، `normal_weight_kg`، `nanino_weight_kg` |
| `inventory` | گردش انبار | `date_jalali`، `item`، `reason_label`، `quantity`، `signed_quantity` |
| `salaries` | فیش حقوقی | `employee`، `period_start_jalali`، `net_amount`، `paid` |

`signed_quantity` در `inventory` ورودی را مثبت و خروجی را منفی می‌دهد، تا در
Power BI بدون نوشتن measure بشود جمعش زد.

پاسخ اگر به سقف ۲۰٬۰۰۰ سطر بخورد، `truncated: true` برمی‌گرداند — در آن صورت
بازه را کوچک‌تر بگیرید تا رفرش بعدی بی‌سروصدا عددها را کم نشان ندهد.

---

## ۴. گزارش‌های آمادهٔ روزانه/هفتگی/ماهانه

اگر نمی‌خواهید مدل‌سازی کنید، دو اندپوینت هست که خودشان جمع‌بندی می‌کنند.
پارامتر `granularity` یکی از `day`، `week` یا `month` است. هفته شنبه تا جمعه و
ماه، ماه شمسی است.

**درآمد و هزینه**

```
GET /api/v1/reports/financial-series?from=1405/05/01&to=1405/05/31&granularity=week
```

هر سطر: `income`، `income_bread`، `income_flour`، `income_other`، `expense`،
`expense_recorded`، `expense_salaries`، `profit`.

**مصارف**

```
GET /api/v1/reports/consumption-series?from=1405/05/01&to=1405/05/31&granularity=day
```

هر سطر: `bags_kneaded`، `flour_production_kg`، `flour_spray_kg`،
`flour_used_kg`، `flour_sold_kg`، `salt_kg`، `yeast_dry_kg`، `yeast_wet_kg`.

آرد فقط دو جور مصرف می‌شود — خمیرگیری و پاششی — و `flour_used_kg` جمع همان دو
است. آردی که فروخته یا امانی داده شده در `flour_sold_kg` جدا گزارش می‌شود، چون
نان نشده و نباید از سهمیه کم شود.

---

## ۵. اتصال مستقیم به دیتابیس (جایگزین)

اگر مدل‌سازی خودتان را می‌خواهید، Power BI با کانکتور MySQL هم وصل می‌شود. یک
کاربر فقط‌خواندنی بسازید:

```sql
CREATE USER 'powerbi'@'%' IDENTIFIED BY 'STRONG-PASSWORD';
GRANT SELECT ON bakery_db.* TO 'powerbi'@'%';
FLUSH PRIVILEGES;
```

در این حالت تاریخ‌ها میلادی‌اند و تبدیل شمسی و برچسب‌های فارسی را باید خودتان در
Power BI بسازید — کاری که API خروجی از قبل انجام داده است.
