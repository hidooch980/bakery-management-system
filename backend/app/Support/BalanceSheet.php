<?php

namespace App\Support;

use App\Models\BakeryShare;
use App\Models\BankAccount;
use App\Models\ConsignmentFlour;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\PurchaseItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdvance;
use App\Models\Supplier;

/**
 * What the shop owns against what it owes, as of now.
 *
 * Most of it is already recorded as the day goes: the bank knows its
 * balance, the store knows its flour, a credit sale knows it is owed. Only
 * the oven and the loan had to be written down separately, because nothing
 * in the day's work mentions them.
 *
 * Every figure is derived — nothing here is a number someone typed as a
 * total — so the sheet cannot disagree with the pages it is drawn from.
 */
class BalanceSheet
{
    /** @return array<string, mixed> */
    public static function build(): array
    {
        $assets = self::assets();
        $liabilities = self::liabilities();

        $assetTotal = array_sum(array_column($assets, 'amount'));
        $liabilityTotal = array_sum(array_column($liabilities, 'amount'));
        $equity = round($assetTotal - $liabilityTotal, 2);

        return [
            'assets' => self::present($assets),
            'liabilities' => self::present($liabilities),
            'asset_total' => round($assetTotal, 2),
            'asset_total_formatted' => Money::format($assetTotal),
            'liability_total' => round($liabilityTotal, 2),
            'liability_total_formatted' => Money::format($liabilityTotal),
            // What would be left for the owners if everything were settled
            // today. Negative means the shop owes more than it holds.
            'equity' => $equity,
            'equity_formatted' => Money::format($equity),
            'is_solvent' => $equity >= 0,
            'currency_label' => Money::label(),
            'as_of' => AppCalendar::date(now()),
        ];
    }

    /** @return array<int, array{key: string, label: string, amount: float, note: string|null}> */
    private static function assets(): array
    {
        // Worked out once and read twice below. It was a `static` inside
        // the method a moment ago, which is a cache with no way to clear
        // it: the first shop's figure was handed to every later caller in
        // the same process, and the tests showed it immediately.
        $stock = self::stockValue();

        return [
            [
                'key' => 'bank',
                'label' => 'موجودی حساب‌های بانکی',
                'amount' => round(BankAccount::where('is_active', true)
                    ->get()->sum(fn (BankAccount $a) => $a->balance), 2),
                'note' => null,
            ],
            [
                'key' => 'customer_debt',
                'label' => 'طلب از مشتریان',
                'amount' => round((float) Sale::query()->outstanding()->sum('amount'), 2),
                'note' => 'نسیه و مدارس وصول‌نشده',
            ],
            [
                'key' => 'seller_holdings',
                'label' => 'نزد فروشندگان',
                'amount' => self::sellerHoldings(),
                'note' => 'نقد و کسری تسویه‌نشده',
            ],
            [
                'key' => 'staff_advances',
                'label' => 'علی‌الحساب کارکنان',
                'amount' => self::staffAdvances(),
                'note' => 'از حقوق کسر می‌شود',
            ],
            [
                'key' => 'stock',
                'label' => 'موجودی انبار',
                'amount' => $stock['amount'],
                'note' => $stock['note'],
                // Kept even at zero when the note names goods that are in
                // the store and have no price on record. Dropping it hid
                // the one sentence saying why the largest thing the shop
                // owns is missing from its own balance sheet.
                'keep' => $stock['unpriced'],
            ],
            [
                'key' => 'consignment_due',
                'label' => 'آرد امانی نزد همکاران',
                'amount' => self::consignmentValue('lent'),
                'note' => 'تحویل داده‌ایم و برنگشته',
            ],
            [
                'key' => 'fixed_assets',
                'label' => 'دارایی ثابت',
                'amount' => round((float) FixedAsset::held()->get()
                    ->sum(fn (FixedAsset $a) => $a->value), 2),
                'note' => 'تنور، وسیله نقلیه، ملک',
            ],
        ];
    }

    /** @return array<int, array{key: string, label: string, amount: float, note: string|null}> */
    private static function liabilities(): array
    {
        return [
            [
                'key' => 'loans',
                'label' => 'مانده وام‌ها',
                'amount' => round((float) Loan::outstanding()->get()
                    ->sum(fn (Loan $l) => $l->remaining), 2),
                'note' => null,
            ],
            [
                'key' => 'unpaid_salaries',
                'label' => 'حقوق پرداخت‌نشده',
                'amount' => round((float) SalaryPayment::whereNull('paid_on')->sum('net_amount'), 2),
                'note' => null,
            ],
            [
                'key' => 'partner_shares',
                'label' => 'سهم تسویه‌نشده شرکا',
                'amount' => self::partnerShares(),
                'note' => null,
            ],
            [
                'key' => 'consignment_owed',
                'label' => 'آرد امانی از همکاران',
                'amount' => self::consignmentValue('borrowed'),
                'note' => 'در انبار هست ولی باید برگردد',
            ],
            [
                'key' => 'supplier_debt',
                'label' => 'بدهی به تأمین‌کنندگان',
                'amount' => self::supplierDebt(),
                'note' => 'فاکتورهای پرداخت‌نشده',
            ],
        ];
    }

