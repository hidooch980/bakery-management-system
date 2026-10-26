<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * «پیامک‌های بانکی» — طرح صفحهٔ پنل (مرحلهٔ نمونهٔ تصویری).
 *
 * داده‌ها ساختگی‌اند و هیچ چیز ثبت نمی‌شود. در مرحلهٔ ساخت، فهرست از جدول
 * bank_sms خوانده می‌شود و «ثبت» همان Income/Expense و تراکنش بانکی را
 * می‌سازد که ثبت دستی می‌سازد.
 */
class BankSmsInbox extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'پیامک‌های بانکی';

    protected static ?string $title = 'پیامک‌های بانکی';

    protected static string $view = 'filament.pages.bank-sms-inbox';

    protected static ?string $slug = 'bank-sms';

    /** Hidden from the menu until the feature is built. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $c = 'ملی — حساب کارتخوان';

        return [
            ['dir' => 'in', 'amount' => '12,345,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'فروش نان (کارتخوان)', 'at' => '1405/08/04 08:41', 'body' => 'واریز: 12,345,000 ریال — کارت 6037**1234 — مانده: 98,765,000', 'status' => 'pending'],
            ['dir' => 'in', 'amount' => '12,345,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'فروش نان (کارتخوان)', 'at' => '1405/08/04 08:43', 'body' => 'واریز: 12,345,000 ریال — کارت 6037**1234', 'status' => 'pending', 'duplicate' => 'همین مبلغ ساعت 08:41 هم آمده'],
            ['dir' => 'out', 'amount' => '3,000,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'سایر', 'at' => '1405/08/04 08:00', 'body' => 'برداشت 3,000,000 ریال — کارت 6037**1234', 'status' => 'ambiguous', 'candidates' => ['علی‌الحساب — سعید غلامزهی', 'هزینه — تعمیر دستگاه']],
            ['dir' => 'out', 'amount' => '1,111,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'حمل و نقل', 'at' => '1405/08/04 07:00', 'body' => 'برداشت: 1,111,000 ریال — کارت 6037**1234', 'status' => 'pending'],
            ['dir' => 'out', 'amount' => '2,222,000', 'bank' => 'سپه', 'card' => '0987', 'account' => null, 'cat' => 'آب، برق، گاز', 'at' => '1405/08/04 04:00', 'body' => 'حساب 0987 — برداشت 2,222,000 — قبض برق', 'status' => 'pending'],
            ['dir' => 'out', 'amount' => '6,500,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'حقوق کارکنان', 'at' => '1405/08/03 18:00', 'body' => 'برداشت: 6,500,000 ریال — کارت 6037**1234', 'status' => 'matched', 'matched' => 'حقوق — جلیل درازهی'],
            ['dir' => 'out', 'amount' => '12,000,000', 'bank' => 'ملت', 'card' => '5678', 'account' => 'ملت — حساب اصلی', 'cat' => 'سایر', 'at' => '1405/08/03 13:00', 'body' => 'برداشت 12,000,000 — حساب 5678 — قسط', 'status' => 'matched', 'matched' => 'قسط وام — وام ملت (قسط ۷)'],
            ['dir' => 'in', 'amount' => '4,444,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'فروش نان (کارتخوان)', 'at' => '1405/08/03 06:00', 'body' => 'واریز 4,444,000', 'status' => 'confirmed'],
            ['dir' => 'out', 'amount' => '555,000', 'bank' => 'ملی', 'card' => '1234', 'account' => $c, 'cat' => 'سایر', 'at' => '1405/08/02 09:00', 'body' => 'برداشت 555,000', 'status' => 'ignored'],
        ];
    }

    /** @return array<int, array<string, ?string>> */
    public function cards(): array
    {
        return [
            ['card' => '1234', 'bank' => 'ملی', 'account' => 'ملی — حساب کارتخوان'],
            ['card' => '5678', 'bank' => 'ملت', 'account' => 'ملت — حساب اصلی'],
            ['card' => '0987', 'bank' => 'سپه', 'account' => null],
        ];
    }
}
