<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use App\Support\Jalali;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-focused view of staff check-ins. This is where the admin sees the
 * exact time each employee tapped their attendance button.
 */
class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'حضور و غیاب';

    protected static ?string $modelLabel = 'حضور';

    protected static ?string $pluralModelLabel = 'حضور و غیاب';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('ثبت حضور')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('کارمند')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    JalaliDateInput::today('date', 'تاریخ')
                        ->required(),

                    Forms\Components\DateTimePicker::make('checked_in_at')
                        ->label('ساعت حضور')
                        ->default(now())
                        ->seconds(false)
                        ->required()
                        ->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('کارمند')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('user.roles.name')
                    ->label('نقش')
                    ->badge()
                    ->formatStateUsing(fn ($state) => UserResource::roleLabel($state))
                    ->color('info'),

                Tables\Columns\TextColumn::make('date')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('checked_in_at')
                    ->label('ساعت تیک حضور')
                    ->formatStateUsing(fn ($state) => Jalali::time($state))
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-clock')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('ثبت در سیستم')
                    ->formatStateUsing(fn ($state) => Jalali::dateTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('کارمند')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ')->native(false),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d));
                    }),

                Tables\Filters\Filter::make('today')
                    ->label('فقط امروز')
                    ->query(fn ($query) => $query->whereDate('date', now()->toDateString()))
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
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::whereDate('date', now()->toDateString())->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
