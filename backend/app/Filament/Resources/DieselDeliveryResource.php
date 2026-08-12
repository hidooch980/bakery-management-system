<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\DieselDeliveryResource\Pages;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Diesel arriving, drawn against the month's quota.
 *
 * What a tanker dropped is a figure off a docket. Litres burned per hour
 * would be a guess dressed up as a measurement, so the shop records what
 * came rather than what the oven ate.
 */
class DieselDeliveryResource extends Resource
{
    protected static ?string $model = DieselDelivery::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'تحویل گازوئیل';

    protected static ?string $modelLabel = 'تحویل گازوئیل';

    protected static ?string $pluralModelLabel = 'تحویل‌های گازوئیل';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('تحویل')
                ->icon('heroicon-o-truck')
                ->columns(2)
                ->schema([
                    JalaliDateInput::make('received_on', 'تاریخ تحویل')
                        ->required()
                        ->default(fn () => now()->toDateString()),

                    Forms\Components\TextInput::make('litres')
                        ->label('مقدار (لیتر)')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        // The remaining quota, said while they are typing
                        // rather than after they have committed to a figure.
                        ->helperText(function () {
                            $quota = DieselAllocation::current();

                            if (! $quota) {
                                return 'برای این ماه سهمیه‌ای ثبت نشده است.';
                            }

                            return 'مانده سهمیه این ماه: '
                                .number_format($quota->remaining_litres, 0).' لیتر';
                        }),

                    MoneyInput::make('amount', 'مبلغ پرداختی')
                        ->helperText('خالی بگذارید اگر سهمیه‌ای و بدون پرداخت بوده.'),

                    Forms\Components\TextInput::make('docket_number')
                        ->label('شماره حواله')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('note')
                        ->label('توضیح')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('received_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('litres')
                    ->label('لیتر')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->state(fn (DieselDelivery $r) => $r->amount_formatted)
                    ->color(fn (DieselDelivery $r) => $r->was_paid_for ? null : 'gray'),

                Tables\Columns\TextColumn::make('docket_number')
                    ->label('حواله')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDieselDeliveries::route('/'),
            'create' => Pages\CreateDieselDelivery::route('/create'),
            'edit' => Pages\EditDieselDelivery::route('/{record}/edit'),
        ];
    }
}
