<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\StaffAdvanceResource\Pages;
use App\Models\StaffAdvance;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Money handed to staff before payday.
 *
 * The recovery side of this — taking it back off the next payslip, oldest
 * advance first, carrying the remainder into the month after rather than
 * pushing pay below zero — was written and tested long ago. What was missing
 * was any way to record that an advance had happened, so none of it ever ran.
 */
class StaffAdvanceResource extends Resource
{
    protected static ?string $model = StaffAdvance::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'علی‌الحساب کارکنان';

    protected static ?string $modelLabel = 'علی‌الحساب';

    protected static ?string $pluralModelLabel = 'علی‌الحساب کارکنان';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('پرداخت')
                ->description('این مبلغ هزینه نیست — پیش‌پرداخت حقوق است و از فیش بعدی کسر می‌شود.')
                ->icon('heroicon-o-banknotes')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('کارمند')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    MoneyInput::make('amount', 'مبلغ')
                        ->required(),

                    JalaliDateInput::make('paid_on', 'تاریخ پرداخت')
                        ->required()
                        ->default(fn () => now()->toDateString()),

                    Forms\Components\Select::make('bank_account_id')
                        ->label('از حساب')
                        ->relationship('bankAccount', 'title')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText('خالی بگذارید اگر از صندوق پرداخت شده.'),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیح')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('کارمند')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('paid_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state)),

                // Derived from the recoveries on file rather than stored, so
                // an edited or deleted payslip cannot leave this stale.
                Tables\Columns\TextColumn::make('recovered')
                    ->label('کسر شده')
                    ->state(fn (StaffAdvance $record) => Money::format($record->recovered))
                    ->color('success'),

                Tables\Columns\TextColumn::make('outstanding')
                    ->label('مانده')
                    ->state(fn (StaffAdvance $record) => Money::format($record->outstanding))
                    ->badge()
                    ->color(fn (StaffAdvance $record) => $record->is_settled ? 'gray' : 'warning'),

                Tables\Columns\TextColumn::make('bankAccount.title')
                    ->label('از حساب')
                    ->placeholder('صندوق')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('outstanding')
                    ->label('فقط تسویه‌نشده‌ها')
                    ->query(fn ($query) => $query->outstanding()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Deleting hands the money back: the payslips that recovered
                // it lose their deduction, which is why this is not hidden.
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffAdvances::route('/'),
            'create' => Pages\CreateStaffAdvance::route('/create'),
            'edit' => Pages\EditStaffAdvance::route('/{record}/edit'),
        ];
    }
}
