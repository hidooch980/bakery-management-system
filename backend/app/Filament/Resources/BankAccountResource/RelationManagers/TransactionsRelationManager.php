<?php

namespace App\Filament\Resources\BankAccountResource\RelationManagers;

use App\Models\BankTransaction;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The account statement. Read-only: every row here is either a hand-entered
 * movement or one posted automatically by a sale, expense or salary, and
 * editing it directly would let the ledger disagree with its source record.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'گردش حساب';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('direction')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'in' ? 'واریز' : 'برداشت')
                    ->color(fn ($state) => $state === 'in' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('بابت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => BankTransaction::REASONS[$state] ?? $state)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('بابت')
                    ->options(BankTransaction::REASONS),

                Tables\Filters\SelectFilter::make('direction')
                    ->label('نوع')
                    ->options(['in' => 'واریز', 'out' => 'برداشت']),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->paginated([10, 25, 50])
            ->striped();
    }
}
