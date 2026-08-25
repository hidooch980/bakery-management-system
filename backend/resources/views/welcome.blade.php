{{--
    The shop's front door.

    Everything is inline on purpose. This server has no asset pipeline —
    the panel's own dark theme is pinned with a renderHook style block for
    exactly that reason — so anything needing `npm run build` could never
    be deployed. No CDN either: a bakery in Sistan should not have its
    front page depend on somebody else's server staying up.

    The colours are the app's, from AppColors: the near-black ground
    #111214 and the one yellow #F5C518. The rule the app follows holds
    here too — the yellow means «press this» and appears once.
--}}
@php
    $name = $bakery?->name ?? 'نانوایی';
    $phone = $bakery?->phone;
    $address = $bakery?->address;
    $about = $bakery?->description;
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }}</title>
    <meta name="description" content="{{ $about ?: $name . ' — نان تازه، هر روز' }}">
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
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--ground);
            color: var(--text);
            /* System Persian faces, in the order they are actually
               installed on Iranian phones and desktops. No web font: one
               more request that can fail, for a page that is mostly text
               anyway. */
            font-family: "Vazirmatn", "IRANSans", "Segoe UI", Tahoma, sans-serif;
            line-height: 1.9;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 780px; margin: 0 auto; padding: 0 22px; }

        /* The warmth comes from one soft ember behind the title rather
           than from decoration anywhere else. */
        header {
            padding: 92px 0 64px;
            text-align: center;
            background:
                radial-gradient(60% 120% at 50% 0%, rgba(245, 197, 24, .10), transparent 70%);
        }

        .mark {
            width: 62px; height: 62px;
            margin: 0 auto 26px;
            border-radius: 18px;
            display: grid; place-items: center;
            background: var(--signal);
            color: var(--on-signal);
            font-size: 30px;
        }

        h1 {
            font-size: clamp(30px, 7vw, 46px);
            font-weight: 800;
            letter-spacing: -.4px;
        }

        .tagline {
            margin-top: 12px;
            color: var(--muted);
            font-size: clamp(15px, 3.6vw, 18px);
        }

        .cta {
            display: inline-block;
            margin-top: 34px;
            padding: 13px 30px;
            border-radius: 12px;
            background: var(--signal);
            color: var(--on-signal);
            font-weight: 700;
            font-size: 16px;
            text-decoration: none;
            transition: transform .15s ease, filter .15s ease;
        }
        .cta:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .cta:focus-visible { outline: 3px solid var(--signal); outline-offset: 3px; }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            padding: 8px 0 64px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px;
        }
        .card h2 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
        .card p  { color: var(--muted); font-size: 14px; }

        .contact {
            border-top: 1px solid var(--line);
            padding: 34px 0 16px;
            display: grid;
            gap: 12px;
        }
        .row { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; }
        .row .label { color: var(--muted); min-width: 54px; }
        .row a { color: var(--text); text-decoration: none; border-bottom: 1px solid var(--line); }
        .row a:hover { border-color: var(--signal); }

        footer {
            padding: 26px 0 40px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }
        footer a { color: var(--muted); }

        /* A page whose whole job is to be read has no business animating
           for somebody who asked it not to. */
        @media (prefers-reduced-motion: reduce) {
            .cta { transition: none; }
        }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <div class="mark" aria-hidden="true">🥖</div>
            <h1>{{ $name }}</h1>
            <p class="tagline">{{ $about ?: 'نان تازه، هر روز صبح' }}</p>

            {{-- The one yellow thing on the page, and the only thing here
                 anybody actually needs: the way in for the people who work
                 at this shop. --}}
            <a class="cta" href="/admin">ورود کارکنان</a>
        </div>
    </header>

    <main class="wrap">
        <div class="cards">
            <div class="card">
                <h2>نان تازه</h2>
                <p>هر روز صبح، از خمیر تا تنور در همین‌جا.</p>
            </div>
            <div class="card">
                <h2>آرد سهمیه‌ای</h2>
                <p>با آرد سهمیهٔ دولتی، به نرخ مصوب.</p>
            </div>
            <div class="card">
                <h2>فروش عمده</h2>
                <p>برای مدارس، ادارات و خوابگاه‌ها.</p>
            </div>
        </div>

        @if ($phone || $address)
            <section class="contact">
                @if ($phone)
                    <div class="row">
                        <span class="label">تلفن</span>
                        {{-- A phone number on a phone should be pressable.
                             Latin digits in the href because that is what a
                             dialler parses; the eye reads the same either
                             way. --}}
                        <a href="tel:{{ preg_replace('/\D+/', '', $phone) }}">{{ $phone }}</a>
                    </div>
                @endif
                @if ($address)
                    <div class="row">
                        <span class="label">نشانی</span>
                        <span>{{ $address }}</span>
                    </div>
                @endif
            </section>
        @endif
    </main>

    <footer class="wrap">
        {{ $name }} — تمام حقوق محفوظ است
    </footer>
</body>
</html>
