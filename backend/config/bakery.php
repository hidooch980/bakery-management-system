<?php

return [

    /*
    |--------------------------------------------------------------------------
    | More than one shop
    |--------------------------------------------------------------------------
    |
    | Off. The owner asked on 2026-08-17 for the other bakeries to stay shut
    | until the app has been through a final test on real handsets — «فعلاً
    | نانوایی‌های دیگر غیرفعال بشه».
    |
    | Everything for it is built and tested: the OpenBakery page, the
    | `bakery:create --like=` command that copies a shop's formula and
    | weights, and the BelongsToBakery scope that keeps one shop's takings
    | out of another's screens. This switch only decides whether the panel
    | offers to open one.
    |
    | Turning it on is this line and nothing else. The console command is
    | left reachable on purpose — it takes a deliberate ssh session and a
    | typed password, which is not something anyone does by accident.
    |
    */

    'multi_shop' => env('BAKERY_MULTI_SHOP', false),

    /*
    |--------------------------------------------------------------------------
    | Paying the partners
    |--------------------------------------------------------------------------
    |
    | Off. «برداشت شرکا اصلا وجود ندارد» — 2026-08-17.
    |
    | The shop is held five dang to one between two brothers and that
    | ownership is real, but nothing has ever been drawn against it: zero
    | settlements, ever. Meanwhile the split screen was showing the whole
    | period's profit as money owed to them, and the balance sheet was
    | carrying it as a liability — a debt of a billion and a half Rial that
    | nobody is owed and nobody expects.
    |
    | Worse, that figure inherits the payroll hole: the profit it divides
    | has no wages in it, so what each brother appeared to be owed was
    | overstated by their share of a thousand million Rial a month.
    |
    | The shares themselves stay on file. This decides only whether the app
    | and the panel show a split and a balance owing.
    |
    */

    'partner_drawings' => env('BAKERY_PARTNER_DRAWINGS', false),

];
