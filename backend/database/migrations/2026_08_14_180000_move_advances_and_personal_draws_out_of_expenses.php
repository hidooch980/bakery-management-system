<?php

use App\Models\Expense;
use App\Models\StaffAdvance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thirteen entries in the expense book were not expenses.
 *
 * Three were advances against wages, written as "حقوق" because that is the
 * nearest category the form offered. Five were the owner's own money going
 * out — «خودم», «کار منزل», «خرید سنگ», and one 702,926,025 Rial line
 * titled «اختلاف» that had been entered to make the books balance and was
 * carrying more than half of every cost the shop appeared to have.
 *
 * Together they made the shop read as though it had lost money over its
 * first eighteen days. It had not.
 *
 * An advance is pay brought forward, not a cost -- StaffAdvance says so
 * itself -- so it belongs on the advance ledger, where the next payslip
 * recovers it. Deleting these outright was not an option: every one of them
 * really did leave the bank, and removing the expense without putting
 * something in its place would have lifted the balance by 967,426,025 Rial
 * and stopped it matching the bank. Deleting an expense clears its bank
 * posting and saving an advance writes one back, so the account is left
 * exactly where it was and only the reason for the withdrawal changes.
 *
 * Owner: عبدالناصر ملازهی confirmed on 2026-08-14 which were personal and
 * that the third unnamed advance was محمد حنیف's.
 *
 * Amounts are read off the row rather than restated here, so nothing is
 * re-derived through the display unit on the way across.
 */
return new class extends Migration
{
    /**
     * Expense id => [who it belongs to, what to write on the advance].
     *
     * The title is matched as well as the id: a database where the ids
     * landed differently must not have a row converted out from under it.
     */
    private const MOVES = [
        30 => [2, 'علی الحساب عبدالله', 'علی‌الحساب — از دفتر هزینه منتقل شد'],
        34 => [4, 'علی الحساب محمد حنیف', 'علی‌الحساب — از دفتر هزینه منتقل شد'],
        42 => [4, 'علی الحساب', 'علی‌الحساب محمد حنیف — از دفتر هزینه منتقل شد'],
        33 => [1, 'خودم', 'برداشت شخصی — از دفتر هزینه منتقل شد'],
        43 => [1, 'خودم', 'برداشت شخصی — از دفتر هزینه منتقل شد'],
        36 => [1, 'کار منزل', 'شخصی: کار منزل — از دفتر هزینه منتقل شد'],
        37 => [1, 'خرید سنگ', 'شخصی: خرید سنگ — از دفتر هزینه منتقل شد'],
        40 => [1, 'اختلاف', 'شخصی — از دفتر هزینه منتقل شد'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::MOVES as $expenseId => [$userId, $title, $note]) {
                $expense = Expense::withoutGlobalScopes()->find($expenseId);

                // Absent on a fresh database, and on any install that is not
                // this shop's. Nothing to move, and nothing to complain about.
                if (! $expense || trim((string) $expense->title) !== $title) {
                    continue;
                }

                StaffAdvance::withoutGlobalScopes()->create([
                    'bakery_id' => $expense->bakery_id,
                    'user_id' => $userId,
                    'recorded_by' => $expense->user_id,
                    'amount' => $expense->amount,
                    'paid_on' => $expense->spent_on,
                    'bank_account_id' => $expense->bank_account_id,
                    'note' => $note,
                ]);

                $expense->delete();
            }
        });
    }

    /**
     * Puts them back where they were, category and all, so the change can be
     * undone if any of these turns out to have been a real cost after all.
     */
    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::MOVES as $expenseId => [$userId, $title, $note]) {
                $advance = StaffAdvance::withoutGlobalScopes()
                    ->where('user_id', $userId)
                    ->where('note', $note)
                    ->first();

                if (! $advance) {
                    continue;
                }

                Expense::withoutGlobalScopes()->create([
                    'bakery_id' => $advance->bakery_id,
                    'user_id' => $advance->recorded_by,
                    'category' => in_array($title, ['علی الحساب عبدالله', 'علی الحساب محمد حنیف', 'علی الحساب'], true)
                        ? 'salary'
                        : 'other',
                    'title' => $title,
                    'amount' => $advance->amount,
                    'spent_on' => $advance->paid_on,
                    'bank_account_id' => $advance->bank_account_id,
                ]);

                $advance->delete();
            }
        });
    }
};
