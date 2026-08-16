<?php

use App\Models\BankAccount;
use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The till, which the shop has always had and the system never did.
 *
 * One account existed — حساب سفید — and only card takings reach it. Cash
 * sales named no account at all, so the money was known to have been taken
 * and not known to be anywhere. There was no figure to count the drawer
 * against, and cash spent out of it reduced nothing.
 *
 * Every cash sale is moved onto it, not only the ones from here. A till
 * holding one day's takings would be worse than none: it would look
 * countable and be wrong.
 *
 * حساب سفید is untouched, so the bank reconciliation is unaffected — this
 * moves nothing between accounts, it gives money that was nowhere a place
 * to be.
 *
 * Opening at zero, because the shop's own float is not known. Whatever is
 * actually in the drawer beyond what has been taken since today is for the
 * owner to enter as an opening figure once he has counted it.
 *
 * What this does not yet do, and tomorrow should: take cash expenses out
 * of it, and put debts collected in cash into it. Both need the collection
 * screen to start asking whether it was cash or card, which it does not.
 */
return new class extends Migration
{
    private const TITLE = 'صندوق نقد';

    public function up(): void
    {
        DB::transaction(function () {
            // Only where there is cash to put in it. A database with no cash
            // sales is a fresh install or a test one, and a till conjured
            // there is an account nobody asked for — three tests that count
            // the shop's accounts exactly failed on precisely that.
            $hasCash = Sale::where('payment_type', 'cash')->exists();

            if (! $hasCash || BankAccount::where('title', self::TITLE)->exists()) {
                return;
            }

            $till = BankAccount::create([
                'title' => self::TITLE,
                'opening_balance' => 0,
                'is_active' => true,
                // حساب سفید stays the default: it is where the card reader
                // pays, which is all but a hundredth of what comes in.
                'is_default' => false,
            ]);

            // Saving each one rather than a mass update, so the posting that
            // puts the money into the till is actually written — a bulk
            // update writes the column and no transaction with it.
            Sale::where('payment_type', 'cash')
                ->whereNull('bank_account_id')
                ->get()
                ->each(fn (Sale $sale) => $sale->update(['bank_account_id' => $till->id]));
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $till = BankAccount::where('title', self::TITLE)->first();

            if (! $till) {
                return;
            }

            Sale::where('bank_account_id', $till->id)
                ->get()
                ->each(fn (Sale $sale) => $sale->update(['bank_account_id' => null]));

            $till->delete();
        });
    }
};
