<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'هزینه‌ها';

    protected static ?string $modelLabel = 'هزینه';

    protected static ?string $pluralModelLabel = 'هزینه‌ها';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات هزینه')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('دسته‌بندی')
                        ->options(Expense::CATEGORIES)
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('title')
                        ->label('عنوان')
                        ->required()
                        ->maxLength(255),

                    MoneyInput::make('amount', 'مبلغ')
                        ->required(),

                    JalaliDateInput::today('spent_on', 'تاریخ هزینه')
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload()
                        ->native(false),

                    Forms\Components\Select::make('bank_account_id')
                        ->label('حساب بانکی')
                        ->options(fn () => BankAccount::active()
                            ->pluck('title', 'id'))
                        ->default(fn () => BankAccount::defaultAccount()?->id)
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
                Tables\Columns\TextColumn::make('spent_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('دسته‌بندی')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Expense::CATEGORIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'flour' => 'warning',
                        'salary' => 'danger',
                        'utilities' => 'info',
                        'rent' => 'primary',
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

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('دسته‌بندی')
                    ->options(Expense::CATEGORIES),

                Tables\Filters\Filter::make('spent_on')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '<=', $d))),
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
            ->defaultSort('spent_on', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
