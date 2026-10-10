<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Qty;
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

    /**
     * از منو برداشته شد، نه حذف.
     *
     * مالک گفت صفحه را پر کرده و راست می‌گوید: کسی که نان می‌فروشد روزش
     * را با فهرست تراکنش‌های انبار شروع نمی‌کند. «موجودی انبار» جواب
     * روزمره را می‌دهد و این یکی جواب «چرا».
     *
     * ولی حذفش نکردم، چون همین ردیف‌ها بودند که ۱۴۰۵/۰۶/۲۳ نشان دادند
     * دفتر آرد ۸ کیسه کم دارد و پیش از آن ۲۳۶ کیسه اصلاح دستی خورده —
     * چیزی که از هیچ صفحهٔ دیگری پیدا نمی‌شد. صفحه سر جایش است و از
     * «موجودی انبار» می‌شود به آن رسید؛ فقط دیگر جا نمی‌گیرد.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** آیا این کالا آرد است؟ آرد به کیسه دیده و وارد می‌شود. */
    public static function isFlour($itemId): bool
    {
        if (blank($itemId)) {
            return false;
        }

        return InventoryItem::query()->whereKey($itemId)->value('key') === InventoryItem::FLOUR
            && DoughFormula::fromBakery()->bagWeightKg > 0;
    }

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

                    // آرد به کیسه وارد می‌شود و به کیلوگرم ذخیره؛ بقیهٔ کالاها کیلوگرم.
                    Forms\Components\TextInput::make('quantity')
                        ->label(fn (Forms\Get $get) => self::isFlour($get('inventory_item_id')) ? 'تعداد کیسه' : 'مقدار')
                        ->numeric()
                        ->minValue(0.001)
                        ->required()
                        ->live(onBlur: true)
                        ->suffix(fn (Forms\Get $get) => self::isFlour($get('inventory_item_id')) ? 'کیسه' : 'کیلوگرم')
                        ->helperText(fn (Forms\Get $get) => self::isFlour($get('inventory_item_id'))
                            ? 'هر کیسه '.Qty::format(DoughFormula::fromBakery()->bagWeightKg, 0).' کیلوگرم؛ کیسهٔ ناقص با اعشار'
                            : null)
                        ->formatStateUsing(fn ($state, ?InventoryMovement $record) => $state !== null && $record && self::isFlour($record->inventory_item_id)
                            ? round((float) $state / max(DoughFormula::fromBakery()->bagWeightKg, 0.001), 2)
                            : $state)
                        ->dehydrateStateUsing(fn ($state, Forms\Get $get) => self::isFlour($get('inventory_item_id'))
                            ? round((float) $state * DoughFormula::fromBakery()->bagWeightKg, 3)
                            : $state),

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
                    ->formatStateUsing(fn ($state, InventoryMovement $record) => self::isFlour($record->inventory_item_id)
                        ? Qty::flourBags((float) $state)
                        : Qty::format((float) $state, 3).' کیلوگرم')
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
