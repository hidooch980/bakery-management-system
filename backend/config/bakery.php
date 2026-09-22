<?php

return [

    /*
    |--------------------------------------------------------------------------
    | More than one shop
    |--------------------------------------------------------------------------
    |
    | On, since 1405/07/01. It was shut on 2026-08-17 — «فعلاً نانوایی‌های
    | دیگر غیرفعال بشه» — until the app had been through a final test on
    | real handsets. That has happened, and the owner has since asked for
    | «نانوایی جدید / شعبه جدید»: a branch is a bakery of its own, with its
    | own quota, store, till, staff and books.
    |
    | What this decides is only whether the panel offers the «نانوایی
    | جدید» page. Everything behind it was built and tested throughout:
    | the `bakery:create --like=` command that copies a shop's formula and
    | weights, the BelongsToBakery scope that keeps one shop's takings out
    | of another's screens, and the topbar switcher that lets an owner
    | holding two of them look at the second.
    |
    */

    'multi_shop' => env('BAKERY_MULTI_SHOP', true),

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

    /*
    |--------------------------------------------------------------------------
    | The shop's certificate
    |--------------------------------------------------------------------------
    |
    | Where certbot keeps the certificate for baker.molido.ir. The issues
    | page reads its expiry so a renewal that has quietly stopped is seen
    | three weeks before HTTPS goes dark, not on the morning it does. A
    | machine without the file — every developer's — simply has no such
    | issue.
    |
    */

    'tls_certificate' => env(
        'BAKERY_TLS_CERTIFICATE',
        '/etc/letsencrypt/live/baker.molido.ir/fullchain.pem',
    ),

];
