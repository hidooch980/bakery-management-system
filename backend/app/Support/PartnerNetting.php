<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use Illuminate\Support\Collection;

/**
 * «همه چی اوتوماتیک»: هر ثبتِ دادیم/گرفتیم خودبه‌خود از ماندهٔ باز طرف
 * مقابلِ همان همکار کم می‌شود — قدیمی‌ترین اول (FIFO) — و دیگر کسی لازم
 * نیست دکمهٔ «تسویه» یا «ثبت برگشت» بزند.
 *
 * فقط حساب می‌شود و چیزی در پایگاه داده نوشته نمی‌شود:
 *   - انبار دست نمی‌خورد. هر ثبت همان جابه‌جایی واقعیِ کیسه است (دادیم از
 *     انبار بیرون رفت، گرفتیم به انبار آمد) و خالص کردن فقط حساب همکار است.
 *   - ردیف‌های قدیمی همان‌طور که بودند می‌مانند؛ settled_on و برگشت‌های
 *     ثبت‌شده همچنان تاریخچه‌اند و از مقدار اولیهٔ هر ردیف کم می‌شوند.
 *   - ویرایش یا حذف یک ردیف خودبه‌خود همه‌چیز را از نو درست حساب می‌کند.
 *
 * ماندهٔ خالص همکار با این کار عوض نمی‌شود (دو طرف به یک اندازه کم
 * می‌شوند)؛ فقط معلوم می‌شود کدام ردیف‌ها هنوز باز مانده‌اند و از کِی.
 */
class PartnerNetting
{
    /** کلید همکار: شناسهٔ همکار تعریف‌شده، وگرنه نام آزادِ ردیف‌های قدیمی. */
    public static function keyOf(ConsignmentFlour $record): string
    {
        return $record->customer_id !== null
            ? (string) $record->customer_id
            : (string) $record->partner_name;
    }

    /**
     * کیسه‌های بازِ هر ردیف بعد از خالص شدن، به شناسهٔ ردیف.
     *
     * @param  Collection<int, ConsignmentFlour>  $records  همهٔ ردیف‌های همکار(ها)
     * @return array<int, float>
     */
    public static function open(Collection $records): array
    {
        $open = [];

        foreach ($records->groupBy(fn (ConsignmentFlour $c) => self::keyOf($c)) as $group) {
            $queues = ['lent' => [], 'borrowed' => []];

            $sorted = $group->sort(fn (ConsignmentFlour $a, ConsignmentFlour $b) => [$a->occurred_on?->toDateString(), $a->id]
                <=> [$b->occurred_on?->toDateString(), $b->id]);

            foreach ($sorted as $record) {
                $left = $record->outstandingBags();
                $other = $record->direction === 'lent' ? 'borrowed' : 'lent';

                // از قدیمی‌ترین ماندهٔ طرف مقابل کم می‌شود.
                foreach ($queues[$other] as $id => $bags) {
                    if ($left <= 0.001) {
                        break;
                    }

                    $used = min($bags, $left);
                    $left = round($left - $used, 2);
                    $queues[$other][$id] = round($bags - $used, 2);

                    if ($queues[$other][$id] <= 0.001) {
                        unset($queues[$other][$id]);
                    }
                }

                if ($left > 0.001) {
                    $queues[$record->direction][$record->id] = $left;
                }
            }

            foreach ($group as $record) {
                $open[$record->id] = $queues[$record->direction][$record->id] ?? 0.0;
            }
        }

        return $open;
    }

    /** کیسه‌های بازِ یک ردیف، با خواندن ردیف‌های همان همکار. */
    public static function openOf(ConsignmentFlour $record): float
    {
        $siblings = ConsignmentFlour::query()
            ->when(
                $record->customer_id !== null,
                fn ($q) => $q->where('customer_id', $record->customer_id),
                fn ($q) => $q->whereNull('customer_id')->where('partner_name', $record->partner_name),
            )
            ->with('returns')
            ->get();

        return self::open($siblings)[$record->id] ?? 0.0;
    }
}
