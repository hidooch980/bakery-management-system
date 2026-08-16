<?php

use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The day's selling, for the day the system was down.
 *
 * 18 Mordad 1405 baked 777 chane and recorded none of it. The baking went
 * in with the migration before this one; this is where it went, as the
 * owner counted it on 2026-08-15:
 *
 *     718 card · 11 home · 5 cash · 8 personal · 3 charity  = 745
 *     777 baked − 745 out                                   =  32 short
 *
 * The shortfall lands on the seller, which is what the shop already does
 * with every other day's — it is not written here, it follows from the
 * count.
 *
 * Home, charity and the eight the owner took carry no amount at all. That
 * is how every other row of those kinds is stored, and it is right: bread
 * that leaves without money is not a sale that went unpaid, and pricing it
 * would put the cost of everything given away onto whoever handed it over.
 *
 * Cash is left without an account here and swept into the till by the
 * migration after this one, along with every other cash sale the shop has
 * ever taken. Only the card takings name the bank, because only they
 * actually reach it.
 *
 * **This does not balance the bank, and is not meant to.** With these in,
 * the account reads 532,973,258 against a real balance of 516,973,258 —
 * 16,000,000 Rial too high, which is an expense that left the bank and was
 * never written down. The owner is checking. Bending the card figure to
 * close that gap is what the «اختلاف» row did, and it came out of these
 * books two days ago.
 */
return new class extends Migration
{
    private const WHEN = '2026-08-09 16:50:00';

    private const SELLER = 4;   // محمد حنیف محمودی فر

    private const BAKED = 777;

    /** [payment type, loaves, does it carry money, note] */
    private const LINES = [
        ['card', 718, true, null],
        ['home', 11, false, null],
        ['cash', 5, true, null],
        ['home', 8, false, 'شخصی'],
        ['charity', 3, false, null],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $chane = ChaneEntry::whereDate('created_at', '2026-08-09')->first();

            // Nothing to sell if the baking is not there — a fresh database,
            // or any install that is not this shop's. The seller has to be
            // there too: a test database is migrated before it is seeded, and
            // a sale hung on a user who does not exist breaks the foreign key
            // and takes the whole suite down at setUp.
            if (! $chane || ! User::whereKey(self::SELLER)->exists()
                || Sale::whereDate('created_at', '2026-08-09')->exists()) {
                return;
            }

            $sold = collect(self::LINES)->sum(fn (array $line) => $line[1]);
            $short = self::BAKED - $sold;

            foreach (self::LINES as [$type, $loaves, $carriesMoney, $note]) {
                $sale = Sale::create([
                    'chane_entry_id' => $chane->id,
                    'user_id' => self::SELLER,
                    'payment_type' => $type,
                    'bread_count' => $loaves,
                    'amount' => $carriesMoney ? $loaves * 10_000 : null,
                    // Only the card takings reach the bank.
                    'bank_account_id' => $type === 'card' ? 1 : null,
                    // Carried on the card line, which is the one the day is
                    // reckoned against, exactly as the other days store it.
                    'shortfall_count' => $type === 'card' ? $short : null,
                    'shortfall_amount' => $type === 'card' ? $short * 10_000 : null,
                    'note' => $note,
                ]);

                $sale->forceFill(['created_at' => self::WHEN, 'updated_at' => self::WHEN])->save();
            }

            $chane->update(['status' => 'sold']);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            Sale::whereDate('created_at', '2026-08-09')->get()->each->delete();

            ChaneEntry::whereDate('created_at', '2026-08-09')
                ->update(['status' => 'pending']);
        });
    }
};
