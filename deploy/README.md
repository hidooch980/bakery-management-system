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

> **کران با `sudo -u www-data` اجرا می‌شود.** دو بار مرد و هر دو بار
> بی‌صدا: ۱۴۰۵/۰۶/۰۸ تا ۱۰ چون مالکیت *پوشهٔ* dump به `www-data:www-data`
> رفته بود؛ و باز در ۱۴۰۵/۰۶/۱۰ چون آن یکی درست شده بود ولی
> `storage/logs/backup.log` — جایی که خط cron ریدایرکت می‌کند — نه.
> اجرا به‌عنوان `www-data` هر دو فایل را یک طرف مجوز می‌برد:
>
> ```bash
> 0 2 * * * sudo -u www-data sh -c "cd /home/ubuntu/bakery-management-system/backend && HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan backup:database --keep=60 >> storage/logs/backup.log 2>&1"
> ```
>
> پوشهٔ dump هم گروه `ubuntu` و بیت `setgid` دارد، تا پشتیبان‌کش ویندوز
> که با `ubuntu` وصل می‌شود بتواند فایل‌ها را بخواند:
>
> ```bash
> sudo chgrp -R ubuntu backend/storage/app/backups && sudo chmod -R g+w backend/storage/app/backups && sudo chmod g+s backend/storage/app/backups
> ```
>
> **نبودِ خطا یعنی نبودِ پشتیبان هم می‌تواند باشد.** تاریخ تازه‌ترین فایل را
> نگاه کنید، نه اینکه دستور به‌ظاهر سالم است.

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

> **`composer install --no-dev` دیگر مغازه را نمی‌خواباند.** ۱۴۰۵/۰۶/۱۰
> می‌خواباند: `filament/filament` در `require-dev` نشسته بود در حالی که کل
> پنل روی آن سوار است، و `--no-dev` حذفش می‌کرد. حالا در `require` است.
>
> قفل فقط دوباره دسته‌بندی شد، نه به‌روز: ۲۳ بسته از `packages-dev` به
> `packages` رفتند و **نسخهٔ هیچ بسته‌ای عوض نشد**. اگر روزی لازم شد
> دوباره این کار را بکنید، تأییدش این است که `composer install --no-dev` در
> یک کپی اجرا شود و `php artisan route:list` مسیرهای `admin` را بدهد — نه
> اینکه composer خطا نداد.

> **artisan را با کاربر `www-data` اجرا کنید، نه `ubuntu`.** فایل‌های
> `storage/logs` مال `www-data` هستند و `ubuntu` عضو گروهش نیست، پس هر
> دستوری که چیزی لاگ کند با «could not be opened in append mode» می‌ایستد —
> از جمله `package:discover` که خودِ composer صدایش می‌زند. psysh هم برای
> `tinker` جای نوشتن می‌خواهد:
>
> ```bash
> sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker
> ```

> **یک خط cron دو فایل دارد، نه یکی.** جایی که دستور می‌نویسد، و جایی
> که *خروجی‌اش* می‌رود. پشتیبان‌گیری ۱۴۰۵/۰۶/۱۰ دو روز مرده بود چون
> مالکیت *پوشهٔ* dump درست شده بود ولی خط cron به `>> storage/logs/backup.log`
> ختم می‌شد و آن فایل هنوز مال `www-data` بود. **شل ریدایرکت را قبل از
> اجرای `php` باز می‌کند**، پس کل دستور همان‌جا می‌مرد: نه dump، نه لاگ،
> نه خطا. کار را همان‌طور که cron اجرا می‌کند تست کنید — با `env -i` و با
> همان ریدایرکت — نه در شل لاگین خودتان، که پاس می‌شود در حالی که
> مسیر واقعی خراب است.

php-fpm کد را در opcache نگه می‌دارد، پس بعد از تغییر کد باید تازه شود:

```bash
sudo systemctl reload php8.3-fpm
```
