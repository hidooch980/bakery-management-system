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
    /**
     * What the seller can hand over today, by component.
     *
     * @param  array<int>|null  $saleIds  Restricts the figure to these sales,
     *                                    for a seller settling only part of
     *                                    what they owe. Null means all of it.
     */
    public static function outstandingFor(User $seller, ?array $saleIds = null): array
    {
        $sales = self::outstandingSales($seller, $saleIds)->get();

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
     * The seller's open sales, newest first, optionally narrowed to a
     * chosen few.
     *
     * The id filter is applied to the seller's own rows only, so a seller
     * cannot reach another's sale by putting its id in the request.
     *
     * @param  array<int>|null  $saleIds
     */
    public static function outstandingSales(User $seller, ?array $saleIds = null)
    {
        $query = Sale::query()
            ->where('user_id', $seller->id)
            ->sellerAccountOutstanding();

        if ($saleIds !== null) {
            $query->whereIn('id', $saleIds);
        }

        return $query->latest();
    }

    /**
     * Marks what the seller can settle as settled. Credit is left alone
     * deliberately — see the class comment.
     *
     * @param  array<int>|null  $saleIds  Only these sales are closed, for a
     *                                    partial handover. Null closes all.
     */
    public static function settle(User $seller, ?array $saleIds = null): void
    {
        DB::transaction(function () use ($seller, $saleIds) {
            $cash = Sale::query()
                ->where('user_id', $seller->id)
                ->whereNull('cash_settled_on')
                ->where(function ($q) {
                    $q->whereIn('payment_type', Sale::CASH_TYPES)
                        ->orWhere('amount_difference', '!=', 0);
                });

            $shortfall = Sale::query()
                ->where('user_id', $seller->id)
                ->whereNull('shortfall_settled_on')
                ->where('shortfall_count', '>', 0);

            if ($saleIds !== null) {
                $cash->whereIn('id', $saleIds);
                $shortfall->whereIn('id', $saleIds);
            }

            $cash->update(['cash_settled_on' => now()]);
            $shortfall->update(['shortfall_settled_on' => now()]);
        });
    }

    /**
     * Settles the account and banks whatever came in on the card reader.
     *
     * The two halves of a handover land in different places: cash stays in
     * the till and is no bank movement at all, while the card share really
     * did arrive in an account and has to be recorded there or the bank
     * balance reads short by exactly what the seller paid that way.
     *
     * @param  float  $card  Toman taken on the reader, not the whole handover.
     * @param  mixed  $source  What the movement is recorded against — a
     *                         settlement request, or null when an admin
     *                         settled the account directly.
     * @param  array<int>|null  $saleIds  Only these sales are closed, for a
     *                                    partial handover. Null closes all.
     * @return BankAccount|null The account the card share went to, if any.
     */
    public static function settleWithMethod(
        User $seller,
        User $admin,
        float $card,
        ?BankAccount $account = null,
        mixed $source = null,
        ?array $saleIds = null,
    ): ?BankAccount {
        return DB::transaction(function () use ($seller, $admin, $card, $account, $source, $saleIds) {
            self::settle($seller, $saleIds);

            $account ??= BankAccount::where('is_default', true)->first();

            if ($card <= 0 || ! $account) {
                return null;
            }

            $account->record(
                'in',
                $card,
                'sale',
                $admin->id,
                $source,
                'تسویه کارتخوان — '.$seller->name,
            );

            return $account;
        });
    }

    /**
     * Confirms a request and settles the account in one step.
     */
    public static function confirm(
        SettlementRequest $request,
        User $admin,
        ?BankAccount $account = null,
    ): void {
        DB::transaction(function () use ($request, $admin, $account) {
            // A request that named its sales closes only those; one from an
            // older copy of the app named none and still means all of them.
            $banked = self::settleWithMethod(
                $request->user,
                $admin,
                (float) $request->paid_card,
                $account,
                $request,
                $request->sale_ids,
            );

            $request->update([
                'confirmed_at' => now(),
                'confirmed_by' => $admin->id,
                'bank_account_id' => $banked?->id,
            ]);
        });
    }
}
