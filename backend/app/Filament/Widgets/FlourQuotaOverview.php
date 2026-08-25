<?php

namespace App\Filament\Widgets;

use App\Models\FlourAllocation;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Qty;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The flour quota for the period in progress. This is the number the shop
 * plans around day to day, so it belongs on the dashboard rather than two
 * clicks away.
 */
class FlourQuotaOverview extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $allocation = FlourAllocation::forDate(now());
        $period = $allocation?->periodFor(now());

        if (! $allocation || ! $period) {
            // Days 1–4 of a Jalali month fall outside all three delivery
            // periods unless the previous month's allocation was entered
            // too (its third period wraps into them) — so the quota can
            // genuinely be registered and this still be true. Say which
            // one actually happened, rather than always blaming the admin
            // for not entering it.
            $thisMonth = FlourAllocation::forJalaliMonthOf(now());

            if ($thisMonth) {
                $firstPeriod = $thisMonth->periods->firstWhere('period_number', 1);

                return [
                    Stat::make('سهمیه آرد', $thisMonth->month_label)
                        ->description($firstPeriod
                            ? 'سهمیه این ماه ثبت شده؛ دوره اول از '
                                .AppCalendar::date($firstPeriod->starts_on).' شروع می‌شود.'
                            : 'سهمیه این ماه ثبت شده؛ هنوز دوره‌ای برای امروز نیست.')
                        ->descriptionIcon('heroicon-m-clock')
                        ->color('info'),
                ];
            }

            return [
                Stat::make('سهمیه آرد', 'تعریف نشده')
                    ->description('سهمیه این بازه در بخش انبار ثبت نشده است.')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('gray'),
            ];
        }

        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
        $remainingBags = $bagWeight > 0 ? $period->remaining_kg / $bagWeight : 0;

        return [
            Stat::make($period->label, Qty::format((float) $period->allocated_kg, 0).' کیلوگرم')
                ->description(AppCalendar::date($period->starts_on).' تا '.AppCalendar::date($period->ends_on))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            // Only dates registered as a holiday count as closed — there is
            // no standing weekly closure to assume.
            Stat::make('روزهای کاری دوره', $period->working_days.' از '.$period->total_days.' روز')
                ->description($period->holiday_days > 0
                    ? $period->holiday_days.' روز تعطیل ثبت‌شده کم شده'
                    : 'تعطیلی ثبت‌شده‌ای در این دوره نیست')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($period->holiday_days > 0 ? 'warning' : 'gray'),

            Stat::make('مصرف این دوره', Qty::format($period->used_kg, 0).' کیلوگرم')
                ->description($period->usage_percent.'٪ از سهمیه دوره')
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color($period->usage_percent > 90 ? 'danger' : ($period->usage_percent > 70 ? 'warning' : 'success')),

            Stat::make('باقی‌مانده دوره', Qty::format($period->remaining_kg, 0).' کیلوگرم')
                ->description($period->is_over
                    ? 'بیش از سهمیه مصرف شده'
                    : Qty::format($remainingBags, 1).' کیسه')
                ->descriptionIcon($period->is_over ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
                ->color($period->is_over ? 'danger' : 'success'),

            // The one comparison the flour is held to: the quota restated as
            // nanino loaves against what the card reader actually rang up.
            // The difference is worked out here, not by hand.
            Stat::make(
                'اختلاف با کارتخوان',
                ($period->bread_remainder >= 0 ? '+' : '−')
                    .Qty::format(abs($period->bread_remainder)).' نان'
            )
                ->description(Qty::format($period->allocated_bread_count).' نان سهمیه'
                    .'   •   '.Qty::format($period->card_bread_count).' نان کارتخوان')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color($period->bread_remainder < 0 ? 'danger' : 'info'),

            Stat::make('سنوات', Qty::format((float) $allocation->carryover_kg, 0).' کیلوگرم')
                ->description((float) $allocation->carryover_bags > 0
                    ? Qty::format((float) $allocation->carryover_bags, 1).' کیسه مانده از قبل'
                    : 'مانده‌ای از قبل ثبت نشده')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color((float) $allocation->carryover_kg > 0 ? 'info' : 'gray'),
        ];
    }
}