    /**
     * Invoiced and not yet paid for.
     *
     * The shop has bought flour on credit since it opened and this sheet
     * has never said so: a lorry that arrived unpaid put its sacks on the
     * asset side and nothing at all on the other, which made the shop look
     * richer the more it owed. Only what is owed counts — a supplier the
     * shop has overpaid is in credit, and adding that to a liability total
     * would net a debt off against a different mill's money.
     */
    private static function supplierDebt(): float
    {
        return round((float) Supplier::query()->get()
            ->sum(fn (Supplier $supplier) => max(0, $supplier->balance)), 2);
    }

    /**
     * What is in the store, at what the shop last paid for it.
     *
     * A bakery's largest ordinary asset, and this sheet said nothing about
     * it until today: the bank, the debts, the advances and the oven were
     * all counted, and the hundred sacks in the store were not.
     *
     * Priced off the most recent purchase line for each good rather than
     * an average of every one: what the store is worth is what replacing
     * it costs, and an average of prices from a year ago answers a
     * question nobody asked.
     *
     * A good the system has never seen bought has no price on record.
     * Valuing it at zero would read as an empty shelf, so it is left out
     * of the figure and named in the note instead — a number that is short
     * and says where is worth more than one that is silently wrong.
     *
     * @return array{amount: float, note: string|null, unpriced: bool}
     */
    private static function stockValue(): array
    {
        $total = 0.0;
        $unpriced = [];

        foreach (InventoryItem::all() as $item) {
            $balance = round((float) $item->balance, 3);

            if ($balance <= 0) {
                continue;
            }

            $price = self::lastPaidPerKg($item);

            if ($price === null) {
                $unpriced[] = $item->name;

                continue;
            }

            $total += $balance * $price;
        }

        $note = 'به آخرین قیمت خرید';

        if ($unpriced !== []) {
            // Named, not counted. The owner can price them by recording a
            // purchase; until then the figure is short by a known amount
            // of a known thing.
            $note .= '. قیمتی برای این‌ها ثبت نشده و حساب نشده‌اند: '
                .implode('، ', $unpriced);
        }

        return [
            'amount' => round($total, 2),
            'note' => $note,
            'unpriced' => $unpriced !== [],
        ];
    }

    /** What the shop last paid for one kilo of a good, if it ever has. */
    private static function lastPaidPerKg(InventoryItem $item): ?float
    {
        $price = PurchaseItem::query()
            ->where('inventory_item_id', $item->id)
            ->where('unit_price', '>', 0)
            ->latest('id')
            ->value('unit_price');

        return $price === null ? null : (float) $price;
    }

    /**
     * Flour lent to or borrowed from another baker, still unsettled.
     *
     * Both sides were missing and they fail differently. Borrowed flour is
     * in the store, so the stock figure above counts it as owned — without
     * the matching liability the shop looks richer for holding somebody
     * else's sacks. Lent flour has left the store, so it was on this sheet
     * nowhere at all: value the shop is owed and no line said so.
     *
     * Valued at the same price as the store, because it is the same flour.
     */
    private static function consignmentValue(string $direction): float
    {
        $kg = (float) ConsignmentFlour::query()
            ->outstanding()
            ->where('direction', $direction)
            ->sum('amount_kg');

        if ($kg <= 0) {
            return 0.0;
        }

        $price = self::lastPaidPerKg(InventoryItem::ofKey(InventoryItem::FLOUR));

        return $price === null ? 0.0 : round($kg * $price, 2);
    }

    /** Cash the sellers are holding, plus bread they owe for. */
    private static function sellerHoldings(): float
    {
        $sales = Sale::query()->sellerAccountOutstanding()->get();

        $cash = $sales->sum(fn (Sale $s) => $s->cash_held);
        $shortfall = $sales->sum(fn (Sale $s) => $s->open_shortfall);

        return round($cash + $shortfall, 2);
    }

    /** Advanced against wages not yet earned — money owed back to the shop. */
    private static function staffAdvances(): float
    {
        return round((float) StaffAdvance::query()->get()
            ->sum(fn (StaffAdvance $advance) => $advance->outstanding), 2);
    }

    /**
     * A partner's cut of this Jalali month's profit that has not been paid.
     *
     * Read through the same split the profit screen shows, so the sheet and
     * that screen cannot disagree about what a partner is owed.
     */
    private static function partnerShares(): float
    {
        // Nothing has ever been drawn against the shares and the owner
        // says nothing will be — «برداشت شرکا اصلا وجود ندارد». Carrying
        // the period's profit as money owed to the two brothers put a
        // liability of a billion and a half Rial on the sheet that nobody
        // is owed, and one inflated further by the wages the profit does
        // not yet include.
        if (! config('bakery.partner_drawings')) {
            return 0.0;
        }

        [$from, $to] = Jalali::currentMonthRange();

        $split = BakeryShare::splitFor($from, $to);

        $owed = collect($split['holders'] ?? [])
            ->sum(fn ($holder) => max(0, (float) ($holder['remaining'] ?? 0)));

        return round((float) $owed, 2);
    }

    /**
     * Formats each line and drops the empty ones — a sheet listing six
     * zeroes buries the two figures that matter.
     *
     * @param  array<int, array{key: string, label: string, amount: float, note: string|null}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private static function present(array $lines): array
    {
        return array_values(array_map(
            function (array $line) {
                unset($line['keep']);

                return [
                    ...$line,
                    'amount' => round($line['amount'], 2),
                    'amount_formatted' => Money::format($line['amount']),
                ];
            },
            array_filter($lines, fn (array $line) => abs($line['amount']) > 0.001
                || ($line['keep'] ?? false)),
        ));
    }
}
