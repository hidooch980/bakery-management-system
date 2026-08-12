<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
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

            return self::bankTheCardShare($seller, $admin, $card, $account, $source);
        });
    }

    /**
     * Records the card share of a handover against a bank account.
     *
     * Cash stays in the till and is no bank movement at all; the card share
     * really did arrive somewhere and has to be recorded or the balance
     * reads short by exactly what the seller paid that way.
     */
    private static function bankTheCardShare(
        User $seller,
        User $admin,
        float $card,
        ?BankAccount $account,
        mixed $source,
    ): ?BankAccount {
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
            // Three shapes of request, and they settle differently. One
            // that named its sales closes exactly those. One that named an
            // amount smaller than the account pays it down oldest first,
            // leaving the remainder as credit. One that named neither is
            // the whole account, which is what older copies of the app send.
            $owed = self::outstandingFor($request->user)['total'];
            $partialAmount = $request->sale_ids === null
                && (float) $request->amount > 0
                && (float) $request->amount < $owed - 0.01;

            if ($partialAmount) {
                self::applyPayment($request->user, (float) $request->amount, $request);
                $banked = self::bankTheCardShare(
                    $request->user,
                    $admin,
                    (float) $request->paid_card,
                    $account,
                    $request,
                );
            } else {
                $banked = self::settleWithMethod(
                    $request->user,
                    $admin,
                    (float) $request->paid_card,
                    $account,
                    $request,
                    $request->sale_ids,
                );
            }

            $request->update([
                'confirmed_at' => now(),
                'confirmed_by' => $admin->id,
                'bank_account_id' => $banked?->id,
            ]);
        });
    }

    /**
     * The running account: what the seller owes once the credit the shop is
     * already holding for them is taken off.
     *
     * This is the figure a seller recognises — one number they can pay
     * against — rather than a list of sales they have to reconcile.
     */
    public static function runningBalanceFor(User $seller): array
    {
        $owed = self::outstandingFor($seller);
        $credit = SellerAccountCredit::balanceFor($seller->id);

        return [
            'debt' => $owed['total'],
            'credit' => $credit,
            // Never below zero: money held beyond the debt is credit, not a
            // negative bill, and showing it as one reads as the shop owing
            // the seller for bread they have not sold yet.
            'balance' => round(max(0, $owed['total'] - $credit), 2),
            'components' => $owed,
        ];
    }

    /**
     * Applies a payment of any size to the account, oldest debt first.
     *
     * A sale settles whole, so the payment closes as many as it covers and
     * whatever is left over is held as credit against the next one. The
     * seller hands over what they have; the arithmetic is the shop's
     * problem, not theirs.
     *
     * @return array{settled: array<int>, credit_left: float}
     */
    public static function applyPayment(
        User $seller,
        float $amount,
        ?SettlementRequest $request = null,
    ): array {
        return DB::transaction(function () use ($seller, $amount, $request) {
            // Credit already on the account is spent first — it is the
            // seller's money and holding it back while asking for more
            // would be counting the same debt twice.
            $available = round($amount + SellerAccountCredit::balanceFor($seller->id), 2);

            if ($available > 0 && SellerAccountCredit::balanceFor($seller->id) > 0) {
                SellerAccountCredit::create([
                    'user_id' => $seller->id,
                    'amount' => -SellerAccountCredit::balanceFor($seller->id),
                    'settlement_request_id' => $request?->id,
                    'note' => 'اعتبار قبلی، خرج تسویه شد',
                ]);
            }

            $settled = [];

            // Oldest first: the debt that has been waiting longest is the
            // one the shop wants off the books.
            foreach (self::outstandingSales($seller)->reorder()->oldest('created_at')->oldest('id')->get() as $sale) {
                $cost = round($sale->seller_account_amount, 2);

                if ($cost <= 0) {
                    continue;
                }

                if ($cost > $available + 0.01) {
                    break;
                }

                self::settle($seller, [$sale->id]);
                $available = round($available - $cost, 2);
                $settled[] = $sale->id;
            }

            if ($available > 0.01) {
                SellerAccountCredit::create([
                    'user_id' => $seller->id,
                    'amount' => $available,
                    'settlement_request_id' => $request?->id,
                    'note' => 'باقی‌مانده تسویه',
                ]);
            }

            return ['settled' => $settled, 'credit_left' => max(0, $available)];
        });
    }
}
