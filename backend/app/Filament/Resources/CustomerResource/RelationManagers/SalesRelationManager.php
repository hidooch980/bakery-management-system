<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What this customer has bought, newest first, with what is still owed on
 * it.
 *
 * The calls beside this say what was promised; this says what it was
 * about. Before a debt is chased it helps to see the invoices behind the
 * one number the collection list shows, which until now meant filtering
 * the whole sales table by hand.
 */
class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    protected static ?string $title = 'سابقه خرید';

    protected static ?string $modelLabel = 'فروش';

    protected static ?string $pluralModelLabel = 'فروش‌ها';

    /** Read-only: a sale is recorded against a batch, not against a name. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('bread_count')
                    ->label('تعداد نان')
                    ->numeric()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()->label('جمع')
                    ),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ),

                Tables\Columns\TextColumn::make('payment_type')
                    ->label('نوع پرداخت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SaleResource::PAYMENT_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('settled_on')
                    ->label('وضعیت')
                    ->badge()
                    ->state(fn (Sale $record) => match (true) {
                        ! $record->is_debt => 'نقدی',
                        $record->settled_on !== null => 'تسویه شد',
                        default => 'بدهکار',
                    })
                    ->color(fn (Sale $record) => match (true) {
                        ! $record->is_debt => 'gray',
                        $record->settled_on !== null => 'success',
                        default => 'danger',
                    })
                    ->description(fn (Sale $record) => $record->settled_on
                        ? AppCalendar::date($record->settled_on)
                        : null),
            ])
            ->filters([
                Tables\Filters\Filter::make('outstanding')
                    ->label('فقط تسویه‌نشده‌ها')
                    ->query(fn ($query) => $query->outstanding()),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('تسویه شد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تسویه این فاکتور')
                    ->modalDescription(fn (Sale $record) => 'مبلغ '
                        .Money::format((float) $record->amount).' دریافت شده است؟')
                    // Per invoice, unlike the collection list's "settle
                    // everything": a customer who pays part of what they
                    // owe pays off particular sales, not a fraction of all.
                    ->visible(fn (Sale $record) => $record->is_debt
                        && $record->settled_on === null)
                    ->action(function (Sale $record) {
                        $record->update(['settled_on' => now()]);

                        Notification::make()
                            ->title('فاکتور تسویه شد')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('خریدی ثبت نشده است')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->paginated([10, 25, 50])
            ->striped();
    }
}
