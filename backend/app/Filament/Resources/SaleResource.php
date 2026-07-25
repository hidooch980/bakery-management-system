<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'فروش';

    protected static ?string $modelLabel = 'فروش';

    protected static ?string $pluralModelLabel = 'فروش‌ها';

    protected static ?int $navigationSort = 3;

    public const PAYMENT_LABELS = [
        'cash' => 'نقد',
        'card' => 'کارتخوان',
        'credit' => 'نسیه',
        'home' => 'منزل',
        'schools' => 'مدارس',
        'other' => 'سایر',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات فروش')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('chane_entry_id')
                        ->label('چانه مرتبط')
                        ->relationship('chaneEntry', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "چانه #{$record->id} — {$record->chane_count} عدد")
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('user_id')
                        ->label('فروشنده')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('payment_type')
                        ->label('نوع پرداخت')
                        ->options(self::PAYMENT_LABELS)
                        ->required()
                        ->native(false),

                    \App\Filament\Forms\MoneyInput::make('amount', 'مبلغ'),

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

                Tables\Columns\TextColumn::make('user.name')
                    ->label('فروشنده')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->sortable(),

                Tables\Columns\TextColumn::make('chane_entry_id')
                    ->label('چانه')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('chaneEntry.chane_count')
                    ->label('تعداد')
                    ->numeric()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payment_type')
                    ->label('نوع پرداخت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::PAYMENT_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'cash' => 'success',
                        'card' => 'info',
                        'credit' => 'danger',
                        'home' => 'warning',
                        'schools' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : \App\Support\Money::format($state))
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => \App\Support\Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان فروش')
                    ->formatStateUsing(fn ($state) => \App\Support\Jalali::dateTime($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_type')
                    ->label('نوع پرداخت')
                    ->options(self::PAYMENT_LABELS),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('فروشنده')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('today')
                    ->label('فقط امروز')
                    ->query(fn ($query) => $query->whereDate('created_at', now()->toDateString()))
                    ->toggle(),
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
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
