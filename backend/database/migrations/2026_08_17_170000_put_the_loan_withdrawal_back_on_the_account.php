<?php

use App\Models\BankAccount;
use App\Models\LoanPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The loan payment lost its withdrawal, and I took it off.
 *
 * The 8 Mordad instalment was entered before the loan itself existed, so
 * on 2026-08-16 I attached it by pointing an already-recorded bank
 * withdrawal at the new LoanPayment row rather than writing a second one —
 * writing one would have taken the money out of the account twice.
 *
 * What that left behind was a payment with a posting and no
 * `bank_account_id`. PostsToBankAccount rebuilds on save: it clears every
 * posting tagged to the record, then writes a fresh one *if the record
 * names an account*. It named none. So correcting the amount from
 * 5,000,000 to 50,000,000 Rial deleted the withdrawal and wrote nothing in
 * its place, and حساب سفید went **up** by 5,000,000 when it should have
 * gone down by 45,000,000.
 *
 * Caught within the minute because the balance was read before and after
 * rather than assumed. It would not have shown up in any test: no test
 * owns a record whose posting was attached by hand.
 *
 * Naming the account is the whole fix. Once the payment says which account
 * it came out of, saving it writes the 50,000,000 withdrawal the way every
 * other payment gets one, and the same edit made through the panel from
 * now on behaves.
 */
return new class extends Migration
{
    /** Stored in Toman: 5,000,000 Toman is 50,000,000 Rial. */
    private const AMOUNT = 5_000_000;

    public function up(): void
    {
        DB::transaction(function () {
            $payment = LoanPayment::withoutGlobalScopes()->first();

            if (! $payment
                || $payment->bank_account_id !== null
                || abs((float) $payment->amount - self::AMOUNT) > 0.01) {
                return;
            }

            // حساب سفید — the account the instalment actually left, and the
            // one every other withdrawal on this shop comes out of. Found
            // by not being the till rather than by its name, which is how
            // the rest of the system finds it.
            $account = BankAccount::withoutGlobalScopes()
                ->where('is_cash_box', false)
                ->oldest('id')
                ->first();

            if (! $account) {
                return;
            }

            $payment->bank_account_id = $account->id;

            // Saving is what writes the posting; the trait does the rest.
            $payment->save();
        });
    }

    /**
     * Not reversible. Undoing it would delete the withdrawal again and
     * leave the account reading 45,000,000 Rial richer than it is, which
     * is the state this migration exists to get out of.
     */
    public function down(): void
    {
        // Nothing.
    }
};
