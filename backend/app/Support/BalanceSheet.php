<?php

namespace App\Support;

use App\Models\BakeryShare;
use App\Models\BankAccount;
use App\Models\ConsignmentFlour;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdvance;

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
        return [
            [
                'key' => 'bank',
                'label' => 'موجودی حساب‌های بانکی',
                'amount' => round(BankAccount::where('is_active', true)
                    ->get()->sum(fn (BankAccount $a) => $a->balance), 2),
                'note' => null,
            ],
            [
                'key' => 'inventory',
                'label' => 'ارزش انبار',
                'amount' => self::inventoryValue(),
                'note' => 'به قیمت خرید',
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
                'key' => 'consignment',
                'label' => 'آرد امانی دریافتی',
                'amount' => self::consignmentOwed(),
                'note' => 'آرد دیگران که نزد ماست',
            ],
            [
                'key' => 'partner_shares',
                'label' => 'سهم تسویه‌نشده شرکا',
                'amount' => self::partnerShares(),
                'note' => null,
            ],
        ];
    }

    /**
     * The store at what it cost, not what it would sell for.
     *
     * Valuing flour at its selling price would book a profit the shop has
     * not made yet — the bread is not baked, let alone sold.
     */
    private static function inventoryValue(): float
    {
        $flourPrice = (float) (CurrentBakery::get()?->flour_purchase_price_per_kg ?? 0);

        $flour = InventoryItem::query()
            ->where('key', InventoryItem::FLOUR)
            ->first();

        return round(($flour?->balance ?? 0) * $flourPrice, 2);
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

    /** Flour borrowed from another shop, which has to go back or be paid for. */
    private static function consignmentOwed(): float
    {
        $flourPrice = (float) (CurrentBakery::get()?->flour_purchase_price_per_kg ?? 0);

        $kg = (float) ConsignmentFlour::query()
            ->where('direction', 'borrowed')
            ->outstanding()
            ->sum('amount_kg');

        return round($kg * $flourPrice, 2);
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
            fn (array $line) => [
                ...$line,
                'amount' => round($line['amount'], 2),
                'amount_formatted' => Money::format($line['amount']),
            ],
            array_filter($lines, fn (array $line) => abs($line['amount']) > 0.001),
        ));
    }
}
