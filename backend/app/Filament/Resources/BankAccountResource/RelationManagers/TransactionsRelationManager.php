<?php

namespace App\Filament\Resources\BankAccountResource\RelationManagers;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Models\BankTransaction;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The account statement.
 *
 * Rows posted by a sale, an expense or a payslip belong to that record and
 * are rebuilt from it on every save, so they cannot be edited here —
 * changing one would only hold until its source was next touched.
 *
 * A hand-entered movement has no source, and until now there was no way to
 * make one. Everything the account really does that the shop records
 * nowhere else — a cash withdrawal for the day's buying, a transfer, a bank
 * charge — was invisible, so the balance on screen drifted from the balance
 * at the bank with nothing able to say why. This shop's had drifted by
 * 777 million Rial.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'گردش حساب';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('direction')
                ->label('نوع')
                ->options(['out' => 'برداشت', 'in' => 'واریز'])
                ->default('out')
                ->required()
                ->native(false),

            MoneyInput::make('amount', 'مبلغ')->required(),

            // Only the reasons a person would enter by hand. A row claiming
            // to be a sale but attached to no sale would make the takings
            // report disagree with the sales list.
            //
            // The rule is not decoration: a Select narrows the dropdown but
            // does not check what actually arrives, so without it the
            // shorter list was a suggestion rather than a limit.
            Forms\Components\Select::make('reason')
                ->label('بابت')
                ->options([
                    'manual' => BankTransaction::REASONS['manual'],
                    'transfer' => BankTransaction::REASONS['transfer'],
                ])
                ->default('manual')
                ->required()
                ->rules(['in:manual,transfer'])
                ->native(false),

            JalaliDateInput::make('occurred_on', 'تاریخ')->required(),

            Forms\Components\Textarea::make('note')
                ->label('توضیحات')
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull()
                ->placeholder('مثلاً: برداشت نقدی بابت خرید روزانه'),
        ]);
    }

    /** A row posted by another record is that record's to change, not ours. */
    private static function isHandEntered(BankTransaction $transaction): bool
    {
        return $transaction->source_type === null;
    }

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
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('ثبت دستی')
                    ->modalHeading('ثبت دستی گردش حساب')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('ویرایش')
                    ->visible(fn (BankTransaction $record) => self::isHandEntered($record)),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn (BankTransaction $record) => self::isHandEntered($record)),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->paginated([10, 25, 50])
            ->striped();
    }
}
