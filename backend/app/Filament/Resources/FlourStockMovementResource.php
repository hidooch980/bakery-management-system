<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FlourStockMovementResource\Pages;
use App\Models\FlourStockMovement;
use App\Support\DoughFormula;
use App\Support\Jalali;
use App\Support\Qty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlourStockMovementResource extends Resource
{
    protected static ?string $model = FlourStockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'موجودی آرد';

    protected static ?string $modelLabel = 'تراکنش آرد';

    protected static ?string $pluralModelLabel = 'موجودی آرد';

    protected static ?int $navigationSort = 4;

    /**
     * Superseded by InventoryItem/InventoryMovement ("موجودی انبار" و
     * "گردش انبار" under انبار), which cover flour, salt and dough with
     * bag sizes and low-stock thresholds. This flour-only ledger stays for
     * the legacy /flour/movements API route, but showing it in the menu
     * alongside its replacement is redundant and confusing.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('تراکنش آرد')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('نوع تراکنش')
                        ->options(['in' => 'ورود آرد (خرید)', 'out' => 'خروج آرد (مصرف)'])
                        ->required()
                        ->native(false),

                    // به کیسه وارد می‌شود و به کیلوگرم ذخیره.
                    Forms\Components\TextInput::make('amount_kg')
                        ->label('مقدار')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->suffix('کیسه')
                        ->helperText(fn () => 'هر کیسه '.Qty::format(DoughFormula::fromBakery()->bagWeightKg, 0).' کیلوگرم؛ کیسهٔ ناقص با اعشار')
                        ->formatStateUsing(fn ($state) => $state === null ? null
                            : round((float) $state / max(DoughFormula::fromBakery()->bagWeightKg, 0.001), 2))
                        ->dehydrateStateUsing(fn ($state) => round((float) $state * DoughFormula::fromBakery()->bagWeightKg, 3)),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn () => auth()->id())
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
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'in' ? 'ورود' : 'خروج')
                    ->color(fn ($state) => $state === 'in' ? 'success' : 'danger')
                    ->icon(fn ($state) => $state === 'in' ? 'heroicon-m-arrow-down-tray' : 'heroicon-m-arrow-up-tray'),

                Tables\Columns\TextColumn::make('amount_kg')
                    ->label('مقدار')
                    ->formatStateUsing(fn ($state) => Qty::flourBags((float) $state))
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('جمع')
                        ->formatStateUsing(fn ($state) => Qty::flourBags((float) $state))),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان')
                    ->formatStateUsing(fn ($state) => Jalali::dateTime($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(['in' => 'ورود', 'out' => 'خروج']),
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
            'index' => Pages\ListFlourStockMovements::route('/'),
            'create' => Pages\CreateFlourStockMovement::route('/create'),
            'edit' => Pages\EditFlourStockMovement::route('/{record}/edit'),
        ];
    }
}
