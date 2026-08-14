<?php

use App\Models\BankTransaction;
use App\Models\Expense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Bank Saderat instalment is a debt being repaid, not bread being baked.
 *
 * 5,000,000 Rial went out on 2026-07-30 under «وام صادرات» and sat in the
 * expense book as though it were a cost of running the shop. It is not: the
 * loan bought a bakery machine, and paying an instalment reduces what is
 * owed rather than buying anything. Counting it as an expense understates
 * the profit by the instalment every month it is paid, and understates it
 * again for as long as the loan runs.
 *
 * The machine itself was the purchase. If the shop ever books that, it
 * belongs under تجهیزات — this row is not it.
 *
 * BankTransaction already has 'loan' => 'قسط وام' for exactly this, so the
 * withdrawal stays where it is with the right reason on it. Same amount,
 * same date, same account: the balance does not move.
 *
 * Worth knowing, and outside what a migration can fix: nothing in this
 * system records the loan itself. The instalments will be visible one by
 * one and the outstanding balance nowhere, so how much is still owed on the
 * machine has to be kept track of off the books for now.
 *
 * Owner confirmed on 2026-08-15.
 */
return new class extends Migration
{
    private const TITLE = 'وام صادرات';

    private const NOTE = 'قسط وام صادرات — گرفته‌شده برای خرید دستگاه نانوایی';

    public function up(): void
    {
        DB::transaction(function () {
            $expense = Expense::withoutGlobalScopes()
                ->where('title', self::TITLE)
                ->first();

            if (! $expense) {
                return;
            }

            BankTransaction::create([
                'bank_account_id' => $expense->bank_account_id,
                'user_id' => $expense->user_id,
                'direction' => 'out',
                'amount' => $expense->amount,
                'reason' => 'loan',
                'occurred_on' => $expense->spent_on,
                'note' => self::NOTE,
            ]);

            // Clears the posting the expense had made, so the money leaves
            // the account once rather than twice.
            $expense->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $row = BankTransaction::whereNull('source_type')
                ->where('note', self::NOTE)
                ->first();

            if (! $row) {
                return;
            }

            Expense::withoutGlobalScopes()->create([
                'user_id' => $row->user_id,
                'category' => 'other',
                'title' => self::TITLE,
                'amount' => $row->amount,
                'spent_on' => $row->occurred_on,
                'bank_account_id' => $row->bank_account_id,
            ]);

            $row->delete();
        });
    }
};
