<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FixedAssetResource\Pages;
use App\Models\FixedAsset;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The oven, the van, the building — what the shop owns that the day's work
 * never mentions, and so has to be written down once.
 */
class FixedAssetResource extends Resource
{
    protected static ?string $model = FixedAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?string $navigationLabel = 'دارایی ثابت';

    protected static ?string $modelLabel = 'دارایی ثابت';

    protected static ?string $pluralModelLabel = 'دارایی‌های ثابت';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات')->columns(2)->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان')
                    ->placeholder('مثلاً تنور دوار')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('category')
                    ->label('نوع')
                    ->options(FixedAsset::CATEGORIES)
                    ->default('equipment')
                    ->native(false)
                    ->required(),

                Forms\Components\TextInput::make('purchase_price')
                    ->label('قیمت خرید')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(Money::label())
                    ->required(),

                Forms\Components\TextInput::make('current_value')
                    ->label('ارزش فعلی')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(Money::label())
                    ->helperText('اگر خالی بماند، همان قیمت خرید حساب می‌شود.'
                        .' یک تنور پنج‌ساله معمولاً کمتر می‌ارزد.'),

                Forms\Components\DatePicker::make('purchased_on')
                    ->label('تاریخ خرید')
                    ->native(false),

                Forms\Components\DatePicker::make('disposed_on')
                    ->label('تاریخ فروش یا اسقاط')
                    ->native(false)
                    ->helperText('اگر پر شود، دیگر جزو دارایی حساب نمی‌شود'
                        .' — ولی سابقه‌اش می‌ماند.'),

                Forms\Components\Textarea::make('note')
                    ->label('توضیحات')
                    ->columnSpanFull()
                    ->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('category_label')->label('نوع')->badge(),

                Tables\Columns\TextColumn::make('purchase_price')
                    ->label('قیمت خرید')
                    ->formatStateUsing(fn ($state) => Money::format($state)),

                Tables\Columns\TextColumn::make('value_formatted')
                    ->label('ارزش فعلی')
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('purchased_on_display')->label('تاریخ خرید'),

                Tables\Columns\IconColumn::make('disposed_on')
                    ->label('در اختیار')
                    ->boolean()
                    ->getStateUsing(fn (FixedAsset $record) => $record->disposed_on === null),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('disposed_on')
                    ->label('وضعیت')
                    ->placeholder('همه')
                    ->trueLabel('فروخته یا اسقاط‌شده')
                    ->falseLabel('در اختیار')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('disposed_on'),
                        false: fn ($query) => $query->whereNull('disposed_on'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->emptyStateHeading('هنوز دارایی ثابتی ثبت نشده')
            ->emptyStateDescription('تنور، وانت، یخچال و ساختمان اینجا ثبت می‌شوند'
                .' تا در تراز مالی دیده شوند.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFixedAssets::route('/'),
            'create' => Pages\CreateFixedAsset::route('/create'),
            'edit' => Pages\EditFixedAsset::route('/{record}/edit'),
        ];
    }
}
