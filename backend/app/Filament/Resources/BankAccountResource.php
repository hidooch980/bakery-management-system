<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankAccountResource\Pages;
use App\Models\BankAccount;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'حساب‌های بانکی';

    protected static ?string $modelLabel = 'حساب بانکی';

    protected static ?string $pluralModelLabel = 'حساب‌های بانکی';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات حساب')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('عنوان حساب')
                        ->required()
                        ->maxLength(255)
                        ->helperText('مثلاً: حساب جاری نانوایی، صندوق'),

                    Forms\Components\TextInput::make('bank_name')
                        ->label('نام بانک')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('account_number')
                        ->label('شماره حساب')
                        ->maxLength(50),

                    Forms\Components\TextInput::make('card_number')
                        ->label('شماره کارت')
                        ->maxLength(30),

                    Forms\Components\TextInput::make('iban')
                        ->label('شبا')
                        ->maxLength(34)
                        ->prefix('IR'),

                    \App\Filament\Forms\MoneyInput::make('opening_balance', 'موجودی اولیه')
                        ->helperText('موجودی حساب در زمان تعریف؛ بعد از آن از تراکنش‌ها محاسبه می‌شود'),

                    Forms\Components\Toggle::make('is_default')
                        ->label('حساب پیش‌فرض')
                        ->helperText('فقط یک حساب می‌تواند پیش‌فرض باشد'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیحات')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('حساب')
                    ->description(fn (BankAccount $r) => $r->bank_name)
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-m-building-library'),

                Tables\Columns\TextColumn::make('card_number')
                    ->label('شماره کارت')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('opening_balance')
                    ->label('موجودی اولیه')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('balance')
                    ->label('موجودی فعلی')
                    // Derived from the ledger, so it cannot drift from the
                    // transactions that produced it.
                    ->getStateUsing(fn (BankAccount $r) => $r->balance_formatted)
                    ->weight('bold')
                    ->color(fn (BankAccount $r) => $r->is_overdrawn ? 'danger' : 'success'),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('پیش‌فرض')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('deposit')
                    ->label('واریز / برداشت')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('success')
                    ->form([
                        Forms\Components\Radio::make('direction')
                            ->label('نوع تراکنش')
                            ->options(['in' => 'واریز', 'out' => 'برداشت'])
                            ->default('in')
                            ->required()
                            ->inline(),

                        \App\Filament\Forms\MoneyInput::make('amount', 'مبلغ')
                            ->required(),

                        \App\Filament\Forms\JalaliDateInput::today('occurred_on', 'تاریخ')
                            ->required(),

                        Forms\Components\Textarea::make('note')
                            ->label('توضیحات')
                            ->rows(2),
                    ])
                    ->action(function (BankAccount $record, array $data) {
                        $record->record(
                            $data['direction'],
                            // The form hands back a display-unit figure.
                            Money::toToman($data['amount']),
                            'manual',
                            auth()->id(),
                            null,
                            $data['note'] ?? null,
                            $data['occurred_on'] ?? now(),
                        );

                        Notification::make()
                            ->title('تراکنش ثبت شد')
                            ->body('موجودی جدید: '.$record->fresh()->balance_formatted)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label('ویرایش'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    // An account with history explains other records' money;
                    // deleting it would leave those unexplained.
                    ->hidden(fn (BankAccount $r) => $r->transactions()->exists()),
            ])
            ->defaultSort('is_default', 'desc')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [BankAccountResource\RelationManagers\TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankAccounts::route('/'),
            'create' => Pages\CreateBankAccount::route('/create'),
            'edit' => Pages\EditBankAccount::route('/{record}/edit'),
        ];
    }
}
