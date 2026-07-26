<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncomeResource\Pages;
use App\Models\Customer;
use App\Models\Income;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IncomeResource extends Resource
{
    protected static ?string $model = Income::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'درآمدهای متفرقه';

    protected static ?string $modelLabel = 'درآمد متفرقه';

    protected static ?string $pluralModelLabel = 'درآمدهای متفرقه';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات درآمد')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('دسته‌بندی')
                        ->options(Income::CATEGORIES)
                        ->default('other')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('title')
                        ->label('عنوان')
                        ->required()
                        ->maxLength(255),

                    \App\Filament\Forms\MoneyInput::make('amount', 'مبلغ')
                        ->required(),

                    \App\Filament\Forms\JalaliDateInput::today('received_on', 'تاریخ دریافت')
                        ->required(),

                    Forms\Components\Select::make('customer_id')
                        ->label('پرداخت‌کننده')
                        ->options(fn () => Customer::pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->placeholder('—'),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload()
                        ->native(false),

                    Forms\Components\Select::make('bank_account_id')
                        ->label('حساب بانکی')
                        ->options(fn () => \App\Models\BankAccount::active()
                            ->pluck('title', 'id'))
                        ->default(fn () => \App\Models\BankAccount::defaultAccount()?->id)
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->placeholder('بدون حساب')
                        ->helperText('اگر انتخاب شود، مبلغ به گردش همان حساب اضافه می‌شود'),

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
                Tables\Columns\TextColumn::make('received_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('دسته‌بندی')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Income::CATEGORIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'subsidy' => 'success',
                        'rent' => 'primary',
                        'scrap' => 'warning',
                        'partner' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('پرداخت‌کننده')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('دسته‌بندی')
                    ->options(Income::CATEGORIES),

                Tables\Filters\Filter::make('received_on')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('received_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('received_on', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->defaultSort('received_on', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncomes::route('/'),
            'create' => Pages\CreateIncome::route('/create'),
            'edit' => Pages\EditIncome::route('/{record}/edit'),
        ];
    }
}
