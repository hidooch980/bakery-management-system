<?php

use App\Support\Jalali;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The diesel quota for Mordad, at the rate the depot actually gave.
 *
 * The rate was carrying its 5-litre default because nobody had been asked;
 * this month the shop is allowed 6.5 litres a sack. The month's own figure
 * is written into the allocation row rather than left to be recomputed, so
 * a later change of rate cannot quietly restate a month that has already
 * been drawn against.
 *
 * The month is taken from the flour allocation rather than typed, because
 * the diesel quota is derived from it and the two have to agree about
 * which month they mean — `litresFor()` matches them on that exact date.
 *
 * The litres are the depot's own figure, not the arithmetic: 343 sacks at
 * 6.5 works out at 2,229.5, and the docket says 2,230. Half a litre is
 * nothing, but the number the shop can actually draw is the one on the
 * docket, and a derived figure that disagrees with it teaches everyone to
 * distrust the screen.
 */
return new class extends Migration
{
    private const LITRES_PER_BAG = 6.5;

    /** What the depot actually allowed this month. */
    private const LITRES_THIS_MONTH = 2230;

    public function up(): void
    {
        DB::table('bakeries')->update(['diesel_litres_per_bag' => self::LITRES_PER_BAG]);

        $flour = DB::table('flour_allocations')->orderByDesc('month_start')->first();

        if (! $flour || $flour->total_bags === null) {
            return;
        }

        $exists = DB::table('diesel_allocations')
            ->where('bakery_id', $flour->bakery_id)
            ->whereDate('month_start', $flour->month_start)
            ->exists();

        if ($exists) {
            return;
        }

        $monthStart = Carbon::parse($flour->month_start);

        DB::table('diesel_allocations')->insert([
            'bakery_id' => $flour->bakery_id,
            'month_start' => $flour->month_start,
            'month_label' => $flour->month_label ?? Jalali::monthLabel($monthStart),
            'total_litres' => self::LITRES_THIS_MONTH,
            'carryover_litres' => 0,
            'note' => 'سهمیه ماه بر پایه‌ی '.$flour->total_bags
                .' کیسه آرد، هر کیسه '.self::LITRES_PER_BAG.' لیتر.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $flour = DB::table('flour_allocations')->orderByDesc('month_start')->first();

        if ($flour) {
            DB::table('diesel_allocations')
                ->where('bakery_id', $flour->bakery_id)
                ->whereDate('month_start', $flour->month_start)
                ->delete();
        }

        DB::table('bakeries')->update(['diesel_litres_per_bag' => 5]);
    }
};
