<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChaneEntryResource\Pages;
use App\Models\ChaneEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChaneEntryResource extends Resource
{
    protected static ?string $model = ChaneEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'ثبت چانه';

    protected static ?string $modelLabel = 'چانه';

    protected static ?string $pluralModelLabel = 'چانه‌ها';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات چانه')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('dough_entry_id')
                        ->label('خمیر مرتبط')
                        ->relationship('doughEntry', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "خمیر #{$record->id} — {$record->bag_count} کیسه")
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('user_id')
                        ->label('چانه‌گیر')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('chane_count')
                        ->label('تعداد چانه')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->suffix('عدد'),

                    Forms\Components\Select::make('status')
                        ->label('وضعیت')
                        ->options(['pending' => 'در انتظار فروش', 'sold' => 'فروخته شده'])
                        ->default('pending')
                        ->required()
                        ->native(false),
                ]),

            Forms\Components\Section::make('اوزان (کیلوگرم)')
                ->icon('heroicon-o-scale')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('normal_weight_kg')
                        ->label('وزن چانه عادی')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('kg'),

                    Forms\Components\TextInput::make('nanino_weight_kg')
                        ->label('وزن چانه نانینو')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('kg'),

                    Forms\Components\TextInput::make('spray_flour_kg')
                        ->label('آرد پاششی مصرفی')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('kg'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('چانه‌گیر')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->sortable(),

                Tables\Columns\TextColumn::make('dough_entry_id')
                    ->label('خمیر')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('chane_count')
                    ->label('تعداد چانه')
                    ->numeric()
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('normal_weight_kg')
                    ->label('وزن عادی')
                    ->numeric(2)
                    ->suffix(' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('nanino_weight_kg')
                    ->label('وزن نانینو')
                    ->numeric(2)
                    ->suffix(' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_weight')
                    ->label('وزن کل')
                    ->state(fn (ChaneEntry $record) => number_format($record->normal_weight_kg + $record->nanino_weight_kg, 2).' kg')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('spray_flour_kg')
                    ->label('آرد پاششی')
                    ->numeric(2)
                    ->suffix(' kg')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pending' ? 'در انتظار فروش' : 'فروخته شده')
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان ثبت')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(['pending' => 'در انتظار فروش', 'sold' => 'فروخته شده']),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('چانه‌گیر')
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
            'index' => Pages\ListChaneEntries::route('/'),
            'create' => Pages\CreateChaneEntry::route('/create'),
            'edit' => Pages\EditChaneEntry::route('/{record}/edit'),
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
