<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use App\Support\DoughFormula;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'انبار';

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
                        ->default('kg')
                        ->required()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('bag_weight_kg')
                        ->label('وزن هر کیسه/بسته')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('کیلوگرم')
                        ->visible(fn (?InventoryItem $record) => $record?->key !== InventoryItem::FLOUR)
                        ->helperText(fn (?InventoryItem $record) => $record?->key === InventoryItem::FLOUR
                            ? null
                            : 'برای نمایش موجودی به تعداد کیسه؛ مثلاً نمک ۲۵ کیلویی یا خمیر ۱۰ کیلویی')
                        ->hint(fn (?InventoryItem $record) => $record?->key === InventoryItem::FLOUR
                            ? 'وزن کیسه آرد از «اطلاعات نانوایی» خوانده می‌شود'
                            : null),

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
        return $item->key === InventoryItem::FLOUR
            ? DoughFormula::fromBakery()->bagWeightKg
            : (float) ($item->bag_weight_kg ?? 0);
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
                    // Derived from the movement ledger, not a stored column.
                    ->state(fn (InventoryItem $record) => number_format($record->balance, 3).' '.$record->unit)
                    ->description(fn (InventoryItem $record) => $record->balance_bags !== null
                        ? number_format($record->balance_bags, 2).' کیسه'
                        : null)
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
                    ->label('ثبت موجودی (کیسه‌ای)')
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
                                ->live(onBlur: true)
                                ->suffix($bagWeight > 0
                                    ? 'هر واحد '.rtrim(rtrim(number_format($bagWeight, 3), '0'), '.').' کیلوگرم'
                                    : null),

                            Forms\Components\Placeholder::make('computed_kg')
                                ->label('معادل کیلوگرم')
                                ->content(function (Forms\Get $get) use ($bagWeight) {
                                    $bags = (float) ($get('bags') ?: 0);

                                    return number_format($bags * $bagWeight, 3).' کیلوگرم';
                                }),

                            Forms\Components\Select::make('reason')
                                ->label('علت')
                                ->options(\App\Models\InventoryMovement::REASONS)
                                ->default('manual')
                                ->required()
                                ->native(false),

                            Forms\Components\Textarea::make('note')
                                ->label('توضیحات')
                                ->rows(2),
                        ];
                    })
                    // A sack size must exist before a bag count means anything —
                    // this only happens for flour when the formula has no bag
                    // weight configured yet.
                    ->disabled(fn (InventoryItem $record) => self::bagWeightFor($record) <= 0)
                    ->tooltip(fn (InventoryItem $record) => self::bagWeightFor($record) <= 0
                        ? 'ابتدا وزن کیسه را در تنظیمات این کالا یا اطلاعات نانوایی ثبت کنید'
                        : null)
                    ->action(function (InventoryItem $record, array $data) {
                        $bagWeight = self::bagWeightFor($record);
                        $kg = round((float) $data['bags'] * $bagWeight, 3);

                        $record->move(
                            $data['direction'],
                            $kg,
                            $data['reason'],
                            auth()->id(),
                            null,
                            $data['note'] ?? null,
                        );

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
