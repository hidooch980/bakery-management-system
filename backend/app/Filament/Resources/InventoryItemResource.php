<?php

namespace App\Filament\Resources;

use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use App\Support\Qty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'موجودی انبار';

    protected static ?string $modelLabel = 'کالای انبار';

    protected static ?string $pluralModelLabel = 'موجودی انبار';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('کالای انبار')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('unit')
                        ->label('واحد')
                        ->default('کیلوگرم')
                        ->required()
                        ->maxLength(20),

                    // Flour reads its sack size from the production formula,
                    // and salt and dough are weighed rather than bagged, so
                    // neither takes a bag weight here. The field is left for
                    // any future item that really does come in fixed sacks.
                    Forms\Components\TextInput::make('bag_weight_kg')
                        ->label('وزن هر کیسه/بسته')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('کیلوگرم')
                        ->visible(fn (?InventoryItem $record) => $record !== null
                            && $record->key !== InventoryItem::FLOUR)
                        ->helperText('برای نمایش موجودی به تعداد کیسه'),

                    Forms\Components\TextInput::make('low_threshold')
                        ->label('حد هشدار موجودی')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('اگر موجودی به این مقدار برسد، هشدار داده می‌شود.'),
                ]),
        ]);
    }

    /** Flour reads its sack size from the production formula; others carry their own. */
    private static function bagWeightFor(InventoryItem $item): float
    {
        return $item->bagWeightKg();
    }

    /**
     * The reasons a person may put on a movement they are entering by hand.
     *
     * Every reason in the ledger minus «شمارش انبار», which the stocktake
     * action writes and nothing else may: that form takes a difference and
     * a count is a total.
     *
     * @return array<string, string>
     */
    private static function handEnteredReasons(): array
    {
        return collect(InventoryMovement::REASONS)->except('stocktake')->all();
    }

    /**
     * A quantity in the unit this item is actually counted in.
     *
     * Flour is spoken of in sacks — ordered, lent and counted in them —
     * so a stocktake that reports kilograms is reporting in a unit nobody
     * at the door uses. Salt and yeast arrive in no fixed sack and the
     * weight is all there is.
     */
    private static function amountLabel(InventoryItem $item, float $kg): string
    {
        $bagWeight = self::bagWeightFor($item);

        if ($bagWeight > 0) {
            return Qty::format(round($kg / $bagWeight, 2), 2).' کیسه';
        }

        return Qty::format($kg, 3).' '.$item->unit;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('کالا')
                    ->weight('bold')
                    ->icon('heroicon-m-cube'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('موجودی فعلی')
                    // Sacks alone, where the item has a sack. «کیلو در انبار
                    // معنی نداره، فقط کیسه بیاد» — this shop counts flour in
                    // sacks, orders it in sacks and lends it in sacks, and
                    // the weight beside the count restated the same fact in
                    // a unit nobody uses at the door.
                    //
                    // Salt and yeast arrive in no fixed sack, so there is no
                    // bag count for them and the weight is all there is.
                    ->state(function (InventoryItem $record) {
                        if ($record->balance_bags === null) {
                            return number_format($record->balance, 3).' '.$record->unit;
                        }

                        return number_format($record->balance_bags, 2).' کیسه';
                    })
                    ->badge()
                    ->color(fn (InventoryItem $record) => $record->is_low ? 'danger' : 'success')
                    ->size('lg'),

                Tables\Columns\TextColumn::make('low_threshold')
                    ->label('حد هشدار')
                    ->formatStateUsing(fn ($state, InventoryItem $record) => $state
                        ? number_format((float) $state, 3).' '.$record->unit
                        : '—'),

                Tables\Columns\IconColumn::make('is_low')
                    ->label('کمبود')
                    ->state(fn (InventoryItem $record) => $record->is_low)
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),

                Tables\Columns\TextColumn::make('movements_count')
                    ->label('تعداد تراکنش')
                    ->counts('movements')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('recordStock')
                    // A good whose sack size the shop has recorded is
                    // counted in sacks; one it has not is weighed. Which
                    // is which is the shop's answer, on the item — not a
                    // list here naming salt.
                    ->label(fn (InventoryItem $record) => self::bagWeightFor($record) > 0
                        ? 'ثبت موجودی (کیسه‌ای)'
                        : 'ثبت موجودی (کیلویی)')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('primary')
                    ->form(function (InventoryItem $record) {
                        $bagWeight = self::bagWeightFor($record);

                        return [
                            Forms\Components\Radio::make('direction')
                                ->label('نوع')
                                ->options(['in' => 'ورود (خرید/تولید)', 'out' => 'خروج (مصرف/ضایعات)'])
                                ->default('in')
                                ->required()
                                ->inline(),

                            Forms\Components\TextInput::make('bags')
                                ->label('تعداد کیسه/واحد')
                                ->numeric()
                                ->minValue(0.001)
                                ->required()
                                ->visible($bagWeight > 0)
                                ->live(onBlur: true)
                                ->suffix('هر واحد '.rtrim(rtrim(number_format(max($bagWeight, 0), 3), '0'), '.').' کیلوگرم'),

                            Forms\Components\Placeholder::make('computed_kg')
                                ->label('معادل کیلوگرم')
                                ->visible($bagWeight > 0)
                                ->content(function (Forms\Get $get) use ($bagWeight) {
                                    $bags = (float) ($get('bags') ?: 0);

                                    return number_format($bags * $bagWeight, 3).' کیلوگرم';
                                }),

                            Forms\Components\TextInput::make('quantity')
                                ->label('مقدار')
                                ->numeric()
                                ->minValue(0.001)
                                ->required()
                                ->visible($bagWeight <= 0)
                                ->suffix('کیلوگرم'),

                            // «شمارش انبار» is not offered here. This form
                            // takes a difference; a count is a total, and
                            // the one place the two could be confused is
                            // a dropdown that accepts either. The action
                            // beside this one asks the right question and
                            // does the subtraction itself.
                            //
                            // Refused as well as hidden. Dropping an
                            // option only stops the person who uses the
                            // dropdown; the rule is what stops the value.
                            Forms\Components\Select::make('reason')
                                ->label('علت')
                                ->options(self::handEnteredReasons())
                                ->default('manual')
                                ->required()
                                ->native(false)
                                ->rules([Rule::in(array_keys(self::handEnteredReasons()))])
                                ->validationMessages([
                                    'in' => 'برای شمارش فیزیکی، از دکمهٔ «ثبت شمارش انبار» استفاده کنید.',
                                ])
                                ->helperText('برای شمارش فیزیکی، از دکمهٔ «ثبت شمارش انبار» استفاده کنید.'),

                            Forms\Components\Textarea::make('note')
                                ->label('توضیحات')
                                ->rows(2),
                        ];
                    })
                    ->action(function (InventoryItem $record, array $data) {
                        $bagWeight = self::bagWeightFor($record);
                        $kg = $bagWeight > 0
                            ? round((float) $data['bags'] * $bagWeight, 3)
                            : round((float) $data['quantity'], 3);

                        try {
                            $record->move(
                                $data['direction'],
                                $kg,
                                $data['reason'],
                                auth()->id(),
                                null,
                                $data['note'] ?? null,
                            );
                        } catch (InsufficientStockException $e) {
                            Notification::make()
                                ->title('ثبت انجام نشد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('موجودی ثبت شد')
                            ->body('موجودی جدید '.$record->name.': '
                                .number_format($record->fresh()->balance, 2).' '.$record->unit
                                .($record->fresh()->balance_bags !== null
                                    ? '  ('.number_format($record->fresh()->balance_bags, 2).' کیسه)'
                                    : ''))
                            ->success()
                            ->send();
                    }),

                // -------------------------------------------- شمارش انبار
                //
                // Asks what was counted. The movement form beside it asks
                // for a *difference*, in a direction the counter has to
                // work out — so recording «I counted 71 sacks» meant
                // reading the ledger off another line, subtracting by
                // hand, and deciding whether that was an in or an out.
                // Two ways to be silently wrong: the sign backwards, or
                // the counted total typed where the difference belonged,
                // which on 2026-09-03 would have added 71 sacks to a
                // store holding 65.
                //
                // Nothing here a person has to compute. The arithmetic is
                // the machine's, and the note it writes says what was
                // counted, what the books held and what the gap was —
                // because the one stocktake on file with no note at all
                // (4.68 kg of yeast, 1405/06/03) can no longer be argued
                // with by anybody.
                Tables\Actions\Action::make('stocktake')
                    ->label('ثبت شمارش انبار')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->modalHeading(fn (InventoryItem $record) => 'شمارش فیزیکی '.$record->name)
                    ->modalSubmitActionLabel('ثبت شمارش')
                    ->form(function (InventoryItem $record) {
                        $bagWeight = self::bagWeightFor($record);
                        $inBags = $bagWeight > 0;

                        return [
                            Forms\Components\Placeholder::make('ledger')
                                ->label('دفتر چه می‌گوید')
                                ->content(fn () => self::amountLabel($record, $record->balance)),

                            Forms\Components\TextInput::make('counted')
                                ->label('چند شمردید؟')
                                ->numeric()
                                // Zero is a real count — a shelf can be
                                // empty, and refusing to record that would
                                // leave the books claiming stock nobody
                                // can find.
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true)
                                ->suffix($inBags ? 'کیسه' : $record->unit)
                                ->helperText($inBags
                                    ? 'همان عددی که در انبار شمردید — نه اختلافش با دفتر.'
                                    : 'همان مقداری که وزن کردید — نه اختلافش با دفتر.'),

                            // The whole of the change, said before it is
                            // made rather than in a notification after.
                            Forms\Components\Placeholder::make('difference')
                                ->label('اختلاف')
                                ->content(function (Forms\Get $get) use ($record, $bagWeight) {
                                    $counted = $get('counted');

                                    if ($counted === null || $counted === '') {
                                        return '—';
                                    }

                                    $countedKg = $bagWeight > 0
                                        ? (float) $counted * $bagWeight
                                        : (float) $counted;

                                    $diff = round($countedKg - (float) $record->balance, 3);

                                    if (abs($diff) < 0.001) {
                                        return 'دفتر با شمارش می‌خواند — چیزی ثبت نمی‌شود.';
                                    }

                                    return self::amountLabel($record, abs($diff))
                                        .($diff > 0 ? '  به نفع انبار (ورود)' : '  کسری (خروج)');
                                }),

                            Forms\Components\Textarea::make('note')
                                ->label('توضیحات')
                                ->rows(2)
                                ->helperText('اختیاری. عددها خودشان ثبت می‌شوند؛ اینجا اگر علتی برای اختلاف می‌دانید بنویسید.'),
                        ];
                    })
                    ->action(function (InventoryItem $record, array $data) {
                        $bagWeight = self::bagWeightFor($record);

                        $countedKg = $bagWeight > 0
                            ? round((float) $data['counted'] * $bagWeight, 3)
                            : round((float) $data['counted'], 3);

                        $before = (float) $record->balance;
                        $diff = round($countedKg - $before, 3);

                        // A count that agrees with the books is a real
                        // result and worth saying out loud, but it is not
                        // a movement. Writing a zero-quantity row would
                        // put a line in the ledger that moved nothing.
                        if (abs($diff) < 0.001) {
                            Notification::make()
                                ->title('دفتر با شمارش می‌خواند')
                                ->body('موجودی '.$record->name.' همان '
                                    .self::amountLabel($record, $before).' است. چیزی ثبت نشد.')
                                ->success()
                                ->send();

                            return;
                        }

                        $note = 'شمارش فیزیکی '.AppCalendar::date(now())
                            .': '.self::amountLabel($record, $countedKg)
                            .'. دفتر '.self::amountLabel($record, $before)
                            .' می‌گفت؛ اختلاف '.self::amountLabel($record, abs($diff))
                            .($diff > 0 ? ' به نفع انبار.' : ' کسری.');

                        if (filled($data['note'] ?? null)) {
                            $note .= ' — '.$data['note'];
                        }

                        $record->move(
                            $diff > 0 ? 'in' : 'out',
                            abs($diff),
                            'stocktake',
                            auth()->id(),
                            null,
                            $note,
                        );

                        Notification::make()
                            ->title('شمارش ثبت شد')
                            ->body('موجودی '.$record->name.' از '
                                .self::amountLabel($record, $before).' به '
                                .self::amountLabel($record, $record->fresh()->balance).' اصلاح شد.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->paginated(false)
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryItems::route('/'),
            'edit' => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // The three stocked goods are fixed; only their settings are editable.
        return false;
    }
}
