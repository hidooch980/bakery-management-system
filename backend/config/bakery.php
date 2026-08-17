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

];
