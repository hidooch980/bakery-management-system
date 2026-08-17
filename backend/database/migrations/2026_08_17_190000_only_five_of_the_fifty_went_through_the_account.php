<?php

use App\Models\BankAccount;
use App\Models\LoanPayment;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The instalment was 50,000,000 Rial. Only 5,000,000 of it left حساب سفید.
 *
 * Both are the owner's own words and the bank proves the second. He gave
 * the statement figure — 592,573,258 Rial before today's card takings —
 * and the arithmetic closes to the rial only if this account saw
 * 5,000,000 leave, not 50,000,000. He then said the payment was
 * 50,000,000 Rial, twice more.
 *
 * Both hold. The other 45,000,000 was paid from somewhere this system does
 * not track — cash, or an account it has never been told about. That is
 * the only reading under which the loan and the bank can both be right,
 * and it is not a guess about the amount: the amount is his, and the
 * account's share is the statement's.
 *
 * So the payment carries the whole 50,000,000 against the loan and names
 * no account, which stops it posting; and the 5,000,000 that did go
 * through حساب سفید is written as its own withdrawal, tagged to nothing,
 * because that is what the statement says happened.
 *
 * Three corrections to this one figure today, each on better information
 * than the last. The first two were wrong and reversible on purpose. What
 * settled it was not a better guess — it was the owner reading his own
 * statement, which is the only instrument that was ever going to.
 *
 * Also here: 1,500,000 Rial drawn yesterday for petrol, which he
 * mentioned and which the reconciliation needs. Rial rather than Toman
 * because that is the unit he types in and because at Rial the account
 * lands on his figure exactly.
 */
return new class extends Migration
{
    /** Stored in Toman throughout. */
    private const PAID_TOTAL = 5_000_000;      // 50,000,000 Rial

    private const THROUGH_ACCOUNT = 500_000;   // 5,000,000 Rial

    private const PETROL = 150_000;            // 1,500,000 Rial

    private const PAID_ON = '2026-07-30';

    public function up(): void
    {
        DB::transaction(function () {
            $account = BankAccount::withoutGlobalScopes()
                ->where('is_cash_box', false)
                ->oldest('id')
                ->first();

            if (! $account) {
                return;
            }

            $this->splitTheInstalment($account);
            $this->petrol($account);
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Undoing it would put back a state where the bank disagrees with the
     * owner's own statement by 45,000,000 Rial. If the split turns out to
     * be wrong, the honest fix is a new correction that says so.
     */
    public function down(): void
    {
        // Nothing.
    }

    private function splitTheInstalment(BankAccount $account): void
    {
        $payment = LoanPayment::withoutGlobalScopes()
            ->whereDate('paid_on', self::PAID_ON)
            ->first();

        // Every one of these has to match or this is not the row this was
        // written about — a fresh database, or any install but this shop's.
        if (! $payment
            || abs((float) $payment->amount - self::PAID_TOTAL) > 0.01
            || $payment->bank_account_id !== $account->id) {
            return;
        }

        // Naming no account stops PostsToBankAccount writing a withdrawal,
        // and its saved hook clears the 50,000,000 one already there.
        $payment->bank_account_id = null;
        $payment->note = trim(($payment->note ?? '').' از این مبلغ فقط '
            .Money::format(self::THROUGH_ACCOUNT).' از حساب سفید رفته؛ باقی از منبع دیگری.');
        $payment->save();

        // What the statement actually shows leaving, standing on its own.
        $account->record(
            'out',
            self::THROUGH_ACCOUNT,
            'loan',
            $payment->user_id,
            null,
            'سهم حساب سفید از قسط وام صادرات — باقی مبلغ از این حساب نرفته.',
            self::PAID_ON,
        );
    }

    private function petrol(BankAccount $account): void
    {
        $on = '2026-08-16';

        $already = $account->transactions()
            ->whereDate('occurred_on', $on)
            ->where('amount', self::PETROL)
            ->exists();

        if ($already) {
            return;
        }

        $account->record('out', self::PETROL, 'expense', null, null, 'برداشت بنزین', $on);
    }
};
