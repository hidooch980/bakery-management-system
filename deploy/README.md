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
sudo mkdir -p /etc/nginx/snippets && sudo cp deploy/snippets/bakery-app.conf /etc/nginx/snippets/
sudo cp deploy/nginx-bakery.conf /etc/nginx/sites-available/bakery && sudo ln -sf /etc/nginx/sites-available/bakery /etc/nginx/sites-enabled/bakery
```

> کانفیگ سه بلوک `server` دارد که همه‌شان یک چیز را سرو می‌کنند، پس آنچه
> مشترک است در `snippets/bakery-app.conf` نشسته. بدون کپی‌کردن آن، nginx
> با `include` ناموجود بالا نمی‌آید.

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

## HTTPS

**این کار انجام شده.** مغازه از ۱۴۰۵/۰۶/۰۳ روی `baker.molido.shop` گواهی
واقعی Let's Encrypt دارد، با آروان جلویش. آنچه پایین می‌آید توضیح وضعیت
موجود است، نه دستورالعملی برای اجرا.

> **هشدار، و دلیل بازنویسی این بخش.** تا ۱۴۰۵/۰۶/۲۲ اینجا راهنمای نصب
> روی `baker.molido.ir` نوشته بود، با فایلی که هیچ TLSی نداشت. مغازه
> ولی روی `baker.molido.shop` بالا آمده بود. اجرای «مرحلهٔ ۱» آن راهنما
> فایلی بدون بلوک TLS و بدون هدرهای امنیتی را روی کانفیگی کپی می‌کرد که
> هر دو را داشت — یعنی به بهانهٔ گرفتن گواهی، گواهی مغازه را برمی‌داشت.
>
> `deploy/nginx-bakery.conf` حالا **همان چیزی است که سرور اجرا می‌کند**.
>
> `nginx-bakery-tls.conf` و `scripts/enable-https.sh` حذف شدند. هر دو
> برای `baker.molido.ir` نوشته شده بودند — دامنه‌ای که نه گواهی دارد و نه
> DNSاش به این سرور اشاره می‌کند. اسکریپت اگر اجرا می‌شد بلوک TLSی نصب
> می‌کرد که به گواهی ناموجود اشاره دارد، و **nginx با گواهی ناموجود اصلاً
> بالا نمی‌آید** — یعنی مغازه تعطیل. کاری هم که برایش نوشته شده بود انجام
> شده: مغازه گواهی دارد.

### چه چیزی کجاست

| | آدرس | |
|---|---|---|
| پنل، از مرورگر | `https://baker.molido.shop` | پشت آروان، گواهی واقعی |
| آروان به سرور | پورت ۴۴۳ روی همین کانفیگ | رمزنگاری تا خود مغازه می‌رسد، نه فقط تا CDN |
| گوشی‌های نصب‌شده | `http://37.32.21.125` و `:8000` | **هنوز بدون رمزنگاری** |

روی سرور هیچ ریدایرکتی از ۸۰ به HTTPS نیست و نباید باشد: آن کار آروان
است، پورت ۸۰ باید برای گوشی‌ها و برای تمدید گواهی باز بماند، و ریدایرکت
اینجا زیر حالت Flexible حلقه می‌زند.

### دو چیزی که روی سرور کم است

کانفیگ زندهٔ سرور با `deploy/nginx-bakery.conf` این مخزن دو فرق دارد، و
هر دو به ضرر سرور است:

**۱. مسیر `/.well-known/acme-challenge/` سرو نمی‌شود.**

تمدید گواهی هر شصت روز یک فایل از آنجا می‌خواند. بدون این بلوک، درخواست
به لاراول می‌رسد و ۴۰۴ می‌گیرد، certbot نمی‌تواند نام را اثبات کند، و
گواهی **بی‌صدا تمدید نمی‌شود** — نه خطایی، نه ایمیلی. HTTPS تا روزی که
کار می‌کند کار می‌کند، و آن روز نود روز بعد از صدور است.

