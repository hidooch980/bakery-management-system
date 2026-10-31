<?php

namespace App\Support;

use App\Models\BankSms;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The figures behind the automatic alerts and the daily profit card. Pure
 * reads: nothing here sends or writes anything.
 */
class ShopChecks
{
    /**
     * Sellers owing more than the shop's threshold.
     *
     * @return Collection<int, array{seller: User, balance: float, threshold: float}>
     */
    public static function sellersOverThreshold(): Collection
    {
        $threshold = NotificationSetting::current()->seller_debt_threshold;
        if ($threshold === null || (float) $threshold <= 0) {
            return collect();
        }

        return User::query()->ofCurrentBakery()->role('seller')->where('is_active', true)->get()
            ->map(fn (User $u) => [
                'seller' => $u,
                'balance' => SellerSettlement::balanceFor($u)['balance'],
                'threshold' => (float) $threshold,
            ])
            ->filter(fn ($r) => $r['balance'] > $r['threshold'])
            ->values();
    }

    /** The banner a seller sees in the app, or null. */
    public static function debtAlertFor(User $seller): ?array
    {
        $threshold = NotificationSetting::current()->seller_debt_threshold;
        if ($threshold === null || (float) $threshold <= 0) {
            return null;
        }
        $balance = SellerSettlement::balanceFor($seller)['balance'];
        if ($balance <= (float) $threshold) {
            return null;
        }

        return [
            'balance' => Money::convert($balance),
            'balance_formatted' => Money::format($balance),
            'threshold_formatted' => Money::format((float) $threshold),
            'message' => 'بدهی شما '.Money::format($balance).' است و از سقف '
                .Money::format((float) $threshold).' گذشته. لطفاً تسویه کنید.',
        ];
    }

    /** What a customer owes on credit that has not been collected. */
    public static function openCreditOf(Customer $customer): float
    {
        return round((float) Sale::query()
            ->where('customer_id', $customer->id)
            ->whereIn('payment_type', Sale::DEBT_TYPES)
            ->whereNull('settled_on')
            ->sum('amount'), 2);
    }

    /** Null when within the limit (or no limit), else the warning sentence. */
    public static function creditLimitWarning(?Customer $customer): ?string
    {
        if ($customer === null || $customer->credit_limit === null || (float) $customer->credit_limit <= 0) {
            return null;
        }
        $open = self::openCreditOf($customer);
        if ($open <= (float) $customer->credit_limit) {
            return null;
        }

        return 'نسیهٔ «'.$customer->name.'» به '.Money::format($open)
            .' رسیده و از سقف '.Money::format((float) $customer->credit_limit).' گذشته است.';
    }

    /**
     * Customers with credit older than $days, or over their limit.
     *
     * @return Collection<int, array{customer: Customer, open: float, oldest: ?Carbon, old: float, over_limit: bool}>
     */
    public static function creditToChase(int $days): Collection
    {
        $cutoff = now()->subDays($days)->endOfDay();

        $open = Sale::query()
            ->whereIn('payment_type', Sale::DEBT_TYPES)
            ->whereNull('settled_on')
            ->whereNotNull('customer_id')
            ->get(['id', 'customer_id', 'amount', 'created_at'])
            ->groupBy('customer_id');

        if ($open->isEmpty()) {
            return collect();
        }

        return Customer::query()->whereIn('id', $open->keys())->get()
            ->map(function (Customer $c) use ($open, $cutoff) {
                $sales = $open[$c->id];
                $total = round((float) $sales->sum('amount'), 2);

                return [
                    'customer' => $c,
                    'open' => $total,
                    'oldest' => $sales->min('created_at'),
                    'old' => round((float) $sales->where('created_at', '<=', $cutoff)->sum('amount'), 2),
                    'over_limit' => $c->credit_limit !== null && (float) $c->credit_limit > 0
                        && $total > (float) $c->credit_limit,
                ];
            })
            ->filter(fn ($r) => $r['old'] > 0 || $r['over_limit'])
            ->sortByDesc('open')
            ->values();
    }

    /**
     * Card sales recorded on $day against the card reader's deposit, which
     * the bank pays in — and texts — the following day.
     *
     * @return array{day: string, sales: float, deposits: float, difference: float, sms_count: int}
     */
    public static function cardReader(Carbon $day): array
    {
        $day = $day->copy()->startOfDay();

        $sales = (float) Sale::query()
            ->where('payment_type', 'card')
            ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
            ->sum('amount');

        $next = $day->copy()->addDay();
        $sms = BankSms::query()
            ->where('kind', 'pos_group')
            ->where('direction', 'in')
            ->where('status', '!=', 'ignored')
            ->whereBetween('received_at', [$next, $next->copy()->endOfDay()])
            ->get(['amount']);

        $deposits = (float) $sms->sum('amount');

        return [
            'day' => $day->toDateString(),
            'sales' => round($sales, 2),
            'deposits' => round($deposits, 2),
            'difference' => round($deposits - $sales, 2),
            'sms_count' => $sms->count(),
        ];
    }

    /**
     * Profit for each day: sales − flour baked − wages earned that day −
     * other spending. Wages are a thirtieth of every monthly salary, so a
     * payday does not read as a disaster and every other day as a windfall.
     *
     * @return array<int, array{date: string, income: float, flour: float, wages: float, expenses: float, profit: float}>
     */
    public static function dailyProfit(Carbon $to, int $days = 7): array
    {
        $dailyWage = round((float) User::query()->ofCurrentBakery()
            ->where('is_active', true)->sum('monthly_salary') / 30, 2);

        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $to->copy()->subDays($i);
            $from = $d->copy()->startOfDay();
            $end = $d->copy()->endOfDay();
            $income = Ledger::totalIncome($from, $end);
            $flour = Ledger::flourConsumedCost($from, $end);
            $expenses = round(Ledger::operatingExpenses($from, $end) - Ledger::paidSalaries($from, $end), 2);
            $rows[] = [
                'date' => $d->toDateString(),
                'income' => $income,
                'flour' => $flour,
                'wages' => $dailyWage,
                'expenses' => $expenses,
                'profit' => round($income - $flour - $dailyWage - $expenses, 2),
            ];
        }

        return $rows;
    }
}
