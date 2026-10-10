<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PartnerResource;
use App\Support\PartnerStatement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * خلاصهٔ آرد امانی همکاران روی داشبورد، به کیسه: طلب ما، بدهی ما و خالص.
 *
 * هر همکار جدا خالص می‌شود و بعد جمع زده می‌شود، همان‌طور که در پروندهٔ
 * هر همکار دیده می‌شود. هر سه کارت به فهرست همکاران می‌روند.
 */
class PartnerFlourSummary extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'آرد امانی همکاران (کیسه)';

    protected function getStats(): array
    {
        $totals = PartnerStatement::totals();
        $url = PartnerResource::getUrl('index');
        $net = PartnerStatement::headline($totals['net']);

        return [
            Stat::make('طلب ما از همکاران', PartnerStatement::bags($totals['owed_to_us']).' کیسه')
                ->description($totals['partners_owing'] > 0
                    ? $totals['partners_owing'].' همکار به ما بدهکارند'
                    : 'هیچ همکاری به ما بدهکار نیست')
                ->descriptionIcon('heroicon-m-arrow-up-right')
                ->color($totals['owed_to_us'] > 0 ? 'success' : 'gray')
                ->url($url),

            Stat::make('بدهی ما به همکاران', PartnerStatement::bags($totals['we_owe']).' کیسه')
                ->description($totals['partners_owed'] > 0
                    ? 'به '.$totals['partners_owed'].' همکار بدهکاریم'
                    : 'به هیچ همکاری بدهکار نیستیم')
                ->descriptionIcon('heroicon-m-arrow-down-left')
                ->color($totals['we_owe'] > 0 ? 'danger' : 'gray')
                ->url($url),

            Stat::make('خالص', $net['label'])
                ->description('طلب منهای بدهی — دیدن همه همکاران')
                ->descriptionIcon('heroicon-m-users')
                ->color(match ($net['tone']) {
                    'owed' => 'success',
                    'owes' => 'danger',
                    default => 'gray',
                })
                ->url($url),
        ];
    }
}
