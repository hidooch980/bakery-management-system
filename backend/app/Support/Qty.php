<?php

namespace App\Support;

/**
 * A quantity, written the way this shop reads numbers.
 *
 * Money already groups with the Persian comma — Money::GROUP_SEPARATOR,
 * chosen by the owner — but every weight and count on the dashboard went
 * through a bare number_format and came out with the Latin one. Both
 * appeared on the same screen: «۱,۰۸۳٫۰ کیلوگرم» of flour beside a balance
 * written «۳۸۷،۲۲۸،۹۷۳». One screen, two conventions.
 *
 * Deliberately not applied to dates. A previous sweep turned a chart's
 * «05/03» into «05،03», and the separator has no business anywhere a
 * number is not a quantity.
 */
class Qty
{
    public static function format(float|int|null $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, '.', Money::GROUP_SEPARATOR);
    }
}
