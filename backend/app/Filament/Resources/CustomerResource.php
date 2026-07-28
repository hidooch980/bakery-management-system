<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'مدارس و ادارات';

    protected static ?string $modelLabel = 'مشتری';

    protected static ?string $pluralModelLabel = 'مدارس و ادارات';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات مشتری')
                ->description('این نام‌ها هنگام ثبت فروش نسیه یا مدارس در اپلیکیشن انتخاب می‌شوند.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('type')
                        ->label('نوع')
                        ->options(Customer::TYPES)
                        ->default('school')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('contact_name')
                        ->label('نام رابط')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('تلفن')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('address')
                        ->label('آدرس')
                        ->maxLength(500)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true)
                        ->inline(false)
                        ->onColor('success')
                        ->offColor('danger'),

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
                Tables\Columns\TextColumn::make('name')
                    ->label('نام')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Customer::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'school' => 'info',
                        'office' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('contact_name')
                    ->label('رابط')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('تلفن')
                    ->placeholder('—')
                    ->copyable(),

                Tables\Columns\TextColumn::make('sales_count')
                    ->label('تعداد فروش')
                    ->counts('sales')
                    ->badge()
                    ->color('success'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(Customer::TYPES),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('وضعیت')
                    ->trueLabel('فعال')
                    ->falseLabel('غیرفعال'),
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
            ->defaultSort('name')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InteractionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
