<?php

/**
 * Persian validation messages.
 *
 * APP_LOCALE was already fa, but no lang directory existed, so Laravel fell
 * back to its built-in English. Nobody noticed because every validation
 * failure used to be replaced by one fixed Persian sentence on the way out
 * — the moment that stopped, staff started seeing "The password field is
 * required" on a Persian screen.
 *
 * Only the rules this application actually uses are translated. An
 * untranslated rule falls back to English, which is the honest outcome:
 * better a sentence in the wrong language than a wrong sentence.
 */
return [
    'required' => 'وارد کردن :attribute الزامی است.',
    'required_if' => 'وارد کردن :attribute الزامی است.',
    'required_with' => 'وارد کردن :attribute الزامی است.',
    'required_without' => 'وارد کردن :attribute الزامی است.',
    'filled' => ':attribute نمی‌تواند خالی باشد.',
    'present' => ':attribute باید ارسال شود.',

    'string' => ':attribute باید متن باشد.',
    'numeric' => ':attribute باید عدد باشد.',
    'integer' => ':attribute باید عدد صحیح باشد.',
    'boolean' => ':attribute باید بله یا خیر باشد.',
    'array' => ':attribute باید فهرست باشد.',
    'date' => ':attribute تاریخ معتبری نیست.',
    'email' => ':attribute باید نشانی ایمیل معتبر باشد.',
    'image' => ':attribute باید تصویر باشد.',
    'file' => ':attribute باید فایل باشد.',

    'in' => ':attribute انتخاب‌شده معتبر نیست.',
    'not_in' => ':attribute انتخاب‌شده معتبر نیست.',
    'exists' => ':attribute انتخاب‌شده وجود ندارد.',
    'unique' => ':attribute قبلاً ثبت شده است.',
    'confirmed' => 'تکرار :attribute مطابقت ندارد.',
    'same' => ':attribute و :other باید یکسان باشند.',
    'different' => ':attribute و :other نباید یکسان باشند.',
    'regex' => 'قالب :attribute معتبر نیست.',

    'min' => [
        'numeric' => ':attribute نباید کمتر از :min باشد.',
        'string' => ':attribute نباید کمتر از :min نویسه باشد.',
        'array' => ':attribute باید دست‌کم :min مورد داشته باشد.',
        'file' => 'حجم :attribute نباید کمتر از :min کیلوبایت باشد.',
    ],

    'max' => [
        'numeric' => ':attribute نباید بیشتر از :max باشد.',
        'string' => ':attribute نباید بیشتر از :max نویسه باشد.',
        'array' => ':attribute نباید بیشتر از :max مورد داشته باشد.',
        'file' => 'حجم :attribute نباید بیشتر از :max کیلوبایت باشد.',
    ],

    'between' => [
        'numeric' => ':attribute باید بین :min و :max باشد.',
        'string' => ':attribute باید بین :min و :max نویسه باشد.',
        'array' => ':attribute باید بین :min و :max مورد داشته باشد.',
        'file' => 'حجم :attribute باید بین :min و :max کیلوبایت باشد.',
    ],

    'gt' => [
        'numeric' => ':attribute باید بزرگ‌تر از :value باشد.',
    ],

    'gte' => [
        'numeric' => ':attribute باید بزرگ‌تر یا مساوی :value باشد.',
    ],

    'lt' => [
        'numeric' => ':attribute باید کوچک‌تر از :value باشد.',
    ],

    'lte' => [
        'numeric' => ':attribute باید کوچک‌تر یا مساوی :value باشد.',
    ],

    /**
     * What each field is called when it appears in a message. Without these
     * the raw column name shows through — "وارد کردن bag_count الزامی است"
     * means nothing to someone counting sacks of flour.
     */
    'attributes' => [
        'login' => 'نام کاربری',
        'password' => 'رمز عبور',
        'current_password' => 'رمز فعلی',
        'new_password' => 'رمز جدید',
        'name' => 'نام',
        'phone' => 'تلفن',
        'email' => 'ایمیل',
        'role' => 'نقش',
        'amount' => 'مبلغ',
        'note' => 'توضیح',
        'date' => 'تاریخ',
        'bag_count' => 'تعداد کیسه',
        'bags' => 'تعداد کیسه',
        'chane_count' => 'تعداد چانه',
        'bread_count' => 'تعداد نان',
        'weight_kg' => 'وزن (کیلوگرم)',
        'spray_flour_kg' => 'آرد پاشش (کیلوگرم)',
        'payment_type' => 'نوع پرداخت',
        'customer_id' => 'مشتری',
        'bank_account_id' => 'حساب بانکی',
        'category' => 'دسته',
        'quantity' => 'مقدار',
        'price' => 'قیمت',
        'sale_ids' => 'موارد تسویه',
        'backup' => 'فایل پشتیبان',
    ],
];
