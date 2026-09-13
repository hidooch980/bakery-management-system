<?php

namespace App\Support;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\FlourSale;
use App\Models\Sale;
use App\Models\SettlementRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Putting back the cash the shop took and never recorded anywhere.
 *
 * Three holes were closed going forward: a seller's handover from the
 * phone banked nothing at all, the panel asked for the cash figure and
 * discarded it, and a flour sale with no account named moved no money.
 * None of that repaired what had already happened — the drawer was short
 * by every cash settlement and every counter sale the shop had ever made.
 *
 * **Only figures the shop actually wrote down are put back.** Where a
 * handover left no record of how much was cash, nothing is invented; it is
 * counted and reported instead, because a drawer holding money nobody
 * handed over is a worse answer than one that is short.
 *
 * Every posting is tied to the row it came from, and the posting is
 * rebuilt from that row rather than added to it, so running this twice
 * leaves the same ledger as running it once.
 */
class CashNeverBanked
{
    /**
     * What the repair would do, or has done, for one shop.
     *
     * @return array{
     *     flour_sales: int, flour_toman: float,
     *     settlements: int, settlement_toman: float,
     *     unrecorded_toman: float, has_till: bool
     * }
     */
    public static function auditFor(Bakery $bakery): array
    {
        return CurrentBakery::for($bakery->id, function () {
            $flour = self::flourSalesMissingTheirPosting()->get();
            $requests = self::settlementsMissingTheirCash()->get();

            return [
                'flour_sales' => $flour->count(),
                'flour_toman' => round((float) $flour->sum('amount'), 2),
                'settlements' => $requests->count(),
                'settlement_toman' => round((float) $requests->sum('paid_cash'), 2),
                'unrecorded_toman' => self::handedOverWithNoRecordOfHow(),
                'has_till' => BankAccount::cashBox() !== null,
            ];
        });
    }

    /**
     * Writes the missing postings for one shop.
     *
     * @return array{flour_sales: int, settlements: int}
     */
    public static function repairFor(Bakery $bakery): array
    {
        return CurrentBakery::for($bakery->id, function () {
            // Without a drawer there is nowhere to put any of it, and
            // inventing one would be this system deciding the shop has an
            // account it has never been told about.
            if (! BankAccount::cashBox()) {
                return ['flour_sales' => 0, 'settlements' => 0];
            }

            return DB::transaction(fn () => [
                'flour_sales' => self::repairFlourSales(),
                'settlements' => self::repairSettlements(),
            ]);
        });
    }

    /**
     * Counter sales of flour that moved no account.
     *
     * The same three conditions the model now applies when it decides
     * where a sale's money went, so this selects exactly the rows whose
     * posting the new rule would have written.
     */
    private static function flourSalesMissingTheirPosting()
    {
        return FlourSale::query()
            ->whereNull('bank_account_id')
            ->whereNotIn('payment_type', array_merge(
                FlourSale::DEBT_TYPES,
                FlourSale::GIVEAWAY_TYPES,
            ))
            ->where('amount', '>', 0)
            // A row already put right keeps its empty account column — the
            // drawer is where the money went, not something the sale
            // records — so the posting is the only thing that says so.
            // Without this the audit reports work that is already done and
            // a second run reads as a first.
            ->whereDoesntHave('bankTransactions');
    }

    /**
     * Re-saving is the repair.
     *
     * The posting is rebuilt from the row on every save — cleared first,
     * then written from the rule that is in force now. So this uses the
     * same path a sale recorded today uses, rather than a second copy of
     * the arithmetic that could disagree with it, and running it again
     * changes nothing.
     */
    private static function repairFlourSales(): int
    {
        $repaired = 0;

        self::flourSalesMissingTheirPosting()
            ->chunkById(200, function ($sales) use (&$repaired) {
                foreach ($sales as $sale) {
                    $sale->syncBankTransaction();
                    $repaired++;
                }
            });

        return $repaired;
    }

    /**
     * Confirmed handovers whose cash share reached no account.
     *
     * The card share has always been posted, against the request itself.
     * The cash share was validated, displayed and dropped — so the figure
     * survives on `paid_cash` and only the posting is missing.
     */
    private static function settlementsMissingTheirCash()
    {
        return SettlementRequest::query()
            ->whereNotNull('confirmed_at')
            ->where('paid_cash', '>', 0)
            // A request whose cash share is already in an account has been
            // put right — by an earlier run of this, or by a confirmation
            // that happened after the fix landed. The card share posts
            // against the request too, so the note is what tells them
            // apart rather than the mere existence of a row.
            ->whereDoesntHave(
                'bankTransactions',
                fn ($q) => $q->where('note', 'like', 'تسویه نقدی%'),
            );
    }

    private static function repairSettlements(): int
    {
        $till = BankAccount::cashBox();
        $repaired = 0;

        self::settlementsMissingTheirCash()
            ->with('user')
            ->chunkById(200, function ($requests) use ($till, &$repaired) {
                foreach ($requests as $request) {
                    $till->record(
                        'in',
                        (float) $request->paid_cash,
                        'sale',
                        $request->confirmed_by,
                        $request,
                        'تسویه نقدی — '.($request->user?->name ?? 'فروشنده')
                            .' (ثبت گذشته)',
                        $request->confirmed_at,
                    );

                    $repaired++;
                }
            });

        return $repaired;
    }

    /**
     * Cash the sales say was handed over, that no row says the shape of.
     *
     * An account settled straight from the panel or the phone stamped the
     * sales and wrote down nothing about how the money arrived — there was
     * no field for it on one path and it was discarded on the other. The
     * amount cannot be recovered from anywhere, so it is reported rather
     * than posted: a figure invented here would put money in the drawer
     * that nobody can point at a record for.
     */
    private static function handedOverWithNoRecordOfHow(): float
    {
        $settledCash = (float) Sale::query()
            ->whereNotNull('cash_settled_on')
            ->whereIn('payment_type', Sale::CASH_TYPES)
            ->sum('amount');

        $accountedFor = (float) BankTransaction::query()
            ->where('direction', 'in')
            ->where('source_type', Relation::getMorphAlias(SettlementRequest::class))
            ->sum('amount');

        return round(max(0, $settledCash - $accountedFor), 2);
    }
}
