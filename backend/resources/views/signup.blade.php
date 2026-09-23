{{--
    فرمِ درخواستِ نانوایی تازه.

    همه‌چیز inline است، به همان دلیلی که صفحهٔ اصلی هست: این سرور هیچ
    خط تولیدِ asset ندارد و هیچ CDN ای هم در کار نیست — نانوایی‌ای در
    سیستان نباید صفحه‌اش به سرورِ کسِ دیگری بند باشد.

    رنگ‌ها همان‌هاست و همان قاعده: زردِ #F5C518 یعنی «این را بزن» و
    یک بار بیشتر نمی‌آید.

    فرم فقط چهار چیزِ اجباری می‌پرسد. هر خانهٔ اضافه، یک نانوای دیگر
    است که وسط راه رهایش می‌کند — و این در، اولین چیزی است که کسی از
    این سامانه می‌بیند.

    رمز عبور پرسیده نمی‌شود، و این عمدی است: رمزی که غریبه‌ای در فرمی
    تایپ کند و تا وقتی کسی برسد در جدولی بماند، رمزی است که جلوی چشم
    نشسته. صاحبِ سامانه موقع پذیرش یکی می‌گذارد.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>درخواست نانوایی تازه</title>
    <meta name="description" content="نانوایی‌تان را با این سامانه بگردانید — سهمیهٔ آرد، فروش، کارکنان و حساب‌ها.">
    <meta name="theme-color" content="#111214">
    <style>
        :root {
            --ground: #111214;
            --surface: #17191D;
            --line: #24272C;
            --signal: #F5C518;
            --on-signal: #17150A;
            --text: #F2F3F5;
            --muted: #9AA0A8;
            --bad: #D1495B;
            --good: #2E9E6B;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--ground);
            color: var(--text);
            font-family: "Vazirmatn", "IRANSans", "Segoe UI", Tahoma, sans-serif;
            line-height: 1.9;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 560px; margin: 0 auto; padding: 0 22px; }

        header { padding: 64px 0 28px; text-align: center; }
        header h1 { font-size: 26px; font-weight: 800; }
        header p { color: var(--muted); font-size: 15px; margin-top: 10px; }

        .note {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 18px 20px;
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 26px;
        }

        form { display: grid; gap: 18px; padding-bottom: 56px; }

        label { display: grid; gap: 7px; font-size: 14px; }
        .hint { color: var(--muted); font-size: 13px; }

        input, textarea {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            color: var(--text);
            font: inherit;
            font-size: 15px;
            padding: 12px 14px;
            width: 100%;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: var(--signal);
        }
        textarea { min-height: 96px; resize: vertical; }

        /* یک بار، و همان یک زرد. */
        button {
            background: var(--signal);
            color: var(--on-signal);
            border: 0;
            border-radius: 12px;
            font: inherit;
            font-size: 16px;
            font-weight: 800;
            padding: 14px;
            cursor: pointer;
        }

        .errors, .done {
            border-radius: 14px;
            padding: 16px 18px;
            font-size: 14px;
            margin-bottom: 22px;
        }
        .errors { border: 1px solid var(--bad); color: var(--text); }
        .errors ul { margin: 6px 20px 0 0; }
        .done { border: 1px solid var(--good); color: var(--text); }

        footer {
            padding: 8px 0 44px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }
        footer a { color: var(--muted); }
    </style>
</head>
<body>
    <header class="wrap">
        <h1>نانوایی‌تان را اینجا بگردانید</h1>
        <p>سهمیهٔ آرد، فروش روزانه، حساب فروشنده‌ها، حقوق کارکنان — یک‌جا.</p>
    </header>

    <main class="wrap">
        @if (session('sent'))
            <div class="done">
                <strong>درخواست‌تان ثبت شد.</strong><br>
                پس از بررسی با شمارهٔ شما تماس گرفته می‌شود. لازم نیست
                دوباره بفرستید.
            </div>
        @endif

        @if ($errors->any())
            <div class="errors">
                <strong>این‌ها را درست کنید:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="note">
            این فرم فقط یک پیام ثبت می‌کند. نانوایی و حساب کاربری پس از
            بررسی و تماس ساخته می‌شود — پس رمز عبوری اینجا نمی‌خواهیم.
        </div>

        <form method="POST" action="{{ route('signup.store') }}">
            @csrf

            <label>
                نام نانوایی
                <input name="bakery_name" value="{{ old('bakery_name') }}"
                       required maxlength="255" autocomplete="organization">
            </label>

            <label>
                نام شما
                <input name="owner_name" value="{{ old('owner_name') }}"
                       required maxlength="255" autocomplete="name">
            </label>

            <label>
                شمارهٔ تماس
                <input name="phone" value="{{ old('phone') }}" type="tel"
                       required maxlength="20" inputmode="tel" autocomplete="tel">
                <span class="hint">با همین شماره با شما تماس گرفته می‌شود.</span>
            </label>

            <label>
                شهر
                <input name="city" value="{{ old('city') }}" maxlength="100">
            </label>

            <label>
                ایمیل <span class="hint">(اختیاری)</span>
                <input name="email" value="{{ old('email') }}" type="email"
                       maxlength="255" autocomplete="email">
            </label>

            <label>
                توضیح <span class="hint">(اختیاری)</span>
                <textarea name="note" maxlength="1000">{{ old('note') }}</textarea>
            </label>

            <button type="submit">فرستادن درخواست</button>
        </form>
    </main>

    <footer class="wrap">
        <a href="{{ route('home') }}">بازگشت</a>
    </footer>
</body>
</html>
