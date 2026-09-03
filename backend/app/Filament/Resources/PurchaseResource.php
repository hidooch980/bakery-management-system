<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\BankAccount;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A delivery, written down once.
 *
 * The lines are the invoice: they fill the warehouse, they add up to the
 * total, and what is not paid at the door becomes a debt with the mill's
 * name on it. None of the three is typed twice, so none of them can
 * disagree with the others.
 */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'خرید';

    protected static ?string $navigationLabel = 'فاکتورهای خرید';

    protected static ?string $modelLabel = 'فاکتور خرید';

    protected static ?string $pluralModelLabel = 'فاکتورهای خرید';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('فاکتور')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('تأمین‌کننده')
                        ->options(fn () => Supplier::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        // A lorry at the door is not the moment to be sent
                        // to a different screen to add the mill first.
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('نام')->required()->maxLength(255),
                            Forms\Components\TextInput::make('phone')
                                ->label('تلفن')->tel()->maxLength(20),
                            Forms\Components\TextInput::make('kind')
                                ->label('چه می‌فروشد')->maxLength(100),
                        ])
                        ->createOptionUsing(fn (array $data) => Supplier::create($data)->id),

                    Forms\Components\TextInput::make('invoice_no')
                        ->label('شماره فاکتور')
                        ->maxLength(100)
                        ->helperText('شماره خودِ کارخانه، برای وقتی سر یک محموله بحث می‌شود'),

                    JalaliDateInput::today('purchased_on', 'تاریخ')
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload()
                        ->native(false),
                ]),

            Forms\Components\Section::make('ردیف‌های فاکتور')
                ->description('کالا و مقدارش. ردیفی که کالا ندارد — حمل، تخلیه — فقط مبلغ می‌گیرد و به انبار نمی‌رود.')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->columns(4)
                        ->defaultItems(1)
                        ->addActionLabel('افزودن ردیف')
                        ->schema([
                            Forms\Components\Select::make('inventory_item_id')
                                ->label('کالا')
                                ->options(fn () => self::stockedGoods())
                                ->native(false)
                                ->placeholder('بدون کالا — فقط مبلغ')
                                ->live(),

                            Forms\Components\TextInput::make('title')
                                ->label('عنوان')
                                ->maxLength(255)
                                ->placeholder('حمل، تخلیه، …')
                                // Only asked for when there is no good to
                                // name the line by.
                                ->required(fn (Forms\Get $get) => blank($get('inventory_item_id')))
                                ->visible(fn (Forms\Get $get) => blank($get('inventory_item_id'))),

                            Forms\Components\TextInput::make('bags')
                                ->label('کیسه')
                                ->numeric()
                                ->minValue(0)
                                ->step('0.01')
                                ->helperText('وزن خودش حساب می‌شود')
                                // Hidden for a good with no fixed package,
                                // and for a line that has no good at all: a
                                // sack count converted at an invented figure
                                // is worse than a plain weight.
                                ->visible(fn (Forms\Get $get) => self::bagWeightOf($get('inventory_item_id')) > 0),

                            Forms\Components\TextInput::make('quantity_kg')
                                ->label('کیلوگرم')
                                ->numeric()
                                ->minValue(0)
                                ->step('0.001')
                                ->visible(fn (Forms\Get $get) => filled($get('inventory_item_id'))),

                            MoneyInput::make('unit_price', 'نرخ هر کیلو')
                                ->visible(fn (Forms\Get $get) => filled($get('inventory_item_id'))),

                            MoneyInput::make('amount', 'مبلغ ردیف')
                                ->helperText('اگر نرخ وارد شود، خودش حساب می‌شود'),
                        ]),
                ]),

            Forms\Components\Section::make('پرداخت')
                ->columns(2)
                ->schema([
                    MoneyInput::make('paid_amount', 'پرداخت‌شده هنگام تحویل')
                        ->default(0)
                        ->helperText('باقی‌مانده به‌عنوان بدهی روی حساب تأمین‌کننده می‌ماند'),

                    Forms\Components\Select::make('bank_account_id')
                        ->label('از حساب')
                        ->options(fn () => BankAccount::active()->pluck('title', 'id'))
                        ->default(fn () => BankAccount::defaultAccount()?->id)
                        ->searchable()
                        ->native(false)
                        ->placeholder('پرداخت نقدی')
                        ->helperText('اگر انتخاب شود، مبلغ پرداختی از همان حساب کم می‌شود'),

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
                Tables\Columns\TextColumn::make('purchased_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('تأمین‌کننده')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('invoice_no')
                    ->label('شماره')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('ردیف')
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ فاکتور')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('outstanding')
                    ->label('مانده')
                    ->state(fn (Purchase $record) => $record->outstanding)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color(fn ($state) => $state > 0.01 ? 'danger' : 'success')
                    ->description(fn ($state) => $state > 0.01 ? 'پرداخت‌نشده' : 'تسویه'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('تأمین‌کننده')
                    // Every supplier, active or not: filtering an old mill
                    // out of the filter hides the invoices it is the only
                    // way to find.
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\Filter::make('purchased_on')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('purchased_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('purchased_on', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    // Deleting takes the stock back out and the bank
                    // posting with it; a paid-against invoice would leave
                    // the payments pointing at nothing.
                    ->hidden(fn (Purchase $record) => $record->payments()->exists()),
            ])
            ->defaultSort('purchased_on', 'desc')
            ->striped();
    }

    /** The stocked goods, created on first read like the API does. */
    private static function stockedGoods(): array
    {
        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            InventoryItem::ofKey($key);
        }

        return InventoryItem::orderBy('name')->pluck('name', 'id')->all();
    }

    /** One sack of the chosen good, or zero when it has no fixed package. */
    private static function bagWeightOf(mixed $itemId): float
    {
        if (blank($itemId)) {
            return 0.0;
        }

        return (float) (InventoryItem::find($itemId)?->bagWeightKg() ?? 0);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }
}
