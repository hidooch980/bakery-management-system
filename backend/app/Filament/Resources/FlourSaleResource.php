<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\FlourSaleResource\Pages;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\FlourSale;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlourSaleResource extends Resource
{
    protected static ?string $model = FlourSale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'فروش آرد';

    protected static ?string $modelLabel = 'فروش آرد';

    protected static ?string $pluralModelLabel = 'فروش آرد';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات فروش')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('unit')
                        ->label('واحد فروش')
                        ->options(FlourSale::UNITS)
                        ->default(FlourSale::KG)
                        ->required()
                        ->live()
                        ->native(false)
                        // Switching the unit changes what the rate means, so
                        // the price is re-suggested rather than left stale.
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set(
                            'unit_price',
                            Money::convert(FlourSale::defaultUnitPrice($state ?? FlourSale::KG))
                        )),

                    Forms\Components\TextInput::make('quantity')
                        ->label('مقدار')
                        ->numeric()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->required()
                        ->live(onBlur: true)
                        ->suffix(fn (Forms\Get $get) => FlourSale::UNITS[$get('unit')] ?? '')
                        ->helperText(fn (Forms\Get $get) => $get('unit') === FlourSale::BAG
                            ? 'هر کیسه '.DoughFormula::fromBakery()->bagWeightKg.' کیلوگرم'
                            : null),

                    // Zero is the truth for a free sack and a slip for a sold
                    // one, and only the payment type tells them apart. See
                    // FlourSale::GIVEAWAY_TYPES.
                    MoneyInput::make('unit_price', 'قیمت واحد')
                        ->default(fn () => Money::convert(FlourSale::defaultUnitPrice(FlourSale::KG)))
                        ->helperText(fn (Forms\Get $get) => in_array(
                            $get('payment_type'),
                            FlourSale::GIVEAWAY_TYPES,
                            true,
                        )
                            ? 'برای «منزل» و «خیرات» می‌تواند صفر بماند'
                            : 'قیمت هر کیلو یا هر کیسه، بسته به واحد انتخابی')
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                $isGiveaway = in_array(
                                    $get('payment_type'),
                                    FlourSale::GIVEAWAY_TYPES,
                                    true,
                                );

                                if (! $isGiveaway && Money::toToman((float) $value) <= 0) {
                                    $fail('قیمت واحد وارد نشده است. اگر این آرد مجانی رفته،'
                                        .' نوع پرداخت را «منزل» یا «خیرات و کمک» بگذارید.');
                                }
                            },
                        ]),

                    Forms\Components\Placeholder::make('computed')
                        ->label('وزن و مبلغ محاسبه‌شده')
                        // Both figures are derived on save; this only mirrors
                        // that calculation so the operator sees it in advance.
                        ->content(function (Forms\Get $get) {
                            $quantity = (float) $get('quantity');
                            $price = Money::toToman((float) $get('unit_price'));

                            $weight = $get('unit') === FlourSale::BAG
                                ? $quantity * DoughFormula::fromBakery()->bagWeightKg
                                : $quantity;

                            return number_format($weight, 2).' کیلوگرم  —  '
                                .Money::format($quantity * $price);
                        }),
                ]),

            Forms\Components\Section::make('پرداخت')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('payment_type')
                        ->label('نوع پرداخت')
                        ->options(SaleResource::PAYMENT_LABELS)
                        // Live so the price field can say, as the type is
                        // chosen, whether zero is allowed here.
                        ->live()
                        ->default('cash')
                        ->required()
                        ->live()
                        ->native(false),

                    Forms\Components\Select::make('customer_id')
                        ->label('مشتری')
                        // Unlike bread, flour is often sold to partner
                        // bakeries, so no customer type is filtered out.
                        ->options(fn () => Customer::pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(fn (Forms\Get $get) => in_array(
                            $get('payment_type'),
                            FlourSale::DEBT_TYPES,
                            true
                        ))
                        ->helperText('برای فروش نسیه و ادارات الزامی است'),

                    Forms\Components\Select::make('user_id')
                        ->label('فروشنده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false),

                    JalaliDateInput::today('sold_on', 'تاریخ فروش')
                        ->required(),

                    JalaliDateInput::make('settled_on', 'تاریخ تسویه')
                        ->helperText('اگر بدهی پرداخت شده، تاریخ آن را وارد کنید'),

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
                Tables\Columns\TextColumn::make('sold_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label('واحد')
                    ->badge()
                    ->formatStateUsing(fn ($state) => FlourSale::UNITS[$state] ?? $state)
                    ->color(fn ($state) => $state === FlourSale::BAG ? 'warning' : 'info'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('مقدار')
                    ->formatStateUsing(fn ($state, FlourSale $record) => $record->quantity_label)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('weight_kg')
                    ->label('وزن (کیلوگرم)')
                    ->numeric(2)
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع وزن')
                            ->numeric(2)
                    )
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_price')
                    ->label('قیمت واحد')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('payment_type')
                    ->label('پرداخت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SaleResource::PAYMENT_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('settled_on')
                    ->label('تسویه')
                    ->boolean()
                    ->getStateUsing(fn (FlourSale $record) => ! $record->is_debt || $record->settled_on !== null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('فروشنده')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('unit')
                    ->label('واحد')
                    ->options(FlourSale::UNITS),

                Tables\Filters\SelectFilter::make('payment_type')
                    ->label('نوع پرداخت')
                    ->options(SaleResource::PAYMENT_LABELS),

                Tables\Filters\Filter::make('outstanding')
                    ->label('فقط بدهی تسویه‌نشده')
                    ->query(fn ($query) => $query->outstanding()),
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
            ->defaultSort('sold_on', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlourSales::route('/'),
            'create' => Pages\CreateFlourSale::route('/create'),
            'edit' => Pages\EditFlourSale::route('/{record}/edit'),
        ];
    }
}
