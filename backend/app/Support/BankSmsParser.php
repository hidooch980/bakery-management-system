<?php

namespace App\Support;

/**
 * Reads a bank SMS: which way the money went, how much, the balance, and
 * the last four digits of the account or card.
 *
 * Written against Bank Sepah first — «برداشت از حساب …», «واريز گروهي …
 * کارتخوان» and «واريز پايا …» — and loose enough for the usual shapes of
 * the other banks. Amounts are rial unless the text says تومان; they come
 * back in Toman, the unit everything is stored in.
 *
 * Nothing here logs or returns the full account number.
 */
class BankSmsParser
{
    /** Sender codes known for some banks. The body's own «بانک …» wins, and is
     * what Sepah's messages carry. */
    public const SENDER_BANKS = [
        '300017' => 'ملی',
        '200033' => 'ملت',
    ];

    public const BANK_NAMES = [
        'سپه', 'ملی', 'ملت', 'صادرات', 'تجارت', 'کشاورزی', 'مسکن', 'رفاه',
        'پاسارگاد', 'سامان', 'پارسیان', 'رسالت', 'شهر', 'سینا', 'اقتصاد نوین',
        'آینده', 'دی', 'توسعه تعاون', 'پست بانک',
    ];

    public static function normalize(string $text): string
    {
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', '٬' => ',', '،' => ',',
            '：' => ':', "\u{200C}" => ' ', "\u{200F}" => '', "\u{200E}" => '',
        ]);

        return preg_replace('/[ \t]+/u', ' ', $text);
    }

    /** One-time passwords and the like are never money. */
    public static function isOtp(string $text): bool
    {
        return (bool) preg_match('/(رمز|کد ?(تایید|تأیید|فعال)|پویا|OTP)/iu', self::normalize($text));
    }

    /**
     * @return array{direction: string, kind: string, amount: ?float, balance: ?float, last4: ?string, bank: ?string, suggested_category: ?string}|null
     */
    public static function parse(string $sender, string $body): ?array
    {
        $t = self::normalize($body);

        if (self::isOtp($t)) {
            return null;
        }

        $direction = self::direction($t);
        $amount = self::amount($t);

        if ($direction === 'unknown' && $amount === null) {
            return null;
        }

        $kind = match (true) {
            str_contains($t, 'گروهی') && str_contains($t, 'کارتخوان') => 'pos_group',
            str_contains($t, 'کارتخوان') && $direction === 'in' => 'pos_group',
            str_contains($t, 'پایا') => 'paya',
            str_contains($t, 'ساتنا') => 'satna',
            str_contains($t, 'انتقال') => 'transfer',
            $direction === 'out' => 'withdrawal',
            default => 'deposit',
        };

        return [
            'direction' => $direction,
            'kind' => $kind,
            'amount' => $amount,
            'balance' => self::balance($t),
            'last4' => self::last4($t),
            'bank' => self::bank($sender, $t),
            'suggested_category' => match (true) {
                $kind === 'pos_group' => 'card_sale',
                default => 'other',
            },
        ];
    }

    public static function direction(string $t): string
    {
        $out = self::firstPosition($t, ['برداشت', 'خرید', 'کسر', 'انتقال از', 'پرداخت']);
        $in = self::firstPosition($t, ['واریز', 'انتقال به', 'افزایش', 'بستانکار']);

        if ($out === null && $in === null) {
            if (preg_match('/(^|\s)-\s?[\d,]{3,}/u', $t)) {
                return 'out';
            }
            if (preg_match('/(^|\s)\+\s?[\d,]{3,}/u', $t)) {
                return 'in';
            }

            return 'unknown';
        }

        if ($out === null) {
            return 'in';
        }
        if ($in === null) {
            return 'out';
        }

        return $in < $out ? 'in' : 'out';
    }

    public static function amount(string $t): ?float
    {
        if (preg_match('/مبلغ\s*:?\s*([\d,\.]+)/u', $t, $m)
            || preg_match('/(?:واریز|برداشت|خرید)\s*:?\s*([\d,]{4,})/u', $t, $m)
            || preg_match('/[+\-]\s?([\d,]{4,})/u', $t, $m)
            || preg_match('/([\d,]{4,})\s*ریال/u', $t, $m)) {
            return self::toToman($m[1], $t);
        }

        return null;
    }

    public static function balance(string $t): ?float
    {
        if (preg_match('/(?:مانده|موجودی)\s*:?\s*(-?[\d,\.]+)/u', $t, $m)) {
            return self::toToman($m[1], $t);
        }

        return null;
    }

    /** The last four digits after «حساب» or «کارت», masked or not. */
    public static function last4(string $t): ?string
    {
        if (preg_match('/(?:حساب|کارت|سپرده)\s*(?:شماره|ش)?\.?\s*:?\s*([\d\*\.\-x]{4,})/u', $t, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);

            return strlen($digits) >= 4 ? substr($digits, -4) : null;
        }

        return null;
    }

    public static function bank(string $sender, string $t): ?string
    {
        foreach (self::BANK_NAMES as $name) {
            if (preg_match('/بانک\s*'.preg_quote($name, '/').'/u', $t)) {
                return $name;
            }
        }

        $code = ltrim(preg_replace('/\D/', '', $sender), '0');

        return self::SENDER_BANKS[$code] ?? self::SENDER_BANKS[preg_replace('/^98/', '', $code)] ?? null;
    }

    /**
     * «حساب 0123456789012» → «حساب ****9012». Every digit run of ten or
     * more, and every number after حساب/کارت, keeps only its last four.
     */
    public static function mask(string $body): string
    {
        $masked = preg_replace_callback(
            '/((?:حساب|کارت|سپرده|حساب|كارت)\s*(?:شماره)?\s*:?\s*)([\d۰-۹\*\.\-]{5,})/u',
            fn ($m) => $m[1].'****'.mb_substr(preg_replace('/[^\d۰-۹]/u', '', $m[2]), -4),
            $body,
        );

        return preg_replace_callback(
            '/[\d۰-۹]{10,}/u',
            fn ($m) => '****'.mb_substr($m[0], -4),
            $masked,
        );
    }

    private static function toToman(string $raw, string $t): ?float
    {
        $n = (float) str_replace(',', '', $raw);

        if ($n <= 0) {
            return null;
        }

        return str_contains($t, 'تومان') ? $n : round($n / 10, 2);
    }

    private static function firstPosition(string $t, array $words): ?int
    {
        $best = null;
        foreach ($words as $w) {
            $p = mb_strpos($t, $w);
            if ($p !== false && ($best === null || $p < $best)) {
                $best = $p;
            }
        }

        return $best;
    }
}
