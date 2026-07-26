<?php

namespace App\Filament\Widgets;

use App\Models\BankAccount;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Balances of every active account, plus the total across them.
 *
 * Hidden until at least one account exists, so a bakery that does not track
 * bank accounts is not shown an empty row of zeros.
 */
class BankBalanceOverview extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return BankAccount::active()->exists();
    }

    protected function getStats(): array
    {
        $accounts = BankAccount::active()
            ->orderByDesc('is_default')
            ->orderBy('title')
            ->get();

        $total = $accounts->sum(fn (BankAccount $a) => $a->balance);

        $stats = [
            Stat::make('موجودی کل حساب‌ها', Money::format($total))
                ->description($accounts->count().' حساب فعال')
                ->descriptionIcon('heroicon-m-building-library')
                ->color($total >= 0 ? 'success' : 'danger'),
        ];

        // Four accounts is as many as fits the row comfortably; the rest are
        // still counted in the total above.
        foreach ($accounts->take(4) as $account) {
            $stats[] = Stat::make($account->title, $account->balance_formatted)
                ->description($account->bank_name
                    ?: ($account->is_default ? 'حساب پیش‌فرض' : 'حساب'))
                ->descriptionIcon($account->is_overdrawn
                    ? 'heroicon-m-exclamation-triangle'
                    : 'heroicon-m-banknotes')
                ->color($account->is_overdrawn ? 'danger' : 'info');
        }

        return $stats;
    }
}
