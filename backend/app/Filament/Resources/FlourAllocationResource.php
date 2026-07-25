<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FlourAllocationResource\Pages;
use App\Models\FlourAllocation;
use App\Support\AppCalendar;
use App\Support\Jalali;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlourAllocationResource extends Resource
{
    protected static ?string $model = FlourAllocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'انبار';

    protected static ?string $navigationLabel = 'سهمیه آرد';

    protected static ?string $modelLabel = 'سهمیه';

    protected static ?string $pluralModelLabel = 'سهمیه آرد';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('سهمیه ماهانه')
                ->description('سهمیه به‌صورت خودکار بین سه دوره تقسیم می‌شود: ۵ تا ۱۴، ۱۵ تا ۲۴، و ۲۵ تا ۴ ماه بعد.')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('month_start')
                        ->label('شروع ماه')
                        ->default(now()->startOfMonth())
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText(fn ($state) => $state
                            ? 'ماه شمسی: '.Jalali::monthLabel($state)
                            : null),

                    Forms\Components\TextInput::make('total_kg')
                        ->label('کل سهمیه ماه')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('kg')
                        ->live(onBlur: true)
                        ->helperText(fn ($state) => $state
                            ? 'سهم هر دوره حدود '.number_format((float) $state / 3, 1).' کیلوگرم'
                            : null),

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
                Tables\Columns\TextColumn::make('month_label')
                    ->label('ماه')
                    ->weight('bold')
                    ->badge()
                    ->color('info')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('month_start', $direction)),

                Tables\Columns\TextColumn::make('total_kg')
                    ->label('کل سهمیه')
                    ->numeric(3)
                    ->suffix(' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('periods_summary')
                    ->label('دوره‌ها')
                    ->state(function (FlourAllocation $record) {
                        return $record->periods
                            ->map(fn ($p) => "{$p->period_number}: ".number_format($p->used_kg, 0)
                                .'/'.number_format((float) $p->allocated_kg, 0))
                            ->implode('   •   ');
                    })
                    ->description('مصرف / سهمیه هر دوره (kg)'),

                Tables\Columns\TextColumn::make('current')
                    ->label('دوره جاری')
                    ->state(function (FlourAllocation $record) {
                        $period = $record->periodFor(now());

                        return $period
                            ? $period->label.' — '.$period->usage_percent.'٪'
                            : '—';
                    })
                    ->badge()
                    ->color(function (FlourAllocation $record) {
                        $period = $record->periodFor(now());

                        if (! $period) {
                            return 'gray';
                        }

                        return $period->is_over ? 'danger' : ($period->usage_percent > 80 ? 'warning' : 'success');
                    }),
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
            ->defaultSort('month_start', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlourAllocations::route('/'),
            'create' => Pages\CreateFlourAllocation::route('/create'),
            'edit' => Pages\EditFlourAllocation::route('/{record}/edit'),
        ];
    }
}
