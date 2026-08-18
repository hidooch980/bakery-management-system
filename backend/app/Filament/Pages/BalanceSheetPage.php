<?php

namespace App\Filament\Pages;

use App\Models\FixedAsset;
use App\Support\BalanceSheet;
use App\Support\Money;
use Filament\Pages\Page;

/**
 * What the shop owns against what it owes.
 *
 * `App\Support\BalanceSheet` has been written, tested and answering on the
 * API since long before this page. Nothing in the panel ever showed it, so
 * the owner could not see it: the 1,543,344,000 Rial of loan that was
 * missing from his books on 2026-08-17 was found by reading this class
 * from a command line, not from any screen he could open.
 *
 * A report nobody can reach is a report the shop does not have.
 *
 * The figure to be careful with here is equity. This shop's loan bought a
 * bakery machine and the owner decided on 2026-08-16 not to record that
 * machine as a fixed asset («نیاز نیست»). That is his call and stands —
 * but it means the debt is on this page and the thing it bought is not, so
 * equity reads far worse than the shop is. The page says so itself rather
 * than letting the number speak for a truth it does not tell.
 */
class BalanceSheetPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'ترازنامه';

    protected static ?string $title = 'ترازنامه';

    protected static ?int $navigationSort = -3;

    protected static string $view = 'filament.pages.balance-sheet';

    public function sheet(): array
    {
        return BalanceSheet::build();
    }

    /**
     * Whether the loan's counterpart is missing from the assets side.
     *
     * Not a criticism of the decision — it was made deliberately and the
     * machine is real whether or not it is written down. It is here so a
     * reader who sees a large negative equity is told why before he draws
     * a conclusion from it.
     */
    public function equityIsMissingAnAsset(array $sheet): bool
    {
        return ($sheet['equity'] ?? 0) < 0
            && ($sheet['liability_total'] ?? 0) > 0
            && FixedAsset::query()->count() === 0;
    }

    public function money(float $amount): string
    {
        return Money::format($amount);
    }
}
