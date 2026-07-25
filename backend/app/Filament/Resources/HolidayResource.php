<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Holiday;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'تعطیلات';

    protected static ?string $modelLabel = 'تعطیلی';

    protected static ?string $pluralModelLabel = 'تعطیلات';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('روز تعطیل')
                ->description('روزهایی که نانوایی تعطیل است. در گزارش حضور و غیاب، غیبت محسوب نمی‌شوند.')
                ->columns(2)
                ->schema([
                    \App\Filament\Forms\JalaliDateInput::today('date', 'تاریخ')
                        ->required()
                        // One record per day keeps the calendar unambiguous.
                        ->unique(ignoreRecord: true),

                    Forms\Components\Select::make('type')
                        ->label('نوع')
                        ->options(Holiday::TYPES)
                        ->default('official')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('title')
                        ->label('مناسبت')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('مثلاً: عید سعید فطر'),

                    Forms\Components\Toggle::make('repeats_monthly')
                        ->label('تکرار در ماه‌های بعد')
                        ->inline(false)
                        ->onColor('success')
                        // Official and religious dates move; only a shop
                        // closure falls on the same day each month.
                        ->visible(fn (Forms\Get $get) => $get('type') === Holiday::REPEATABLE_TYPE)
                        ->helperText('همین روز در ۱۲ ماه آینده به‌صورت خودکار ثبت می‌شود.'),

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
                Tables\Columns\TextColumn::make('date')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->description(fn (Holiday $record) => AppCalendar::monthLabel($record->date))
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('مناسبت')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Holiday::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'official' => 'danger',
                        'religious' => 'info',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('repeats_monthly')
                    ->label('تکرار')
                    ->badge()
                    ->state(function (Holiday $record) {
                        if ($record->is_rule) {
                            return 'ماهانه';
                        }

                        return $record->repeats_from_id ? 'خودکار' : '—';
                    })
                    ->color(fn ($state) => match ($state) {
                        'ماهانه' => 'success',
                        'خودکار' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_past')
                    ->label('گذشته')
                    ->state(fn (Holiday $record) => $record->date->isPast())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('gray')
                    ->falseColor('success'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(Holiday::TYPES),

                Tables\Filters\Filter::make('upcoming')
                    ->label('فقط پیش‌رو')
                    ->query(fn ($query) => $query->upcoming())
                    ->toggle(),

                Tables\Filters\Filter::make('recurring')
                    ->label('فقط تکرارشونده‌ها')
                    ->query(fn ($query) => $query->where('repeats_monthly', true))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('همین ماه')
                    ->query(fn ($query) => $query->inJalaliMonth(now()))
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
            ->defaultSort('date', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHolidays::route('/'),
            'create' => Pages\CreateHoliday::route('/create'),
            'edit' => Pages\EditHoliday::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $thisMonth = static::getModel()::query()->inJalaliMonth(now())->count();

        return $thisMonth > 0 ? (string) $thisMonth : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
