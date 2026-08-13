<?php

use App\Models\FlourAllocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The month's diesel arrived and was burned, and none of it was recorded.
 *
 * 173 sacks had gone into dough — 1,125 litres at the month's rate — with
 * no delivery on file at all, so the tank read 1,125 litres in the red and
 * the quota read untouched. Both were wrong in the same direction: the
 * shop looked entitled to fuel it had already drawn.
 *
 * Two things the owner did not say and this has to assume, both easy to
 * correct from the panel or the app afterwards:
 *
 *   - the date. Dated to the start of the quota month rather than today,
 *     because the fuel was plainly there before the 173 sacks were baked
 *     and a delivery dated after its own consumption reads as nonsense.
 *   - the price. Left null, which the system reads as "came off quota,
 *     carried no invoice". Inventing a figure would put money into the
 *     books that nobody paid.
 *
 * Guarded on the exact state it corrects — this month's quota, no
 * delivery yet recorded against it — so it cannot fire twice, and cannot
 * fire on a shop that has been keeping its dockets.
 */
return new class extends Migration
{
    private const LITRES = 2230;

    public function up(): void
    {
        $quota = DB::table('diesel_allocations')->orderByDesc('month_start')->first();

        if (! $quota) {
            return;
        }

        // Anything already on file means the shop is recording deliveries,
        // and a bulk entry beside them would double the month.
        [$periodStart] = FlourAllocation::periodRange(Carbon::parse($quota->month_start), 1);

        $already = DB::table('diesel_deliveries')
            ->where('bakery_id', $quota->bakery_id)
            ->whereDate('received_on', '>=', $periodStart->toDateString())
            ->exists();

        if ($already) {
            return;
        }

        DB::table('diesel_deliveries')->insert([
            'bakery_id' => $quota->bakery_id,
            'user_id' => DB::table('users')->orderBy('id')->value('id'),
            'received_on' => $periodStart->toDateString(),
            'litres' => self::LITRES,
            'amount' => null,
            'docket_number' => null,
            'note' => 'سهمیه کامل دوره (۵ تا ۴ ماه بعد)، یکجا ثبت شد. '
                .'تاریخ و شماره حواله بعداً قابل اصلاح است.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $quota = DB::table('diesel_allocations')->orderByDesc('month_start')->first();

        if ($quota) {
            [$periodStart] = FlourAllocation::periodRange(Carbon::parse($quota->month_start), 1);

            DB::table('diesel_deliveries')
                ->where('bakery_id', $quota->bakery_id)
                ->whereDate('received_on', $periodStart->toDateString())
                ->where('litres', self::LITRES)
                ->delete();
        }
    }
};
