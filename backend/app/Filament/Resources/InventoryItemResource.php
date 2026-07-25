<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use Filament\Forms;
use Filament\Forms\Form;
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

                    Forms\Components\TextInput::make('low_threshold')
                        ->label('حد هشدار موجودی')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('اگر موجودی به این مقدار برسد، هشدار داده می‌شود.'),
                ]),
        ]);
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
