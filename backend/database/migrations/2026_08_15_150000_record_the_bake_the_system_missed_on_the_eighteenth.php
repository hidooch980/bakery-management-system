<?php

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 18 Mordad 1405 was baked and never recorded.
 *
 * The system was down that day, so the shop's books show no dough, no
 * chane, no sale and no attendance for it — a working day sitting in the
 * middle of the month looking like a closure. The owner confirmed on
 * 2026-08-15 what was actually done: 10 bags, 777 chane.
 *
 * That matches the days either side almost exactly — 10 bags and around
 * 770 chane on the 16th and 17th — so nothing here is unusual except that
 * it went unwritten.
 *
 * Only the baking is recorded here. The day's sales are not: the shop
 * counted 718 loaves through the card reader, but the bank is only
 * 55,800,000 Rial short, which is 558 loaves, and 160 loaves is too large
 * a gap to write past. Money entered to make a balance agree is what the
 * «اختلاف» row was, and that came out of these books yesterday.
 *
 * Values taken from the surrounding days, all of which are identical in
 * shape: dry yeast, one tray, 5 kg of spray flour. The chane weight is
 * derived, not guessed — 777 × 0.85 kg, the shop's own normal chane
 * weight, which is exactly how every other day in the table computes.
 */
return new class extends Migration
{
    private const WHEN = '2026-08-09 16:45:00';

    private const BAGS = 10;

    private const CHANE = 777;

    private const MIXER = 2;        // عبدالله خوشنواز

    private const CHANE_GIR = 3;    // سعید غلامزهی

    public function up(): void
    {
        DB::transaction(function () {
            // Guarded so a re-run passes straight through — and so does any
            // database that is not this shop's. A test database is migrated
            // before it is seeded, so the two staff named below are not there
            // and creating their work would break the foreign key, taking
            // every test in the suite down with it at setUp.
            $staffPresent = User::whereKey([self::MIXER, self::CHANE_GIR])->count() === 2;

            if (! $staffPresent || DoughEntry::whereDate('created_at', '2026-08-09')->exists()) {
                return;
            }

            $dough = DoughEntry::create([
                'user_id' => self::MIXER,
                'bag_count' => self::BAGS,
                'yeast_type' => 'dry',
                'status' => 'processed',
                'note' => 'ثبت با تأخیر — روز ۱۸ مرداد سیستم قطع بود و پخت آن روز ثبت نشد.',
            ]);

            $dough->forceFill(['created_at' => self::WHEN, 'updated_at' => self::WHEN])->save();

            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => self::CHANE_GIR,
                'chane_count' => self::CHANE,
                'tray_count' => 1,
                'tray_counts' => [self::CHANE],
                // 777 × 0.85 kg — the shop's normal chane weight, the same
                // arithmetic every other row in this table already carries.
                'normal_weight_kg' => round(self::CHANE * 0.85, 2),
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 5,
                // Not "sold": the sales for this day are still being
                // settled against the card reader's own figure.
                'status' => 'pending',
            ]);

            $chane->forceFill(['created_at' => self::WHEN, 'updated_at' => self::WHEN])->save();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $dough = DoughEntry::whereDate('created_at', '2026-08-09')->first();

            if (! $dough) {
                return;
            }

            ChaneEntry::where('dough_entry_id', $dough->id)->delete();
            $dough->delete();
        });
    }
};
