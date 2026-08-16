# سرور نانوایی

سرور اصلی `37.32.21.125` است و بک‌اند را **nginx + php-fpm** سرو می‌کند.

> سرور قدیمی `194.5.176.140` دیگر بخشی از این پروژه نیست. اگر جایی به آن
> برخوردید، کهنه است.

## چرا nginx و نه `php artisan serve`

`artisan serve` سرور توسعه است و **یک درخواست را در لحظه** جواب می‌دهد. با پنج
نفر روی گوشی، بقیه پشت اولی در صف می‌ماندند. اندازه‌گیری روی همین سرور:

| | `artisan serve` | nginx + php-fpm |
|---|---|---|
| ۸ درخواست هم‌زمان | ۶.۲ ثانیه | **۱.۴ ثانیه** |
| صفحهٔ پنل (گرم) | ۰.۹–۱.۸ ثانیه | **۰.۳–۰.۵ ثانیه** |

## نصب روی یک سرور تازه

```bash
sudo apt-get install -y nginx php8.3-fpm
```

فایل [nginx-bakery.conf](nginx-bakery.conf) را در جای خودش بگذارید و فعالش کنید:

```bash
sudo cp deploy/nginx-bakery.conf /etc/nginx/sites-available/bakery && sudo ln -sf /etc/nginx/sites-available/bakery /etc/nginx/sites-enabled/bakery
```

nginx به‌عنوان `www-data` اجرا می‌شود و باید بتواند از مسیر پروژه رد شود.
بدون این، هر درخواست ۵۰۰ می‌دهد و در `/var/log/nginx/error.log` می‌نویسد
`Permission denied`:

```bash
sudo chmod o+x /home/ubuntu /home/ubuntu/bakery-management-system /home/ubuntu/bakery-management-system/backend
```

اجازهٔ نوشتن روی مسیرهایی که لاراول به آن‌ها می‌نویسد:

```bash
sudo usermod -a -G ubuntu www-data && bash deploy/fix-storage-permissions.sh
```

> عضویت گروه با `reload` اعمال نمی‌شود؛ `restart` لازم است.

دو کاربر روی `storage` می‌نویسند: `www-data` که سایت را سرو می‌کند، و کاربر استقرار که `artisan` و بک‌آپ شبانه را اجرا می‌کند. هرکدام زودتر بنویسد مالک فایل می‌شود و دیگری تا ساخته‌شدن فایل بعدی از آن بیرون می‌ماند.

یک `chmod -R g+w` این را فقط تا نیمه‌شب درست می‌کند: لاگ روز بعد دوباره با گروهِ سازنده‌اش ساخته می‌شود و مشکل برمی‌گردد. `fix-storage-permissions.sh` علاوه بر دسترسی، `setgid` را هم روی پوشه‌ها می‌گذارد تا هر فایل تازه گروه پوشه را به ارث ببرد. نیمهٔ دوم کار در `config/logging.php` است (`'permission' => 0664`).

بعد از هر استقراری که فایل تازه می‌سازد، دوباره اجرایش کنید — بی‌خطر است.

```bash
sudo nginx -t && sudo systemctl restart nginx php8.3-fpm && sudo systemctl enable nginx php8.3-fpm
```

## آزمایش

```bash
curl -s http://37.32.21.125/api/v1/health
```

باید `{"success":true,"service":"bakery"}` برگردد.

## برگشت به حالت قبل

`bakery.service` حذف نشده، فقط غیرفعال است. اگر nginx مشکلی داشت:

```bash
sudo systemctl stop nginx && sudo systemctl enable --now bakery.service
```

## پشتیبان‌ها

سرور روزی دو بار نسخه می‌گیرد — بامداد و ظهر (`backup:database`، نگهداری ۶۰
نسخه). آن نسخه‌ها روی **همان دیسکی** هستند که دیتابیس روی آن است، پس اگر آن
دیسک برود همه با هم می‌روند.

[pull-backups.ps1](pull-backups.ps1) روی ویندوز مدیر همین نسخه‌ها را هر شب
ساعت ۹ می‌کشد و در `D:\aziz\backups` نگه می‌دارد. جهت **کشیدن** است نه
فرستادن: سرور به کامپیوتر پشت مودم خانگی دسترسی ندارد.

هر فایل بعد از انتقال باز می‌شود تا از سالم بودنش مطمئن شویم؛ کپی ناقص دور
انداخته می‌شود تا دفعه‌ی بعد دوباره کشیده شود. یک آرشیو خرابِ خوش‌نام، تا روزی
که به آن نیاز باشد بی‌سروصدا سالم به نظر می‌رسد.

اجرای دستی:

```bash
powershell -ExecutionPolicy Bypass -File deploy/pull-backups.ps1
```

آزمودن اینکه یک نسخه واقعاً برمی‌گردد — روی دیتابیس موقت، نه روی `bakery_db`:

```bash
mysql -e 'CREATE DATABASE bakery_restore_check' && gunzip -c backend/storage/app/backups/آخرین.sql.gz | mysql bakery_restore_check
```

> ارسال ایمیلِ پشتیبان با `--no-mail` خاموش است چون رمز SMTP پذیرفته نمی‌شود
> (`535 BadCredentials`). با یک رمز معتبر در `MAIL_PASSWORD` و برداشتن
> `--no-mail` از crontab دوباره کار می‌کند.

## بعد از هر بار فرستادن کد تازه

```bash
cd backend && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:clear && php artisan view:cache
```

php-fpm کد را در opcache نگه می‌دارد، پس بعد از تغییر کد باید تازه شود:

```bash
sudo systemctl reload php8.3-fpm
```
