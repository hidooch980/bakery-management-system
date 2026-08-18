<?php

namespace App\Rules;

use App\Support\Sms;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses the passwords an attacker tries first.
 *
 * Every place in this system already required eight characters, and on
 * 2026-08-18 one of the shop's five accounts still had a password that
 * appears on every published list of the commonest ones. Eight characters
 * of «12345678» is eight characters, and it is guessed in under a second.
 *
 * So length is not the test. The test is whether the password is one that
 * somebody would try.
 *
 * This is a short list on purpose. A real breach corpus is millions of
 * entries and belongs behind an API, which this shop's server cannot rely
 * on reaching — Iranian hosting and haveibeenpwned do not always speak.
 * What is here covers what actually gets typed: keyboard runs, repeated
 * digits, the words «password» and «admin», the shop's own name, and
 * Persian-keyboard equivalents.
 */
class NotAGuessablePassword implements ValidationRule
{
    /**
     * The ones tried first. Compared case-insensitively and after Persian
     * digits are turned into Latin ones, because «۱۲۳۴۵۶۷۸» is the same
     * password to everyone except a string comparison.
     */
    private const COMMON = [
        '12345678', '123456789', '1234567890', '87654321', '11111111',
        '00000000', '22222222', '12341234', '11223344', '12121212',
        'password', 'password1', 'passw0rd', 'qwertyui', 'asdfghjk',
        'zxcvbnm1', 'qwerty123', '1qaz2wsx', 'iloveyou',
        'admin123', 'administrator', 'adminadmin', 'root1234',
        'bakery123', 'nanvaei1', 'mollazehi', 'khabazi1',
        'iran1234', 'tehran12', 'zahedan1',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = Sms::latinDigits((string) $value);
        $lower = mb_strtolower($password);

        if (in_array($lower, self::COMMON, true)) {
            $fail('این رمز از رایج‌ترین رمزهاست و در چند ثانیه حدس زده می‌شود. رمز دیگری بگذارید.');

            return;
        }

        // One character over and over. Not on the list above because there
        // are too many of them to list, and «aaaaaaaa» is no better than
        // «11111111».
        if (preg_match('/^(.)\1+$/u', $password) === 1) {
            $fail('رمز نباید تکرار یک نویسه باشد.');

            return;
        }

        // A straight run, up or down, of digits. «23456789» is not on any
        // list and is tried by every tool.
        if (self::isARun($password)) {
            $fail('رمز نباید عددهای پشت سر هم باشد.');
        }
    }

    private static function isARun(string $password): bool
    {
        if (preg_match('/^\d+$/', $password) !== 1 || mb_strlen($password) < 4) {
            return false;
        }

        $digits = str_split($password);
        $up = true;
        $down = true;

        for ($i = 1; $i < count($digits); $i++) {
            $step = (int) $digits[$i] - (int) $digits[$i - 1];

            $up = $up && $step === 1;
            $down = $down && $step === -1;
        }

        return $up || $down;
    }
}
