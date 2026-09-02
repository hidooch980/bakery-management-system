<?php

use App\Models\FlourSale;
use Illuminate\Database\Migrations\Migration;

/**
 * Puts one sack of flour under the heading it actually belongs to.
 *
 * Flour sale #3, 1405/05/12: one sack, forty kilos, «نقدی», zero rial.
 * It read as a cash sale whose price somebody forgot, and a report said
 * so. Asked about it, the owner answered «آرد خودم بود مجانی» — it was
 * his own flour, taken free, and the record simply had nowhere to say
 * that until «منزل» was recognised as a category flour sales could use.
 *
 * Nothing moves but the label. The amount is zero and the sale names no
 * bank account, so `PostsToBankAccount` has nothing to post either way;
 * the ledger sums `amount` and not the type, so no income figure shifts;
 * and the forty kilos left the warehouse when it was recorded, which was
 * true then and stays true.
 *
 * Guarded on the row still looking the way it did: if somebody has
 * already corrected it, or the amount is no longer zero, this does
 * nothing rather than overwriting a later decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sale = FlourSale::find(3);

        if (! $sale || $sale->payment_type !== 'cash' || (float) $sale->amount !== 0.0) {
            echo "  فروش آرد #3 آن‌طور که انتظار می‌رفت نیست — دست نخورد.\n";

            return;
        }

        $sale->update(['payment_type' => 'home']);

        echo "  فروش آرد #3: «نقدی» → «منزل» (۴۰ کیلو، مبلغ صفر)\n";
    }

    public function down(): void
    {
        $sale = FlourSale::find(3);

        if ($sale && $sale->payment_type === 'home' && (float) $sale->amount === 0.0) {
            $sale->update(['payment_type' => 'cash']);
        }
    }
};
