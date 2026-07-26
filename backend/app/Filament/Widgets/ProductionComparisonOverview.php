<?php

namespace App\Filament\Widgets;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today's production measured against the formula: the chane actually
 * shaped, how many each bag of dough yielded compared with what it should
 * have, and the nanino output beside it.
 */
class ProductionComparisonOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $today = now()->toDateString();

        $chane = ChaneEntry::whereDate('created_at', $today)->get();
        $bags = (int) DoughEntry::whereDate('created_at', $today)->sum('bag_count');

        $formula = DoughFormula::fromBakery();

        $normalCount = (int) $chane->sum('chane_count');
        $naninoCount = $formula->naninoCountForWeight((float) $chane->sum('nanino_weight_kg'));

        // Per bag rather than per batch, so batches of different sizes can
        // still be compared against the same expected figure.
        $expectedPerBag = $formula->normalChaneCount(1);
        $actualPerBag = $bags > 0 ? round($normalCount / $bags, 1) : null;

        return [
            Stat::make('چانه تولیدی امروز', number_format($normalCount).' عدد')
                ->description($bags > 0
                    ? 'از '.number_format($bags).' کیسه خمیر'
                    : 'خمیری برای امروز ثبت نشده')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('info'),

            Stat::make('چانه به ازای هر کیسه', $actualPerBag === null
                ? '—'
                : number_format($actualPerBag, 1).' عدد')
                ->description($this->yieldDescription($actualPerBag, $expectedPerBag))
                ->descriptionIcon($this->yieldIcon($actualPerBag, $expectedPerBag))
                ->color($this->yieldColor($actualPerBag, $expectedPerBag)),

            Stat::make('چانه نانینو امروز', number_format($naninoCount).' عدد')
                ->description($normalCount + $naninoCount > 0
                    ? round($naninoCount / ($normalCount + $naninoCount) * 100, 1).'٪ از تولید امروز'
                    : 'تولیدی برای امروز ثبت نشده')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('warning'),
        ];
    }

    private function yieldDescription(?float $actual, ?int $expected): string
    {
        if ($expected === null) {
            return 'وزن چانه عادی در تنظیمات ثبت نشده است';
        }

        if ($actual === null) {
            return 'انتظار: '.number_format($expected).' عدد از هر کیسه';
        }

        $gap = round($actual - $expected, 1);

        return 'انتظار '.number_format($expected).' عدد'
            .($gap == 0 ? '' : '   •   '.($gap > 0 ? '+' : '').number_format($gap, 1));
    }

    private function yieldIcon(?float $actual, ?int $expected): string
    {
        if ($actual === null || $expected === null || $actual >= $expected) {
            return 'heroicon-m-check-circle';
        }

        return 'heroicon-m-exclamation-triangle';
    }

    private function yieldColor(?float $actual, ?int $expected): string
    {
        if ($actual === null || $expected === null) {
            return 'gray';
        }

        // Falling short of the formula means dough went somewhere it should
        // not have; matching or beating it is fine.
        return $actual < $expected ? 'danger' : 'success';
    }
}
