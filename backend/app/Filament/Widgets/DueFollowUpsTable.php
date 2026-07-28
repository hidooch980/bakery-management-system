<?php

namespace App\Filament\Widgets;

use App\Models\CustomerInteraction;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Today's call list: the follow-ups that have come due, oldest first.
 *
 * A promise made on the phone is worth nothing if nobody is reminded of
 * it, so the ones that have come round sit on the dashboard until they
 * are dealt with.
 */
class DueFollowUpsTable extends BaseWidget
{
    protected static ?string $heading = 'پیگیری‌های امروز';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CustomerInteraction::query()
                    ->due()
                    ->with('customer')
                    ->orderBy('follow_up_on')
            )
            ->columns([
                Tables\Columns\TextColumn::make('follow_up_on')
                    ->label('موعد')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->badge()
                    ->color(fn (CustomerInteraction $record) => $record->is_overdue ? 'danger' : 'warning')
                    ->description(fn (CustomerInteraction $record) => $record->is_overdue
                        ? 'عقب‌افتاده'
                        : 'امروز'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->weight('bold')
                    ->icon('heroicon-m-building-library')
                    ->description(fn (CustomerInteraction $record) => $record->customer?->phone),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => CustomerInteraction::TYPES[$state] ?? $state),

                Tables\Columns\TextColumn::make('summary')
                    ->label('موضوع')
                    ->wrap()
                    ->limit(100),

                Tables\Columns\TextColumn::make('customer_id')
                    ->label('بدهی')
                    // What they owe belongs on the call list: it is usually
                    // the reason for the call.
                    ->state(fn (CustomerInteraction $record) => Money::format(
                        $record->customer?->outstanding ?? 0
                    ))
                    ->color('danger'),
            ])
            ->actions([
                Tables\Actions\Action::make('complete')
                    ->label('انجام شد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->action(function (CustomerInteraction $record) {
                        $record->update(['completed_at' => now()]);

                        Notification::make()
                            ->title('پیگیری انجام شد')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('پیگیری سررسیدشده‌ای نیست')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->striped();
    }
}
