<?php

use App\Models\Expense;
use Illuminate\Database\Migrations\Migration;

/**
 * Puts right the costs a Rial shop entered from the phone.
 *
 * The app sends the amount exactly as it was typed, in the shop's display
 * unit. Every other endpoint converted it to Toman before storing;
 * ExpenseController did not, so a shop set to Rial had each cost saved ten
 * times its real value — and the figure fed the reports, the profit split
 * and the bank balances built on it.
 *
 * Saved through the model rather than a raw UPDATE, deliberately: the bank
 * posting is rebuilt on save, so the transaction behind each cost comes
 * back down with it. A raw UPDATE would fix the cost and leave the account
 * still counting ten times the money.
 *
 * Every row present when this runs is corrected. The controller fix ships
 * in the same release, so anything recorded afterwards is already right.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rescale(1 / 10);
    }

    public function down(): void
    {
        $this->rescale(10);
    }

    private function rescale(float $factor): void
    {
        Expense::query()->orderBy('id')->each(function (Expense $expense) use ($factor) {
            $expense->amount = round((float) $expense->amount * $factor, 2);
            $expense->save();
        });
    }
};
