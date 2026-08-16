<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Database\Migrations\Migration;

/**
 * The 15,800,000 Rial the bank was short of the books.
 *
 * With 18 Mordad finally recorded, حساب سفید read 609,873,258 against a
 * real balance of 594,073,258 — money that had left the account and was
 * written down nowhere. The owner identified it on 2026-08-16 as a
 * personal purchase, and it is filed the way his other withdrawals are: a
 * hand-entered movement on the account, not an expense of the bakery. It
 * cost the shop nothing, so it must not reduce the shop's profit.
 *
 * This is the last of the reconciliation. After it the account agrees with
 * the bank to the Rial, which is the first time it has since the shop
 * opened.
 *
 * Dated today rather than guessed at. The figure is the difference across
 * the whole period, so there is no day in it that is more true than
 * another, and inventing one would put a fact where there is none. The
 * note says as much, and the row is editable from the statement screen if
 * the receipt turns up.
 */
return new class extends Migration
{
    /** 15,800,000 Rial, stored in Toman like every other amount. */
    private const AMOUNT = 1_580_000;

    private const NOTE = 'برداشت شخصی: خرید شخصی — تاریخ دقیقش معلوم نیست';

    public function up(): void
    {
        $account = BankAccount::where('title', 'حساب سفید')->first();

        // Absent on a fresh database, and on any install that is not this
        // shop's. Nothing to reconcile there.
        if (! $account || BankTransaction::where('note', self::NOTE)->exists()) {
            return;
        }

        BankTransaction::create([
            'bank_account_id' => $account->id,
            'user_id' => 1,          // عبدالناصر ملازهی
            'direction' => 'out',
            'amount' => self::AMOUNT,
            'reason' => 'manual',
            'occurred_on' => '2026-08-16',
            'note' => self::NOTE,
        ]);
    }

    public function down(): void
    {
        BankTransaction::where('note', self::NOTE)->delete();
    }
};
