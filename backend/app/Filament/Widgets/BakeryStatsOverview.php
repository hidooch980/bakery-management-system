<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourStockMovement;
use App\Models\Sale;
use App\Support\DoughFormula;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BakeryStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $today = now()->toDateString();

        $doughBags = (int) DoughEntry::whereDate('created_at', $today)->sum('bag_count');
        $chaneCount = (int) ChaneEntry::whereDate('created_at', $today)->sum('chane_count');
        $salesAmount = (float) Sale::whereDate('created_at', $today)->sum('amount');
        $salesCount = Sale::whereDate('created_at', $today)->count();
        $attendance = Attendance::where('date', $today)->count();

        $flourIn = (float) FlourStockMovement::where('type', 'in')->sum('amount_kg');
        $flourOut = (float) FlourStockMovement::where('type', 'out')->sum('amount_kg');
        $balance = round($flourIn - $flourOut, 2);

        $formula = DoughFormula::fromBakery();
        // Bags is a derived display, in step with how the warehouse tab
        // already reports it (kg ÷ bag weight).
        $balanceBags = $formula->bagWeightKg > 0
            ? round($balance / $formula->bagWeightKg, 1)
            : 0.0;

        // What-if: today's normal chane, expressed as nanino loaves.
        $naninoEquivalent = $formula->naninoEquivalentForNormalCount($chaneCount);

        return [
            Stat::make('خمیر امروز', number_format($doughBags).' کیسه')
                ->description('مجموع کیسه‌های خمیرگیری‌شده')
                ->descriptionIcon('heroicon-m-cube')
                ->color('warning')
                ->chart($this->trend(DoughEntry::class, 'bag_count')),

            Stat::make('چانه امروز', number_format($chaneCount).' عدد')
                ->description('مجموع چانه‌های تولیدشده')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('info')
                ->chart($this->trend(ChaneEntry::class, 'chane_count')),

            Stat::make('فروش امروز', Money::format($salesAmount))
                ->description("{$salesCount} فقره فروش")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart($this->trend(Sale::class, 'amount')),

            Stat::make('حضور امروز', $attendance.' نفر')
                ->description('کارکنان تیک حضور زده')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make('موجودی آرد', number_format($balance, 2).' kg')
                ->description($balance > 0
                    ? number_format($balanceBags, 1).' کیسه'
                    : 'نیاز به تأمین')
                ->descriptionIcon($balance > 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($balance > 0 ? 'success' : 'danger'),

            Stat::make('صف کاری', DoughEntry::pending()->count().' / '.ChaneEntry::pending()->count())
                ->description('خمیر در انتظار / چانه در انتظار فروش')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color('gray'),

            // A what-if comparison, not a count of anything actually baked:
            // how many nanino loaves today's normal-shaped dough would have
            // produced, had it been shaped as nanino instead.
            Stat::make('معادل نانینوی چانه عادی', $naninoEquivalent === null
                ? '—'
                : number_format($naninoEquivalent).' عدد')
                ->description($naninoEquivalent === null
                    ? 'وزن هر دو نوع چانه در تنظیمات ثبت نشده است'
                    : $chaneCount.' چانه عادی امروز، اگر نانینو شکل می‌گرفت')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('warning'),
        ];
    }

    /**
     * Last 7 days of a summed column, for the sparkline on each stat.
     */
    private function trend(string $model, string $column): array
    {
        return collect(range(6, 0))
            ->map(fn ($daysAgo) => (float) $model::whereDate('created_at', now()->subDays($daysAgo)->toDateString())->sum($column))
            ->toArray();
    }
}
