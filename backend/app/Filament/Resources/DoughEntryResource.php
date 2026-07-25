<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DoughEntryResource\Pages;
use App\Models\DoughEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DoughEntryResource extends Resource
{
    protected static ?string $model = DoughEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'ثبت خمیر';

    protected static ?string $modelLabel = 'خمیر';

    protected static ?string $pluralModelLabel = 'خمیرها';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات خمیر')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('خمیرگیر')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('bag_count')
                        ->label('تعداد کیسه')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->suffix('کیسه'),

                    Forms\Components\Select::make('status')
                        ->label('وضعیت')
                        ->options(['pending' => 'در انتظار چانه', 'processed' => 'چانه شده'])
                        ->default('pending')
                        ->required()
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

                Tables\Columns\TextColumn::make('user.name')
                    ->label('خمیرگیر')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->sortable(),

                Tables\Columns\TextColumn::make('bag_count')
                    ->label('تعداد کیسه')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('warning')
                    ->suffix(' کیسه'),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pending' ? 'در انتظار چانه' : 'چانه شده')
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان ثبت')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(['pending' => 'در انتظار چانه', 'processed' => 'چانه شده']),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('خمیرگیر')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
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
            'index' => Pages\ListDoughEntries::route('/'),
            'create' => Pages\CreateDoughEntry::route('/create'),
            'edit' => Pages\EditDoughEntry::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', 'pending')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
