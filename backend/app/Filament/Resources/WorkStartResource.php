<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WorkStartResource\Pages;
use App\Models\WorkStart;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WorkStartResource extends Resource
{
    protected static ?string $model = WorkStart::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'شروع کار روزانه';

    protected static ?string $modelLabel = 'شروع کار';

    protected static ?string $pluralModelLabel = 'شروع کار روزانه';

    protected static ?int $navigationSort = 3;

    /** Late starts are what the admin needs to notice. */
    public static function getNavigationBadge(): ?string
    {
        $count = WorkStart::late()
            ->whereBetween('date', \App\Support\Jalali::currentMonthRange())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('ثبت شروع کار')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('نوع')
                        ->options(WorkStart::TYPES)
                        ->required()
                        ->native(false),

                    \App\Filament\Forms\JalaliDateInput::today('date', 'تاریخ')
                        ->required(),

                    Forms\Components\DateTimePicker::make('started_at')
                        ->label('ساعت شروع')
                        ->seconds(false)
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label('ثبت‌کننده')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false),

                    Forms\Components\Toggle::make('is_late')
                        ->label('تأخیر داشته')
                        ->helperText('در ثبت از اپلیکیشن خودکار تعیین می‌شود'),

                    Forms\Components\TextInput::make('late_minutes')
                        ->label('دقیقه تأخیر')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

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
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkStart::TYPES[$state] ?? $state)
                    ->color(fn ($state) => $state === WorkStart::BAKING ? 'warning' : 'info'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('ساعت شروع')
                    ->formatStateUsing(fn (WorkStart $r) => $r->started_at_time)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('deadline')
                    ->label('مهلت')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 5)),

                Tables\Columns\TextColumn::make('is_late')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn (WorkStart $r) => $r->is_late
                        ? $r->late_minutes.' دقیقه تأخیر'
                        : 'به‌موقع')
                    ->color(fn (WorkStart $r) => $r->is_late ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(WorkStart::TYPES),

                Tables\Filters\Filter::make('late')
                    ->label('فقط تأخیرها')
                    ->query(fn ($query) => $query->late()),

                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->defaultSort('date', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkStarts::route('/'),
            'create' => Pages\CreateWorkStart::route('/create'),
            'edit' => Pages\EditWorkStart::route('/{record}/edit'),
        ];
    }
}
