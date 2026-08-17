<?php

namespace App\Filament\Resources;

use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
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
                    // Bag count leads, weight follows on the same line — not
                    // stacked as a badge-plus-description, so both read at a
                    // glance together rather than one being a footnote.
                    ->state(function (InventoryItem $record) {
                        $weight = number_format($record->balance, 3).' '.$record->unit;

                        if ($record->balance_bags === null) {
                            return $weight;
                        }

                        return number_format($record->balance_bags, 2).' کیسه'
                            .'   —   '.$weight;
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
                    // Salt and dough are weighed, not bagged, so the form
                    // asks them for kilograms and the label follows suit.
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

                            Forms\Components\Select::make('reason')
                                ->label('علت')
                                ->options(InventoryMovement::REASONS)
                                ->default('manual')
                                ->required()
                                ->native(false),

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
