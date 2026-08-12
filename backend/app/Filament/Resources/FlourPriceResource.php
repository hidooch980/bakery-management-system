<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\FlourPriceResource\Pages;
use App\Models\FlourPrice;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What flour cost, and from when.
 *
 * The shop carried one price and the cost of goods read it for every
 * period, so entering today's higher price rewrote last month's profit —
 * and the partners' split with it. Each row here is dated, and a bake is
 * costed at the price in force on the day it happened.
 */
class FlourPriceResource extends Resource
{
    protected static ?string $model = FlourPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'قیمت خرید آرد';

    protected static ?string $modelLabel = 'قیمت آرد';

    protected static ?string $pluralModelLabel = 'قیمت خرید آرد';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('قیمت جدید')
                ->description('قیمت قبلی پاک نمی‌شود؛ فقط از این تاریخ به بعد قیمت تازه اعمال می‌شود.')
                ->icon('heroicon-o-receipt-percent')
                ->columns(2)
                ->schema([
                    MoneyInput::make('price_per_kg', 'قیمت هر کیلو')
                        ->required(),

                    JalaliDateInput::make('effective_from', 'از تاریخ')
                        ->required()
                        ->default(fn () => now()->toDateString())
                        ->helperText('نان‌های پخته‌شده پیش از این تاریخ با قیمت قبلی حساب می‌شوند.'),

                    Forms\Components\TextInput::make('note')
                        ->label('توضیح')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('مثلاً: افزایش نرخ کارخانه'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Newest first: the price in force is the one at the top.
            ->defaultSort('effective_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('effective_from')
                    ->label('از تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('price_per_kg')
                    ->label('هر کیلو')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state)),

                Tables\Columns\IconColumn::make('in_force')
                    ->label('در حال اجرا')
                    ->state(fn (FlourPrice $record) => $record->id === static::inForceId())
                    ->boolean(),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Deleting a price re-costs every bake that fell under it,
                // so it is worth thinking about rather than hidden away.
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('با حذف این قیمت، بهای نان‌های پخته‌شده در آن بازه با قیمت قدیمی‌تر حساب می‌شود.'),
            ]);
    }

    /** The row whose price applies today, so the table can mark it. */
    private static function inForceId(): ?int
    {
        return FlourPrice::query()
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlourPrices::route('/'),
            'create' => Pages\CreateFlourPrice::route('/create'),
            'edit' => Pages\EditFlourPrice::route('/{record}/edit'),
        ];
    }
}
