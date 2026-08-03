<?php

namespace App\Support;

use App\Models\Bakery;

/**
 * Amounts are always stored in Toman. This formats them for display in
 * whichever unit the bakery has configured.
 */
class Money
{
    public const TOMAN = 'toman';

    public const RIAL = 'rial';

    public const UNITS = [
        self::TOMAN => 'تومان',
        self::RIAL => 'ریال',
    ];

    /** One Toman is ten Rial. */
    public const RIAL_PER_TOMAN = 10;

    private static ?string $cachedCurrency = null;

    public static function currency(): string
    {
        return self::$cachedCurrency ??= Bakery::query()->value('currency') ?? self::TOMAN;
    }

    public static function label(?string $currency = null): string
    {
        return self::UNITS[$currency ?? self::currency()] ?? self::UNITS[self::TOMAN];
    }

    /** Converts a stored Toman amount into the display unit. */
    public static function convert(float|int|string|null $toman, ?string $currency = null): float
    {
        $amount = (float) ($toman ?? 0);

        return ($currency ?? self::currency()) === self::RIAL
            ? $amount * self::RIAL_PER_TOMAN
            : $amount;
    }

    /** e.g. "۱٬۲۶۰٬۰۰۰ تومان" — grouped, with the configured unit appended. */
    public static function format(float|int|string|null $toman, ?string $currency = null): string
    {
        $currency ??= self::currency();

        return number_format(self::convert($toman, $currency)).' '.self::label($currency);
    }

    /** Converts an amount typed in the display unit back to stored Toman. */
    public static function toToman(float|int|string|null $displayed, ?string $currency = null): float
    {
        $amount = (float) ($displayed ?? 0);

        return ($currency ?? self::currency()) === self::RIAL
            ? $amount / self::RIAL_PER_TOMAN
            : $amount;
    }

    /** Clears the cached currency after the setting is changed. */
    public static function forgetCache(): void
    {
        self::$cachedCurrency = null;
    }
}
