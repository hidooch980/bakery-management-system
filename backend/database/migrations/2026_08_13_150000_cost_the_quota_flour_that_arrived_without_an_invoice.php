<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 199 sacks of the Mordad quota were baked but never costed.
 *
 * The month's allocation is 343 sacks. Three flour invoices were entered,
 * covering 144 sacks between them; the rest arrived through two
 * "correction" stock movements, which put the flour on the shelf without
 * putting its price in the books.
 *
 * Cost of goods was unaffected — the profit and loss statement prices
 * flour as it is consumed, not as it is bought. Cash profit was not: it
 * is income less recorded spending, and the partners' split is paid on
 * it, so every sack that arrived without an invoice raised what the
 * partners appeared to be owed. 199 sacks at 48,000 is 9,552,000 Toman of
 * profit that was really flour.
 *
 * Guarded on the exact state it is correcting — the 343-sack allocation
 * and the 6,912,000 already recorded — so it cannot fire on a shop it was
 * not written for, and cannot fire twice on this one.
 */
return new class extends Migration
{
    private const SACKS = 199;

    private const PER_SACK = 48000;  // 1,200 Toman a kilo, 40 kg a sack

    private const ALREADY_RECORDED = 6912000;

    private const TITLE = 'خرید آرد سهمیه مرداد — مانده ۱۹۹ کیسه';

    /** The day the bulk physically arrived, so the cost sits with the flour. */
    private const SPENT_ON = '2026-08-08';

    public function up(): void
    {
        $bakery = DB::table('bakeries')->first();

        if (! $bakery) {
            return;
        }

        $allocation = DB::table('flour_allocations')
            ->where('bakery_id', $bakery->id)
            ->where('total_bags', 343)
            ->exists();

        $recorded = (float) DB::table('expenses')
            ->where('bakery_id', $bakery->id)
            ->where('category', 'flour')
            ->sum('amount');

        if (! $allocation || round($recorded, 2) !== (float) self::ALREADY_RECORDED) {
            return;
        }

        DB::table('expenses')->insert([
            'bakery_id' => $bakery->id,
            // Whoever owns the shop's records; the invoice is the shop's,
            // not any one person's.
            'user_id' => DB::table('users')->orderBy('id')->value('id'),
            'category' => 'flour',
            'title' => self::TITLE,
            'amount' => self::SACKS * self::PER_SACK,
            'spent_on' => self::SPENT_ON,
            'note' => 'سهمیه مرداد ۱۴۰۵: ۳۴۳ کیسه. پیش از این فقط ۱۴۴ کیسه '
                .'(۶٬۹۱۲٬۰۰۰ تومان) در هزینه‌ها ثبت شده بود؛ باقی از راه '
                .'«اصلاح موجودی» وارد انبار شده بود بدون آنکه بهایش ثبت شود.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('expenses')
            ->where('category', 'flour')
            ->where('title', self::TITLE)
            ->delete();
    }
};