بررسی‌اش، بدون اینکه چیزی عوض شود:

```bash
sudo certbot renew --dry-run
```

**۲. `fastcgi_param HTTPS $https if_not_empty;` نیست.**

بدون آن لاراول نمی‌داند درخواست از روی TLS آمده و هر لینکی که می‌سازد —
لینک بازیابی رمز، ریدایرکت خروج از پنل — با `http://` بیرون می‌رود.

### اگر خواستید سرور را با این مخزن یکی کنید

**اول تفاوت را ببینید. هیچ‌وقت بدون این کپی نکنید:**

```bash
diff /etc/nginx/sites-available/bakery deploy/nginx-bakery.conf
```

هرچه با `<` شروع شود روی سرور هست و در مخزن نیست. اگر چیزی آنجا بود که
اینجا نیست و نمی‌دانید چرا، **قبل از کپی بپرسید** — همین یک کار بود که
جلوی پاک شدن گواهی را گرفت.

بعد، با پشتیبان و با تست:

```bash
sudo cp /etc/nginx/sites-available/bakery /root/bakery-nginx-$(date +%F).bak
sudo cp deploy/snippets/bakery-app.conf /etc/nginx/snippets/
sudo cp deploy/nginx-bakery.conf /etc/nginx/sites-available/bakery
sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` قبل از `reload` شرط است، نه ادب: nginx که بالا نیاید یعنی
مغازه تعطیل.

بعدش **هر سه را بیازمایید**، نه فقط اولی:

```bash
curl -s https://baker.molido.shop/api/v1/health
curl -s -o /dev/null -w '%{http_code}\n' http://37.32.21.125/api/v1/health
curl -s -o /dev/null -w '%{http_code}\n' http://37.32.21.125:8000/api/v1/health
```

سومی از همه مهم‌تر است. گوشی‌های مغازه با آن حرف می‌زنند؛ اگر ۲۰۰ نداد،
همهٔ فروشنده‌ها از کار افتاده‌اند.

برگشت، اگر چیزی خراب شد:

```bash
sudo cp /root/bakery-nginx-$(date +%F).bak /etc/nginx/sites-available/bakery
sudo nginx -t && sudo systemctl reload nginx
```

### گوشی‌ها هنوز روی HTTP هستند

`server.json` هنوز به IP اشاره می‌کند، پس رمز فروشنده از گوشی خوانا رد
می‌شود. حالا که دامنه از قبل گواهی دارد، این دیگر کار زیرساختی نیست —
فقط تغییر آدرس:

```json
"api_base_url": "https://baker.molido.shop/api/v1",
"fallback_urls": [
  "http://37.32.21.125/api/v1",
  "http://37.32.21.125:8000/api/v1"
]
```

fallbackها می‌مانند تا وقتی مطمئن شوید همهٔ گوشی‌ها روی نام کار می‌کنند.

**این تغییر هم‌زمان به همهٔ گوشی‌ها می‌رسد** — اپ این فایل را از GitHub
می‌خواند — پس اشتباهش هم‌زمان همه را می‌خواباند. اول از یک گوشی مطمئن
شوید که آروان روی مسیر API درست جواب می‌دهد.

### APP_URL

```bash
# در backend/.env
APP_URL=https://baker.molido.shop
```

```bash
sudo -u www-data php artisan config:cache
```

### HSTS: هنوز نه

در کانفیگ نیست و عمدی است. HSTS به هر گوشی‌ای که یک بار موفق شده می‌گوید
تا ماه‌ها HTTP ساده را برای این نام قبول نکند، و **با ویرایش فایل روی سرور
پس گرفته نمی‌شود** — دستور از قبل داخل گوشی است. بعد از اینکه گواهی
دست‌کم یک بار خودش تمدید شد و مغازه یک هفته روی نام کار کرد اضافه‌اش کنید.
تا آن وقت، گواهی خراب مسئلهٔ یک بعدازظهر است نه دو هفته.

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

### کارهای شبانهٔ دیگر

