# انتقال سرور بدون قطعی اپ

سرور فعلی `185.97.119.91` است و مقصد `194.5.176.140`. این سند ترتیبی را
می‌نویسد که در آن هیچ گوشی‌ای بی‌سرور نمی‌ماند.

**نکتهٔ اصلی را اول بخوانید:** اپ‌هایی که همین حالا روی گوشی‌ها نصب‌اند،
آدرس سرور قدیمی را داخل خودشان دارند و [server.json](../server.json) را
نمی‌خوانند. پس **قبل از خاموش‌کردن سرور قدیمی، همه باید یک‌بار اپ را آپدیت
کنند**. از آپدیت به بعد، انتقال سرور فقط ویرایش همان یک فایل است.

بخش آپدیت اپ از GitHub می‌خواند نه از سرور شما، پس حتی وقتی سرور قدیمی
خاموش شود، اپِ نصب‌شده باز هم می‌تواند نسخهٔ جدید را بگیرد.

---

## آنچه روی سرور فعلی بالاست

| مورد | مقدار |
|---|---|
| سرویس | `bakery.service` — `php artisan serve --host=0.0.0.0 --port=8000` |
| PHP | 8.3 |
| دیتابیس | MySQL، `bakery_db`، کاربر `bakery_user` |
| زمان‌بند | یک خط cron که هر دقیقه `schedule:run` را اجرا می‌کند (پشتیبان شبانه) |
| مسیر | `/home/ubuntu/bakery-management-system` |

---

## گام ۱ — پشتیبان از سرور قدیمی

روی سرور قدیمی:

```bash
mysqldump -u bakery_user -p bakery_db > ~/bakery-$(date +%F).sql
```

فایل‌های آپلودشده (لوگو و پیوست‌ها) هم باید بروند:

```bash
tar czf ~/bakery-storage-$(date +%F).tar.gz -C /home/ubuntu/bakery-management-system/backend storage/app
```

هر دو فایل را روی سرور جدید بفرستید:

```bash
scp ~/bakery-*.sql ~/bakery-storage-*.tar.gz root@194.5.176.140:~/
```

## گام ۲ — آماده‌سازی سرور جدید

```bash
sudo apt update && sudo apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-bcmath php8.3-curl php8.3-zip php8.3-gd php8.3-intl mysql-server git unzip
```

Composer و کد:

```bash
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

```bash
git clone https://github.com/hidooch980/bakery-management-system.git /home/ubuntu/bakery-management-system
```

```bash
cd /home/ubuntu/bakery-management-system/backend && composer install --no-dev --optimize-autoloader
```

## گام ۳ — دیتابیس

```bash
sudo mysql -e "CREATE DATABASE bakery_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'bakery_user'@'localhost' IDENTIFIED BY 'همان-رمز-سرور-قدیمی'; GRANT ALL PRIVILEGES ON bakery_db.* TO 'bakery_user'@'localhost'; FLUSH PRIVILEGES;"
```

بازگردانی پشتیبان — این جای `migrate` را می‌گیرد، چون داده‌ها با ساختارشان
می‌آیند:

```bash
mysql -u bakery_user -p bakery_db < ~/bakery-*.sql
```

## گام ۴ — تنظیمات

`.env` را از سرور قدیمی کپی کنید (یا از `.env.example` بسازید) و فقط این را
عوض کنید:

```
APP_URL=http://194.5.176.140:8000
```

> **`APP_KEY` را حتماً همان مقدار سرور قدیمی بگذارید.** با کلید متفاوت،
> هر چیزی که رمزنگاری‌شده ذخیره شده باز نمی‌شود و کاربران باید دوباره وارد
> شوند.

فایل‌های آپلودشده:

```bash
tar xzf ~/bakery-storage-*.tar.gz -C /home/ubuntu/bakery-management-system/backend
```

مهاجرت‌های تازه (اگر از زمان پشتیبان چیزی اضافه شده) و کش:

```bash
cd /home/ubuntu/bakery-management-system/backend && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## گام ۵ — سرویس و زمان‌بند

