<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing out what a seller owes.
 *
 * Cash they are holding, a gap between the money taken and the bread sold,
 * and bread nobody paid for are all theirs to hand over. Credit is not:
 * that money is still with the customer, so it stays on the account until
 * the customer pays and cannot be settled by the seller handing over
 * money they never collected.
 */
class SellerSettlement
{
    /** What the seller can hand over today, by component. */
    public static function outstandingFor(User $seller): array
    {
        $sales = Sale::query()
            ->where('user_id', $seller->id)
            ->sellerAccountOutstanding()
            ->get();

        $cash = round($sales->sum(fn (Sale $s) => $s->cash_held), 2);
        $difference = round($sales->sum(fn (Sale $s) => $s->open_difference), 2);
        $shortfall = round($sales->sum(fn (Sale $s) => $s->open_shortfall), 2);

        return [
            'cash' => $cash,
            'difference' => $difference,
            'shortfall' => $shortfall,
            // The gap counts against them, so a shortfall in what they
            // handed over adds to the total rather than reducing it.
            'total' => round($cash + $shortfall - $difference, 2),
            'credit' => round($sales->sum(fn (Sale $s) => $s->open_credit), 2),
        ];
    }

    /**
     * Marks everything the seller can settle as settled. Credit is left
     * alone deliberately — see the class comment.
     */
    public static function settle(User $seller): void
    {
        DB::transaction(function () use ($seller) {
            Sale::query()
                ->where('user_id', $seller->id)
                ->whereNull('cash_settled_on')
                ->where(function ($q) {
                    $q->whereIn('payment_type', Sale::CASH_TYPES)
                        ->orWhere('amount_difference', '!=', 0);
                })
                ->update(['cash_settled_on' => now()]);

            Sale::query()
                ->where('user_id', $seller->id)
                ->whereNull('shortfall_settled_on')
                ->where('shortfall_count', '>', 0)
                ->update(['shortfall_settled_on' => now()]);
        });
    }

    /**
     * Confirms a request and settles the account in one step.
     *
     * The card share is posted into a bank account, because that money
     * really did arrive there — cash stays in the till and is not a bank
     * movement, so only the card part is recorded against an account.
     */
    public static function confirm(
        SettlementRequest $request,
        User $admin,
        ?BankAccount $account = null,
    ): void {
        DB::transaction(function () use ($request, $admin, $account) {
            self::settle($request->user);

            $card = (float) $request->paid_card;
            $account ??= BankAccount::where('is_default', true)->first();

            if ($card > 0 && $account) {
                $account->record(
                    'in',
                    $card,
                    'sale',
                    $admin->id,
                    $request,
                    'تسویه کارتخوان — '.$request->user->name,
                );
            }

            $request->update([
                'confirmed_at' => now(),
                'confirmed_by' => $admin->id,
                'bank_account_id' => $card > 0 ? $account?->id : null,
            ]);
        });
    }
}