خط بالا فقط `backup:database` را صدا می‌زند. سه کار دیگر هم در
`backend/routes/console.php` زمان‌بندی شده‌اند و **هیچ‌کدام بدون یک خط cron
جداگانه اجرا نمی‌شوند**:

| کار | چه می‌کند |
|---|---|
| `tokens:prune-idle` | دسترسی گوشی‌هایی که ۳۰ روز استفاده نشده‌اند را می‌بندد |
| `sanctum:prune-expired` | دسترسی‌های منقضی را پاک می‌کند |
| `idempotency:prune` | کلیدهای نوشتنِ گوشی‌ها را که دیگر لازم نیستند پاک می‌کند |

اولی امنیتی است: توکنی که هیچ‌وقت استفاده نمی‌شود دقیقاً همانی است که گم
شدنش را کسی نمی‌فهمد. تا وقتی این خط نباشد، هیچ‌کدام یک بار هم اجرا نشده‌اند
و **جایی خطا هم نمی‌دهند** — درست مثل پشتیبانی که دو روز مرده بود.

```bash
( crontab -l 2>/dev/null; echo '* * * * * sudo -u www-data sh -c "cd /home/ubuntu/bakery-management-system/backend && HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan schedule:run >> storage/logs/schedule.log 2>&1"' ) | crontab -
```

خروجی به `/dev/null` نمی‌رود، چون آن هم همین را می‌کند: کاری که شکست
می‌خورد و کسی نمی‌فهمد. فایل باید از قبل مال `www-data` باشد، وگرنه شل
ریدایرکت را باز نمی‌کند و کل دستور پیش از اجرای `php` می‌میرد:

```bash
sudo -u www-data touch backend/storage/logs/schedule.log
```

اگر این خط نباشد، «امروز» روی گوشی خودش می‌گوید: «کارهای شبانهٔ سرور اجرا
نمی‌شوند». آن هشدار از روی توکن‌های باز‌ماندهٔ واقعی حساب می‌شود، نه از روی
حدس دربارهٔ cron.

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

## وقتی استقرار راه نمی‌افتد

دو چیز که روی سرور زنده پیش آمد و هر دو ظاهرشان با علتشان فرق داشت.

### «یک استقرار در جریان است» در حالی که نیست

اسکریپت یک بار **بدون** `sudo` اجرا شده بود و یک `/tmp/deploy.lock`
متعلق به کاربر `ubuntu` جا گذاشته بود. از آن به بعد هر اجرای با `sudo`
رد می‌شد: تنظیم `fs.protected_regular` هسته جلوی باز کردن فایل کاربر
دیگر را در پوشه‌های چسبنده و همگانی مثل `/tmp` می‌گیرد، حتی برای root.

قفل حالا در `/var/lock` است که همگانی‌نویس نیست، پس این تله دوباره
کار نمی‌گذارد. اگر روی سروری هنوز فایل قدیمی مانده، پاکش کنید:

```bash
sudo rm -f /tmp/deploy.lock /tmp/auto-deploy.lock /tmp/verify.lock
```

### «پوشهٔ کار روی سرور تغییرات ثبت‌نشده دارد» برای یازده فایل `.gitignore`

`git diff` را نگاه کنید: اگر فقط `old mode 100644` / `new mode 100755`
باشد، هیچ محتوایی عوض نشده. یک `chmod -R` در زمان راه‌اندازی به
`storage` و `bootstrap/cache` خورده و فایل‌های `.gitignore` داخلشان هم
بیت اجرایی گرفته‌اند.

دسترسی‌ها روی این سرور عمداً مدیریت می‌شوند، پس گیت باید بیت اجرایی را
نادیده بگیرد:

```bash
git -C /home/ubuntu/bakery-management-system config core.fileMode false
```

این بررسی عمداً سخت‌گیر است و نباید ضعیفش کرد: اگر کسی سر تنور فایلی را
دستی عوض کرده باشد، استقرار باید بایستد و بپرسد.
