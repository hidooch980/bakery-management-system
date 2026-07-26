<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Credit and school sales still to be collected, oldest first — the order
 * they need chasing in.
 */
class OutstandingDebtsTable extends BaseWidget
{
    protected static ?string $heading = 'بدهی‌های تسویه‌نشده';

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Sale::query()->outstanding()->with('customer')->orderBy('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ فروش')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->description(fn (Sale $record) => $record->created_at->diffForHumans()),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->placeholder('بدون مشتری')
                    ->weight('bold')
                    ->icon('heroicon-m-building-library'),

                Tables\Columns\TextColumn::make('payment_type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SaleResource::PAYMENT_LABELS[$state] ?? $state)
                    ->color('warning'),

                Tables\Columns\TextColumn::make('bread_count')
                    ->label('تعداد نان')
                    ->numeric()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->weight('bold')
                    ->color('danger')
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع بدهی')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('تسویه')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Sale $record) => $record->update(['settled_on' => now()])),
            ])
            ->emptyStateHeading('بدهی تسویه‌نشده‌ای وجود ندارد')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }
}
