<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Loan;
use App\Models\LoanPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two Bank Saderat loans that bought the bakery machine.
 *
 * The screens for this have existed since 2026-08-04 and were never filled
 * in, so the instalments went out one at a time as money leaving the
 * account and what was still owed on the machine lived nowhere but the
 * owner's memory. He gave the figures on 2026-08-16: 1,538,344,000 Rial
 * across two loans, 20,000,000 Rial each per month, due on the 10th.
 *
 * Split in half, because that is what the equal instalments say and he
 * confirmed the two are alike. If they turn out uneven, the principal of
 * either is one field on the loan's own page and nothing else moves —
 * what is still owed is counted from the payments, never stored.
 *
 * The 5,000,000 Rial that went out on 2026-07-30 under «وام صادرات» is
 * attached to the first of them. It was already recorded as a loan
 * instalment against the bank; this only gives it the loan it belongs to,
 * so the balance owed comes down by it. The account is untouched: the
 * existing withdrawal is reused rather than a second one written, which
 * would take the money out twice.
 */
return new class extends Migration
{
    private const TOTAL = 153_834_400;      // 1,538,344,000 Rial, in Toman

    private const INSTALMENT = 2_000_000;   // 20,000,000 Rial a month, each

    private const FIRST_DUE = '2026-08-01'; // 10 Mordad 1405

    public function up(): void
    {
        DB::transaction(function () {
            $account = BankAccount::where('title', 'حساب سفید')->first();

            // Absent on a fresh database and on any install that is not
            // this shop's. Nothing here belongs to those.
            if (! $account || Loan::where('lender', 'بانک صادرات')->exists()) {
                return;
            }

            $half = round(self::TOTAL / 2, 2);

            $first = Loan::create([
                'title' => 'وام صادرات ۱',
                'lender' => 'بانک صادرات',
                'principal' => $half,
                'instalment_amount' => self::INSTALMENT,
                'instalment_count' => (int) ceil($half / self::INSTALMENT),
                'first_due_on' => self::FIRST_DUE,
                'note' => 'خرید دستگاه نانوایی. سررسید قسط: دهم هر ماه.',
            ]);

            Loan::create([
                'title' => 'وام صادرات ۲',
                'lender' => 'بانک صادرات',
                'principal' => $half,
                'instalment_amount' => self::INSTALMENT,
                'instalment_count' => (int) ceil($half / self::INSTALMENT),
                'first_due_on' => self::FIRST_DUE,
                'note' => 'خرید دستگاه نانوایی. سررسید قسط: دهم هر ماه.',
            ]);

            $this->attachTheInstalmentAlreadyPaid($first, $account);
        });
    }

    /**
     * The one instalment on file, given the loan it belongs to.
     *
     * Its withdrawal is already on the account under «قسط وام». Saving a
     * LoanPayment with the account named would write a second one and take
     * the money out twice, so the payment is created without an account and
     * the existing row is pointed at it instead — the bank ends where it
     * started and the loan's balance comes down.
     */
    private function attachTheInstalmentAlreadyPaid(Loan $loan, BankAccount $account): void
    {
        $existing = BankTransaction::where('bank_account_id', $account->id)
            ->where('reason', 'loan')
            ->whereNull('source_type')
            ->first();

        if (! $existing) {
            return;
        }

        $payment = LoanPayment::create([
            'loan_id' => $loan->id,
            'user_id' => $existing->user_id,
            'amount' => $existing->amount,
            'paid_on' => $existing->occurred_on,
            'bank_account_id' => null,
            'note' => 'قسط پرداخت‌شده پیش از ثبت وام — برداشتش از قبل در گردش حساب هست.',
        ]);

        $existing->update([
            'source_type' => LoanPayment::class,
            'source_id' => $payment->id,
        ]);
    }

    public function down(): void
    {
        DB::transaction(function () {
            $loans = Loan::where('lender', 'بانک صادرات')->pluck('id');

            BankTransaction::whereIn('source_id', LoanPayment::whereIn('loan_id', $loans)->pluck('id'))
                ->where('source_type', LoanPayment::class)
                ->update(['source_type' => null, 'source_id' => null]);

            LoanPayment::whereIn('loan_id', $loans)->delete();
            Loan::whereIn('id', $loans)->delete();
        });
    }
};
