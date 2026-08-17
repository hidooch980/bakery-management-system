<?php

namespace App\Filament\Widgets;

use App\Models\BakeryShare;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * How this month's profit divides between the partners, by dang.
 *
 * Hidden entirely when nobody has registered a share, so a single-owner
 * bakery does not carry an empty table on its dashboard.
 */
class ProfitSplitTable extends BaseWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Shares on file are not the same as money being drawn against
        // them. This shop has two brothers holding five dang and one, and
        // has never settled a rial to either — «برداشت شرکا اصلا وجود
        // ندارد» — so a table saying each is owed his share of the profit
        // is a debt nobody is owed, inflated further by the wages that
        // profit does not yet include.
        return config('bakery.partner_drawings') && BakeryShare::active()->exists();
    }

    public function getTableHeading(): string
    {
        [$from] = Jalali::currentMonthRange();

        return 'تقسیم سود '.Jalali::monthLabel($from).' بین شرکا';
    }

    public function table(Table $table): Table
    {
        [$from, $to] = Jalali::currentMonthRange();

        return $table
            ->query(BakeryShare::query()->active()->orderByDesc('dang'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('شریک')
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('dang')
                    ->label('دانگ')
                    ->formatStateUsing(fn (BakeryShare $record) => $record->dang_label)
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('share_percent')
                    ->label('سهم')
                    ->formatStateUsing(fn ($state) => $state.'٪')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('cut')
                    ->label('سهم از سود')
                    ->getStateUsing(fn (BakeryShare $r) => Money::format($r->profitShare($from, $to)))
                    ->weight('bold')
                    ->color(fn (BakeryShare $r) => $r->profitShare($from, $to) >= 0 ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('paid')
                    ->label('پرداخت‌شده')
                    ->getStateUsing(fn (BakeryShare $r) => Money::format($r->settledFor($from, $to))),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('مانده')
                    ->getStateUsing(fn (BakeryShare $r) => Money::format(
                        $r->profitShare($from, $to) - $r->settledFor($from, $to)
                    ))
                    ->weight('bold')
                    ->color('primary'),
            ])
            ->emptyStateHeading('شریکی ثبت نشده است')
            ->emptyStateIcon('heroicon-o-users')
            ->paginated(false);
    }
}
