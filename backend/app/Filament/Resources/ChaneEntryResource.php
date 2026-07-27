<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChaneEntryResource\Pages;
use App\Models\ChaneEntry;
use App\Support\DoughFormula;
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
                        ->label('تعداد چانه عادی')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->live(onBlur: true)
                        ->suffix('عدد')
                        ->helperText('ملاک فروش، موجودی و گزارش‌ها'),

                    // Not a database column — a separate count purely for the
                    // nanino display weight below, exactly like the mobile
                    // app's chane gir screen. Nanino has no count column of
                    // its own; only the weight it derives to is stored.
                    Forms\Components\TextInput::make('nanino_chane_count')
                        ->label('تعداد چانه نانینو')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->suffix('عدد')
                        ->helperText('در فروش و گزارش‌ها دخالتی ندارد، اما خمیر مصرفی آن از انبار کم می‌شود')
                        ->dehydrated(false),

                    Forms\Components\Select::make('status')
                        ->label('وضعیت')
                        ->options(['pending' => 'در انتظار فروش', 'sold' => 'فروخته شده'])
                        ->default('pending')
                        ->required()
                        ->native(false),
                ]),

            Forms\Components\Section::make('اوزان (کیلوگرم)')
                ->description('وزن از تعداد چانه × وزن هر چانه در «اطلاعات نانوایی» محاسبه می‌شود — قابل ویرایش دستی نیست، تا هیچ‌گاه با فرمول تولید در تناقض نباشد.')
                ->icon('heroicon-o-scale')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('normal_weight_preview')
                        ->label('وزن چانه عادی')
                        ->content(function (Forms\Get $get) {
                            $weight = DoughFormula::fromBakery()
                                ->weightForNormalChane((int) ($get('chane_count') ?: 0));

                            return $weight === null
                                ? 'وزن هر چانه عادی در تنظیمات ثبت نشده است'
                                : number_format($weight, 2).' کیلوگرم';
                        }),

                    Forms\Components\Placeholder::make('nanino_weight_preview')
                        ->label('وزن چانه نانینو')
                        ->content(function (Forms\Get $get) {
                            $weight = DoughFormula::fromBakery()
                                ->weightForNaninoChane((int) ($get('nanino_chane_count') ?: 0));

                            return $weight === null
                                ? 'وزن هر چانه نانینو در تنظیمات ثبت نشده است'
                                : number_format($weight, 2).' کیلوگرم';
                        }),

                    Forms\Components\TextInput::make('spray_flour_kg')
                        ->label('آرد پاششی مصرفی')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('کیلوگرم'),

                    Forms\Components\Placeholder::make('comparison_preview')
                        ->label('مقایسه عادی / نانینو')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get) {
                            $formula = DoughFormula::fromBakery();
                            $normalCount = (int) ($get('chane_count') ?: 0);
                            $naninoCount = (int) ($get('nanino_chane_count') ?: 0);

                            if ($normalCount === 0 && $naninoCount === 0) {
                                return '—';
                            }

                            $normalWeight = $formula->weightForNormalChane($normalCount) ?? 0;
                            $naninoWeight = $formula->weightForNaninoChane($naninoCount) ?? 0;
                            $total = $normalCount + $naninoCount;

                            $normalShare = $total > 0 ? round($normalCount / $total * 100) : 0;
                            $naninoShare = $total > 0 ? round($naninoCount / $total * 100) : 0;

                            return sprintf(
                                'عادی: %d عدد (%s کیلوگرم) — %d٪   •   نانینو: %d عدد (%s کیلوگرم) — %d٪',
                                $normalCount,
                                number_format($normalWeight, 1),
                                $normalShare,
                                $naninoCount,
                                number_format($naninoWeight, 1),
                                $naninoShare,
                            );
                        }),
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
                    // How the batch was counted out, when it was recorded
                    // tray by tray rather than as one figure.
                    ->description(fn (ChaneEntry $record) => $record->tray_count
                        ? $record->tray_count.' تشتک: '.$record->tray_breakdown
                        : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('normal_weight_kg')
                    ->label('وزن عادی')
                    ->numeric(2)
                    ->suffix(' کیلوگرم')
                    ->sortable(),

                Tables\Columns\TextColumn::make('nanino_weight_kg')
                    ->label('وزن نانینو')
                    ->numeric(2)
                    ->suffix(' کیلوگرم')
                    ->sortable()
                    ->description('نمایشی'),

                Tables\Columns\TextColumn::make('weight_kg')
                    ->label('وزن ملاک')
                    // Normal chane only — the figure sales and stock use.
                    ->state(fn (ChaneEntry $record) => number_format($record->weight_kg, 2).' کیلوگرم')
                    ->badge()
                    ->color('success')
                    ->description('فقط چانه عادی'),

                Tables\Columns\TextColumn::make('spray_flour_kg')
                    ->label('آرد پاششی')
                    ->numeric(2)
                    ->suffix(' کیلوگرم')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pending' ? 'در انتظار فروش' : 'فروخته شده')
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان ثبت')
                    ->formatStateUsing(fn ($state) => \App\Support\Jalali::dateTime($state))
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
