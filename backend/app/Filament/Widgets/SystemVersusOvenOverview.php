<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SaleResource;
use App\Support\Money;
use App\Support\SystemVersusOven;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * This month's baking against what سامانه نانینو saw of it.
 *
 * Bread sold through the card reader registers with the national system;
 * cash, credit, bread taken home and bread given away do not. The flour
 * quota follows what the system sees, so the gap between the two is the
 * part of the month's baking that earns nothing towards next month's
 * flour — which makes it worth a place on the dashboard rather than a
 * report someone has to go and ask for.
 */
class SystemVersusOvenOverview extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'سامانه در برابر تنور';

    protected function getStats(): array
    {
        $period = SystemVersusOven::forMonth();

        $baked = $period->baked();
        $seen = $period->seenBySystem();
        $gap = $period->gap();
        $share = $period->shareSeen();

        return [
            Stat::make('پخت این ماه', number_format($baked).' نان')
                ->description($period->periodLabel())
                ->descriptionIcon('heroicon-m-fire')
                ->color('gray'),

            Stat::make('سامانه دید', number_format($seen).' نان')
                ->description($share.'٪ از پخت — فروش کارتخوان')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('سامانه ندید', number_format($gap).' نان')
                ->description($this->breakdown($period))
                ->descriptionIcon('heroicon-m-eye-slash')
                // Not an error — a shop sells for cash and gives bread away.
                // Amber because it is the figure the quota is short by, not
                // because someone did something wrong.
                ->color($gap > 0 ? 'warning' : 'success'),
        ];
    }

    /** Where the unseen bread went, largest first. */
    private function breakdown(SystemVersusOven $period): string
    {
        $unseen = $period->unseen();

        if ($unseen === []) {
            return 'همهٔ پخت این ماه در سامانه ثبت شده';
        }

        arsort($unseen);

        $parts = [];

        foreach ($unseen as $type => $loaves) {
            $parts[] = (SaleResource::PAYMENT_LABELS[$type] ?? $type).' '.number_format($loaves);
        }

        $collected = $period->collectedOnCard();

        // Debts paid by card register with the system when they are
        // collected, not when the bread went out — so they close part of an
        // earlier month's gap rather than this one's, and are said apart.
        if ($collected > 0) {
            $parts[] = 'وصول نسیه با کارت '.Money::format($collected);
        }

        return implode(' · ', $parts);
    }
}
