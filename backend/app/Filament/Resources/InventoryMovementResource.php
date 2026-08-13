<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'گردش انبار';

    protected static ?string $modelLabel = 'تراکنش انبار';

    protected static ?string $pluralModelLabel = 'گردش انبار';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('تراکنش انبار')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('inventory_item_id')
                        ->label('کالا')
                        ->options(fn () => InventoryItem::pluck('name', 'id'))
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('direction')
                        ->label('نوع')
                        ->options(['in' => 'ورود', 'out' => 'خروج'])
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('quantity')
                        ->label('مقدار')
                        ->numeric()
                        ->minValue(0.001)
                        ->required()
                        ->suffix('کیلوگرم'),

                    Forms\Components\Select::make('reason')
                        ->label('علت')
                        ->options(InventoryMovement::REASONS)
                        ->default('manual')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload()
                        ->native(false),

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
                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان')
                    ->formatStateUsing(fn ($state) => AppCalendar::dateTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.name')
                    ->label('کالا')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('direction')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'in' ? 'ورود' : 'خروج')
                    ->color(fn ($state) => $state === 'in' ? 'success' : 'danger')
                    ->icon(fn ($state) => $state === 'in'
                        ? 'heroicon-m-arrow-down-tray'
                        : 'heroicon-m-arrow-up-tray'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('مقدار')
                    ->numeric(3)
                    ->suffix(' کیلوگرم')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('علت')
                    ->formatStateUsing(fn ($state) => InventoryMovement::REASONS[$state] ?? $state),

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
                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('کالا')
                    ->options(fn () => InventoryItem::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('direction')
                    ->label('نوع')
                    ->options(['in' => 'ورود', 'out' => 'خروج']),

                Tables\Filters\SelectFilter::make('reason')
                    ->label('علت')
                    ->options(InventoryMovement::REASONS),
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
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
            'create' => Pages\CreateInventoryMovement::route('/create'),
            'edit' => Pages\EditInventoryMovement::route('/{record}/edit'),
        ];
    }
}
