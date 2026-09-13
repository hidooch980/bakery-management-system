<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SettlementRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        return self::totals(self::outstandingSales($seller, $saleIds)->get());
    }

    /**
     * The same answer for a list of sellers, in one query rather than one
     * each.
     *
     * The seller-accounts page asks for every seller at once, and asking
     * per seller put a query on the page for every person who has ever
     * sold bread — it grew with the staff list, which is exactly the shape
     * this project has been bitten by before.
     *
     * Sellers with nothing open still get an entry, so a caller can read
     * the result by id without checking whether it is there.
     *
     * @param  Collection<int, User>  $sellers
     * @return array<int, array<string, mixed>>
     */
    public static function outstandingForMany($sellers): array
    {
        $ids = $sellers->pluck('id')->all();

        $bySeller = $ids === []
            ? collect()
            : Sale::query()
                ->whereIn('user_id', $ids)
                ->sellerAccountOutstanding()
                ->get()
                ->groupBy('user_id');

        $totals = [];

        foreach ($ids as $id) {
            $totals[$id] = self::totals($bySeller->get($id) ?? collect());
        }

        return $totals;
    }

    /**
     * What a set of open sales comes to.
     *
     * Shared by the one-seller and the many-seller paths on purpose. Two
     * copies of this arithmetic would be two answers to «what does he owe»
     * the day somebody changed one of them.
     *
     * @param  Collection<int, Sale>  $sales
     */
    private static function totals($sales): array
    {
        $cash = round($sales->sum(fn (Sale $s) => $s->cash_held), 2);
        $difference = round($sales->sum(fn (Sale $s) => $s->open_difference), 2);
        $shortfall = round($sales->sum(fn (Sale $s) => $s->open_shortfall), 2);

        // Counted, not divided: the seller settles in loaves, and the
        // loaves are on the sales already. Dividing the money by today's
        // price would restate an old debt every time the price moved.
        $cashLoaves = (int) $sales->sum(
            fn (Sale $s) => $s->cash_held > 0 ? (int) $s->bread_count : 0
        );
        $shortfallLoaves = (int) $sales->sum(
            fn (Sale $s) => $s->open_shortfall > 0 ? (int) $s->shortfall_count : 0
        );

        return [
            'cash' => $cash,
            'difference' => $difference,
            'shortfall' => $shortfall,
            // The gap counts against them, so a shortfall in what they
            // handed over adds to the total rather than reducing it.
            'total' => round($cash + $shortfall - $difference, 2),
            'credit' => round($sales->sum(fn (Sale $s) => $s->open_credit), 2),
            'cash_loaves' => $cashLoaves,
            'shortfall_loaves' => $shortfallLoaves,
            'loaves' => $cashLoaves + $shortfallLoaves,
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
     * Settles the account and banks both halves of what was handed over.
     *
     * The two halves land in different places — the card share in a bank
     * account, the cash in the drawer — but they both land. Cash used to be
     * described here as «staying in the till», which was only true while
     * there was no till to stay in: the money was taken, the account was
     * marked clear, and nothing anywhere recorded where it went.
     *
     * The panel made that worse than a silent omission. It asks the owner
     * for the cash figure, refuses the form unless cash and card add up to
     * the account, and reports «نقد X • کارتخوان Y» back — then passed only
     * the card share in. Every one of those numbers was thrown away.
     *
     * Customer collections were fixed this way first
     * (SellerCollectionController); this is the same money in the same shop
     * arriving by a different door.
     *
     * @param  float  $cash  Toman handed over in notes, for the drawer.
     * @param  float  $card  Toman taken on the reader, not the whole handover.
     * @param  mixed  $source  What the movement is recorded against — a
     *                         settlement request, or null when an admin
     *                         settled the account directly.
     * @param  array<int>|null  $saleIds  Only these sales are closed, for a
     *                                    partial handover. Null closes all.
     * @return BankAccount|null Which account to name on the record: the one
     *                          the card reached, or the drawer when it was
     *                          all cash.
     */
    public static function settleWithMethod(
        User $seller,
        User $admin,
        float $cash,
        float $card,
        ?BankAccount $account = null,
        mixed $source = null,
        ?array $saleIds = null,
    ): ?BankAccount {
        return DB::transaction(function () use ($seller, $admin, $cash, $card, $account, $source, $saleIds) {
            self::settle($seller, $saleIds);

            return self::bankTheHandover($seller, $admin, $cash, $card, $account, $source);
        });
    }

    /**
     * Records both halves of a handover against the accounts they reached.
     *
     * Each half is posted only if it was actually handed over, so a pure
     * cash handover writes one row in the drawer and none in the bank.
     *
     * A missing drawer is not silently absorbed: the shop that has taken
     * cash and has nowhere to put it should say so rather than lose the
     * figure a second time, which is what the notes on these rows are for.
     */
    private static function bankTheHandover(
        User $seller,
        User $admin,
        float $cash,
        float $card,
        ?BankAccount $account,
        mixed $source,
    ): ?BankAccount {
        $account ??= BankAccount::defaultAccount();
        $named = null;

        if ($card > 0 && $account) {
            $account->record(
                'in',
                $card,
                'sale',
                $admin->id,
                $source,
                'تسویه کارتخوان — '.$seller->name,
            );

            $named = $account;
        }

        if ($cash > 0 && $till = BankAccount::cashBox()) {
            $till->record(
                'in',
                $cash,
                'sale',
                $admin->id,
                $source,
                'تسویه نقدی — '.$seller->name,
            );

            // Only when the card did not already name one: a split handover
            // is one row on the request and the bank is the more useful half
            // to point at, the drawer being where cash goes by definition.
            $named ??= $till;
        }

        return $named;
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
                $banked = self::bankTheHandover(
                    $request->user,
                    $admin,
                    (float) $request->paid_cash,
                    (float) $request->paid_card,
                    $account,
                    $request,
                );
            } else {
                $banked = self::settleWithMethod(
                    $request->user,
                    $admin,
                    (float) $request->paid_cash,
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
    /**
     * What one loaf is worth to the seller's account today.
     *
     * The shop settles in loaves — "I have accounted for five hundred" —
     * so the count has to become money somewhere, and this is the only
     * place it does.
     */
    public static function loafPrice(): float
    {
        return (float) (CurrentBakery::get()?->bread_price ?? 0);
    }

    /**
     * Settles a number of loaves rather than an amount.
     *
     * The loaves become money at today's price and are applied exactly as
     * a payment would be — oldest sale first, remainder held as credit.
     * Nothing downstream needs to know it arrived as a count, so there is
     * one settlement path rather than two that can drift apart.
     */
    public static function applyLoaves(
        User $seller,
        int $loaves,
        ?SettlementRequest $request = null,
    ): array {
        $price = self::loafPrice();

        // No price means no honest conversion. Settling at zero would mark
        // the debt paid and take nothing off it.
        if ($price <= 0) {
            throw new RuntimeException('قیمت نان در تنظیمات تعیین نشده است.');
        }

        return self::applyPayment($seller, round($loaves * $price, 2), $request);
    }

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
