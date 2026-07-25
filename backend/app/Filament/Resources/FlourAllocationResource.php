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
                ->description('سهمیه را به کیسه وارد کنید؛ وزن از روی وزن کیسه در تنظیمات محاسبه و بین سه دوره (۵ تا ۱۴، ۱۵ تا ۲۴، ۲۵ تا ۴ ماه بعد) تقسیم می‌شود.')
                ->columns(2)
                ->schema([
                    \App\Filament\Forms\JalaliDateInput::make('month_start', 'شروع ماه شمسی')
                        ->required()
                        ->default(fn () => Jalali::currentMonthRange()[0]->toDateString())
                        ->live(onBlur: true)
                        ->helperText(fn ($state) => $state && Jalali::parse($state)
                            ? 'ماه: '.Jalali::monthLabel(Jalali::parse($state))
                            : 'اول ماه شمسی — مثال: 1405/05/01'),

                    Forms\Components\TextInput::make('total_bags')
                        ->label('کل سهمیه ماه')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('کیسه')
                        ->live(onBlur: true)
                        ->helperText(function ($state) {
                            $bags = (float) ($state ?: 0);

                            if ($bags <= 0) {
                                return 'تعداد کیسه را وارد کنید؛ وزن خودکار محاسبه می‌شود.';
                            }

                            $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;
                            $kg = $bags * $bagWeight;

                            return sprintf(
                                '%s کیسه × %s کیلوگرم = %s کیلوگرم   •   سهم هر دوره حدود %s کیسه',
                                number_format($bags, 0),
                                number_format($bagWeight, 1),
                                number_format($kg, 1),
                                number_format($bags / 3, 1)
                            );
                        }),

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

                Tables\Columns\TextColumn::make('total_bags')
                    ->label('کل سهمیه')
                    ->formatStateUsing(fn ($state) => $state
                        ? number_format((float) $state, 0).' کیسه'
                        : '—')
                    ->description(fn ($record) => number_format((float) $record->total_kg, 1).' کیلوگرم')
                    ->sortable(),

                Tables\Columns\TextColumn::make('periods_summary')
                    ->label('دوره‌ها')
                    ->state(function (FlourAllocation $record) {
                        return $record->periods
                            ->map(fn ($p) => "{$p->period_number}: ".number_format($p->used_kg, 0)
                                .'/'.number_format((float) $p->allocated_kg, 0))
                            ->implode('   •   ');
                    })
                    ->description('مصرف / سهمیه هر دوره (کیلوگرم)'),

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
