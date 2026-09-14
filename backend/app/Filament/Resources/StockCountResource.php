<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockCountResource\Pages;
use App\Models\InventoryItem;
use App\Models\StockCount;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * انبار، شمرده در برابر دفتر.
 *
 * ۱۴۰۵/۰۶/۲۳ موجودی آرد دو بار با دست اصلاح شد. دفتر آرد نشان داد بار
 * اول نبوده: پنج اصلاح پیش از آن هم بود، جمعاً ۲۳۶ کیسه، هر پنج تا رو به
 * بالا — در برابر ۵۵۷ کیسه خرید ثبت‌شدهٔ کل تاریخ مغازه.
 *
 * شمارش هیچ‌وقت ویرایش نمی‌شود. آن چیزی است که کسی در یک لحظه روی قفسه
 * دید، و عددی که بعداً بشود به توافق اصلاحش کرد، سند چیزی نیست.
 */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'شمارش انبار';

    protected static ?string $modelLabel = 'شمارش انبار';

    protected static ?string $pluralModelLabel = 'شمارش‌های انبار';

    protected static ?int $navigationSort = 3;

    /** واحدی که روی صفحه گفته و گرفته می‌شود: کیسه اگر کیسه‌ای باشد. */
    public static function unitLabel(InventoryItem $item): string
    {
        return $item->bagWeightKg() > 0 ? 'کیسه' : $item->unit;
    }

    /** موجودی دفتر، به همان واحدی که صفحه می‌پرسد. */
    public static function expectedIn(InventoryItem $item): float
    {
        $bag = $item->bagWeightKg();
        $balance = (float) $item->balance;

        return round($bag > 0 ? $balance / $bag : $balance, 2);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('شمارش قفسه')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('inventory_item_id')
                        ->label('کالا')
                        ->options(fn () => collect(array_keys(InventoryItem::DEFAULTS))
                            ->mapWithKeys(function (string $key) {
                                $item = InventoryItem::ofKey($key);

                                return [$item->getKey() => sprintf(
                                    '%s — دفتر می‌گوید %s %s',
                                    $item->name,
                                    rtrim(rtrim(number_format(self::expectedIn($item), 2), '0'), '.'),
                                    self::unitLabel($item),
                                )];
                            })
                            ->all())
                        ->default(fn () => InventoryItem::ofKey(InventoryItem::FLOUR)->getKey())
                        ->live()
                        ->required(),

                    Forms\Components\TextInput::make('counted')
                        ->label('شمرده شد')
                        ->helperText(fn (Forms\Get $get) => ($id = $get('inventory_item_id'))
                            && ($item = InventoryItem::find($id))
                                ? 'به '.self::unitLabel($item)
                                : null)
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->dehydrated(false),

                    Forms\Components\Toggle::make('adjust')
                        ->label('دفتر با قفسه یکی شود')
                        ->helperText('خاموش بگذارید تا فقط ثبت شود و چیزی'
                            .' جابه‌جا نشود. روشن کنید تا اختلاف به‌عنوان یک'
                            .' حرکت انبار با دلیل «شمارش انبار» نوشته شود.')
                        ->default(false)
                        ->dehydrated(false),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیح')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('اگر دلیلی برای اختلاف می‌دانید، همین‌جا'
                            .' بنویسید — یک ماه بعد کسی یادش نیست.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('counted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('counted_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.name')->label('کالا'),

                Tables\Columns\TextColumn::make('counted_quantity')
                    ->label('شمرده‌شده')
                    ->state(fn (StockCount $r) => self::inDisplayUnit($r, (float) $r->counted_quantity)),

                Tables\Columns\TextColumn::make('expected_quantity')
                    ->label('دفتر می‌گفت')
                    ->state(fn (StockCount $r) => self::inDisplayUnit($r, (float) $r->expected_quantity))
                    ->color('gray'),

                // تنها ستونی که ارزش خواندن دارد، و با کلمه گفته می‌شود نه
                // فقط با علامت.
                Tables\Columns\TextColumn::make('difference')
                    ->label('اختلاف')
                    ->state(fn (StockCount $r) => $r->is_exact
                        ? 'می‌خواند'
                        : ($r->difference > 0 ? 'اضافه ' : 'کسری ')
                            .self::inDisplayUnit($r, abs($r->difference)))
                    ->badge()
                    ->color(fn (StockCount $r) => $r->is_exact ? 'success' : 'danger'),

                Tables\Columns\IconColumn::make('adjusted')
                    ->label('دفتر اصلاح شد')
                    ->state(fn (StockCount $r) => $r->adjustment !== null)
                    ->boolean(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('شمارنده')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح')
                    ->wrap()
                    ->toggleable(),
            ])
            ->emptyStateHeading('هنوز انبار شمرده نشده')
            ->emptyStateDescription('یک بار قفسه را بشمارید. دفتر خطای خودش'
                .' را پیدا نمی‌کند: کیسه‌ای که آمده و فاکتورش ثبت نشده،'
                .' آردی که ریخته — هر کدام دو طرف دفتر را با هم جور'
                .' می‌گذارد و با قفسه نه.');
    }

    /** یک مقدار پایه، به واحدی که مالک می‌خواند. */
    private static function inDisplayUnit(StockCount $count, float $quantity): string
    {
        $item = $count->item;

        if (! $item) {
            return (string) $quantity;
        }

        $bag = $item->bagWeightKg();
        $shown = $bag > 0 ? $quantity / $bag : $quantity;

        return rtrim(rtrim(number_format($shown, 2), '0'), '.')
            .' '.self::unitLabel($item);
    }

    /** شمارش، آن چیزی است که کسی دید. بعداً اصلاح نمی‌شود. */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockCounts::route('/'),
            'create' => Pages\CreateStockCount::route('/create'),
        ];
    }
}
