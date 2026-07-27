<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'فروش';

    protected static ?string $modelLabel = 'فروش';

    protected static ?string $pluralModelLabel = 'فروش‌ها';

    protected static ?int $navigationSort = 3;

    public const PAYMENT_LABELS = [
        'cash' => 'نقد',
        'card' => 'کارتخوان',
        'credit' => 'نسیه',
        'home' => 'منزل',
        'schools' => 'مدارس',
        'charity' => 'خیرات و کمک',
        'other' => 'سایر',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات فروش')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('chane_entry_id')
                        ->label('چانه مرتبط')
                        ->relationship('chaneEntry', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "چانه #{$record->id} — {$record->chane_count} عدد")
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('user_id')
                        ->label('فروشنده')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    // A batch is often settled in more than one way, so the
                    // panel takes a line per payment type — the same shape
                    // the seller's screen uses. Each line becomes its own
                    // sale row, so reports that group by type are unchanged.
                    Forms\Components\Repeater::make('payments')
                        ->label('پرداخت‌ها')
                        ->columnSpanFull()
                        ->addActionLabel('افزودن نوع پرداخت')
                        ->defaultItems(1)
                        ->visibleOn('create')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('payment_type')
                                ->label('نوع پرداخت')
                                ->options(self::PAYMENT_LABELS)
                                ->default('cash')
                                ->required()
                                ->native(false)
                                ->live(),

                            Forms\Components\TextInput::make('bread_count')
                                ->label('تعداد نان')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->suffix('عدد'),

                            \App\Filament\Forms\MoneyInput::make('amount', 'مبلغ'),

                            Forms\Components\Select::make('customer_id')
                                ->label('مدرسه / اداره')
                                ->options(fn () => \App\Models\Customer::query()
                                    ->buyers()->pluck('name', 'id'))
                                ->searchable()
                                ->native(false)
                                // Only school and credit lines owe a name.
                                ->required(fn (Forms\Get $get) => in_array(
                                    $get('payment_type'), ['schools', 'credit'], true
                                ))
                                ->visible(fn (Forms\Get $get) => in_array(
                                    $get('payment_type'), ['schools', 'credit'], true
                                )),
                        ])
                        ->dehydrated(false),

                    // Editing works on one recorded line at a time, since
                    // that is what a sale row is once it has been written.
                    Forms\Components\Select::make('payment_type')
                        ->label('نوع پرداخت')
                        ->options(self::PAYMENT_LABELS)
                        ->required()
                        ->native(false)
                        ->visibleOn('edit')
                        ->live(),

                    Forms\Components\TextInput::make('bread_count')
                        ->label('تعداد نان')
                        ->numeric()
                        ->minValue(0)
                        ->visibleOn('edit')
                        ->suffix('عدد'),

                    Forms\Components\Select::make('customer_id')
                        ->label('مدرسه / اداره')
                        // Partner bakeries are counterparties, not buyers.
                        ->relationship('customer', 'name', fn ($query) => $query->buyers())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visibleOn('edit')
                        // Named buyers only matter for school and credit sales.
                        ->required(fn (Forms\Get $get) => in_array($get('payment_type'), ['schools', 'credit'], true))
                        ->helperText('برای فروش مدارس و نسیه الزامی است.'),

                    \App\Filament\Forms\MoneyInput::make('amount', 'مبلغ')->visibleOn('edit'),

                    \App\Filament\Forms\JalaliDateInput::make('settled_on', 'تاریخ تسویه')
                        // Only credit and school sales leave money owed.
                        ->visible(fn (Forms\Get $get) => in_array(
                            $get('payment_type'),
                            \App\Models\Sale::DEBT_TYPES,
                            true
                        ))
                        ->helperText('خالی بگذارید تا در فهرست بدهی‌ها بماند.'),

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
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('فروشنده')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->sortable(),

                Tables\Columns\TextColumn::make('chane_entry_id')
                    ->label('چانه')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('bread_count')
                    ->label('تعداد نان')
                    ->numeric()
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('جمع')),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payment_type')
                    ->label('نوع پرداخت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::PAYMENT_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'cash' => 'success',
                        'card' => 'info',
                        'credit' => 'danger',
                        'home' => 'warning',
                        'schools' => 'primary',
                        'charity' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : \App\Support\Money::format($state))
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => \App\Support\Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('shortfall_count')
                    ->label('بدهی موقت (نان)')
                    ->numeric()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Sale $record) => $record->has_shortfall
                        ? ($record->shortfall_settled_on ? 'success' : 'danger')
                        : 'gray')
                    ->description(fn (Sale $record) => $record->has_shortfall
                        ? \App\Support\Money::format($record->shortfall_amount)
                        .($record->shortfall_settled_on ? ' — تسویه شد' : '')
                        : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('settled_on')
                    ->label('تسویه')
                    ->badge()
                    ->state(function (Sale $record) {
                        if (! $record->is_debt) {
                            return 'نقدی';
                        }

                        return $record->is_settled
                            ? 'تسویه شد'
                            : 'بدهکار';
                    })
                    ->color(fn ($state) => match ($state) {
                        'بدهکار' => 'danger',
                        'تسویه شد' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان فروش')
                    ->formatStateUsing(fn ($state) => \App\Support\Jalali::dateTime($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_type')
                    ->label('نوع پرداخت')
                    ->options(self::PAYMENT_LABELS),

                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('مشتری')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('فروشنده')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('outstanding')
                    ->label('فقط بدهی‌های تسویه‌نشده')
                    ->query(fn ($query) => $query->outstanding())
                    ->toggle(),

                Tables\Filters\Filter::make('today')
                    ->label('فقط امروز')
                    ->query(fn ($query) => $query->whereDate('created_at', now()->toDateString()))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('ثبت تسویه')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Sale $record) => $record->is_debt && ! $record->is_settled)
                    ->action(fn (Sale $record) => $record->update(['settled_on' => now()])),

                Tables\Actions\Action::make('settleShortfall')
                    ->label('تسویه کسری')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('کسری این فروش تسویه یا مطابق تصمیم مدیریت راه‌حل‌یابی شده است؟')
                    ->visible(fn (Sale $record) => $record->has_shortfall && ! $record->shortfall_settled_on)
                    ->action(fn (Sale $record) => $record->update(['shortfall_settled_on' => now()])),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