فایل `/etc/systemd/system/bakery.service` را عیناً از سرور قدیمی کپی کنید،
سپس:

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now bakery.service && sudo systemctl status bakery.service --no-pager
```

زمان‌بند لاراول:

```bash
( crontab -l 2>/dev/null; echo "* * * * * cd /home/ubuntu/bakery-management-system/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1" ) | crontab -
```

پورت ۸۰۰۰ باید از بیرون باز باشد:

```bash
sudo ufw allow 8000/tcp
```

## گام ۶ — آزمایش سرور جدید

از هر جایی:

```bash
curl -s http://194.5.176.140:8000/api/v1/health
```

باید `{"success":true,"service":"bakery"}` برگردد. تا وقتی این جواب نداده،
از گام بعد جلوتر نروید.

## گام ۷ — انتشار APK تازه

۱. در GitHub، متغیر مخزن `API_BASE_URL` را روی
`http://194.5.176.140:8000/api/v1` بگذارید
(Settings ← Secrets and variables ← Actions ← Variables).

۲. workflow «Build & Release APK» را اجرا کنید.

۳. **به همهٔ کاربران بگویید اپ را آپدیت کنند.** از نسخهٔ فعلی به بعد، اپ
خودش پس از ورود یک‌بار بررسی می‌کند و اگر نسخهٔ تازه‌ای باشد پیشنهاد نصب
می‌دهد؛ کاربر فقط «بروزرسانی» را می‌زند. کسی که این پیام را رد کند، از
تنظیمات ← بررسی بروزرسانی هم می‌تواند اقدام کند.

> این پیشنهاد خودکار در نسخه‌های **قبلی** نیست. کاربرانی که هنوز نسخهٔ
> قدیمی دارند باید دستی از تنظیمات آپدیت کنند، پس یک اطلاع‌رسانی تلفنی یا
> پیامکی برای همین یک‌بار لازم است.

## گام ۸ — بیست‌وچهار ساعت هر دو روشن

[server.json](../server.json) الان هر دو آدرس را دارد:

```json
{
  "api_base_url": "http://194.5.176.140:8000/api/v1",
  "fallback_urls": ["http://185.97.119.91:8000/api/v1"]
}
```

اپ آدرس‌ها را به ترتیب امتحان می‌کند و اولی را که به `/health` جواب بدهد
برمی‌دارد. گوشی‌ای که روی سرور قدیمی است تا وقتی جواب می‌دهد همان‌جا می‌ماند
و وسط روز جابه‌جا نمی‌شود.

**در این ۲۴ ساعت هر دو سرور روی یک دیتابیس نیستند.** هر ثبتی که روی سرور
قدیمی انجام شود در سرور جدید نیست. دو راه:

- **ساده و مطمئن:** سرور قدیمی را در همان گام ۸ فقط-خواندنی کنید یا خاموش
  کنید، تا همه فوراً روی جدید ثبت کنند. ۲۴ ساعت فقط برای این است که کسی
  پیام خطا نبیند.
- **اگر خاموشی ممکن نیست:** پایان ۲۴ ساعت یک `mysqldump` دیگر از سرور قدیمی
  بگیرید و رکوردهای همان بازه را دستی منتقل کنید.

## گام ۹ — بستن پرونده

سرور قدیمی را از `server.json` بردارید:

```json
{
  "api_base_url": "http://194.5.176.140:8000/api/v1",
  "fallback_urls": []
}
```

کامیت و پوش کنید. گوشی‌هایی که هنوز روی سرور قدیمی بودند، دفعهٔ بعد که اپ را
باز کنند خودشان منتقل می‌شوند. حالا سرور قدیمی را خاموش کنید.

---

## انتقال‌های بعدی

فقط `server.json` را عوض کنید و پوش بزنید. نه بیلد جدیدی لازم است، نه نصبی
روی گوشی‌ها.
