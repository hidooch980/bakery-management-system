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

        // What today's normal chane would have been, shaped as nanino.
        $equivalent = $formula->naninoEquivalentForNormalCount($normalCount);

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

            // The comparison figure: today's normal chane restated as nanino
            // loaves. Showing the nanino actually recorded would read as a
            // bare zero on any day nothing was shaped that way, which says
            // nothing about how the two systems compare.
            Stat::make('معادل نانینو', $equivalent === null
                ? '—'
                : number_format($equivalent).' عدد')
                ->description($this->naninoDescription($equivalent, $naninoCount, $normalCount))
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('warning'),
        ];
    }

    /**
     * The equivalent on its own is a what-if, so it is worth saying which
     * it is — and naming the nanino actually shaped when there was any.
     */
    private function naninoDescription(?int $equivalent, int $actualNanino, int $normalCount): string
    {
        if ($equivalent === null) {
            return 'وزن هر دو نوع چانه در تنظیمات ثبت نشده است';
        }

        if ($normalCount === 0) {
            return 'چانه‌ای برای امروز ثبت نشده';
        }

        $prefix = number_format($normalCount).' چانه عادی امروز، اگر نانینو شکل می‌گرفت';

        return $actualNanino > 0
            ? $prefix.'   •   نانینوی واقعی: '.number_format($actualNanino).' عدد'
            : $prefix;
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
