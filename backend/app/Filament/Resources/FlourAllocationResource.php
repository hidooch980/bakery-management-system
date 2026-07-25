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
                    \App\Filament\Forms\JalaliMonthInput::make('month_start', 'ماه')
                        ->required()
                        ->live(),

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

            Forms\Components\Section::make('آرد سنوات (مانده از قبل)')
                ->description('آردی که از دوره‌های گذشته باقی مانده و به سهمیه این ماه اضافه می‌شود. بین دوره‌ها تقسیم نمی‌شود و ذخیره‌ای است که هر زمان قابل برداشت است.')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->columns(2)
                ->collapsed(fn ($record) => (float) ($record?->carryover_bags ?? 0) === 0.0)
                ->schema([
                    Forms\Components\TextInput::make('carryover_bags')
                        ->label('مانده از قبل')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->suffix('کیسه')
                        ->live(onBlur: true)
                        ->helperText(function ($state) {
                            $bags = (float) ($state ?: 0);

                            if ($bags <= 0) {
                                return 'اگر مانده‌ای از قبل ندارید، صفر بگذارید.';
                            }

                            $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;

                            return number_format($bags, 1).' کیسه = '
                                .number_format($bags * $bagWeight, 1).' کیلوگرم';
                        }),

                    Forms\Components\TextInput::make('carryover_note')
                        ->label('بابت')
                        ->maxLength(255)
                        ->placeholder('مثلاً: مانده سنوات ۱۴۰۴'),

                    Forms\Components\Placeholder::make('available_total')
                        ->label('کل قابل استفاده این ماه')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get) {
                            $quota = (float) ($get('total_bags') ?: 0);
                            $carry = (float) ($get('carryover_bags') ?: 0);

                            if ($quota + $carry <= 0) {
                                return '—';
                            }

                            $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;

                            return sprintf(
                                'سهمیه %s + سنوات %s = %s کیسه (%s کیلوگرم)',
                                number_format($quota, 1),
                                number_format($carry, 1),
                                number_format($quota + $carry, 1),
                                number_format(($quota + $carry) * $bagWeight, 1),
                            );
                        }),
                ]),

            Forms\Components\Section::make('دوره‌های تحویل')
                ->description('سهمیه به‌طور مساوی بین این سه دوره تقسیم می‌شود.')
                ->icon('heroicon-o-calendar-days')
                ->schema([
                    Forms\Components\Placeholder::make('period_windows')
                        ->hiddenLabel()
                        ->content(function (Forms\Get $get) {
                            $month = $get('month_start');
                            $bags = (float) ($get('total_bags') ?: 0);

                            if (blank($month)) {
                                return 'ابتدا ماه را انتخاب کنید.';
                            }

                            $start = \Illuminate\Support\Carbon::parse($month);
                            $lines = [];

                            foreach (array_keys(FlourAllocation::PERIODS) as $number) {
                                [$from, $to] = FlourAllocation::periodRange($start, $number);

                                $share = $number === 3
                                    ? $bags - 2 * round($bags / 3, 2)
                                    : round($bags / 3, 2);

                                $lines[] = sprintf(
                                    '%s   —   %s تا %s%s',
                                    FlourAllocation::PERIODS[$number]['label'],
                                    Jalali::date($from),
                                    Jalali::date($to),
                                    $bags > 0 ? '   —   '.number_format($share, 1).' کیسه' : ''
                                );
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<div style="line-height:2">'.implode('<br>', $lines).'</div>'
                            );
                        }),
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
                    ->label('سهمیه ماه')
                    ->formatStateUsing(fn ($state) => $state
                        ? number_format((float) $state, 0).' کیسه'
                        : '—')
                    ->description(fn ($record) => number_format((float) $record->total_kg, 1).' کیلوگرم')
                    ->sortable(),

                Tables\Columns\TextColumn::make('carryover_bags')
                    ->label('سنوات')
                    ->formatStateUsing(fn ($state) => (float) $state > 0
                        ? number_format((float) $state, 0).' کیسه'
                        : '—')
                    ->description(fn (FlourAllocation $record) => $record->carryover_note)
                    ->badge()
                    ->color(fn ($state) => (float) $state > 0 ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('available_bags')
                    ->label('کل قابل استفاده')
                    ->state(fn (FlourAllocation $record) => number_format($record->available_bags, 0).' کیسه')
                    ->description(fn (FlourAllocation $record) => number_format($record->available_kg, 1).' کیلوگرم')
                    ->badge()
                    ->color('success')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('periods_summary')
                    ->label('دوره‌ها')
                    ->state(function (FlourAllocation $record) {
                        return $record->periods
                            ->map(fn ($p) => sprintf(
                                '%d) %s تا %s',
                                $p->period_number,
                                Jalali::date($p->starts_on),
                                Jalali::date($p->ends_on),
                            ))
                            ->implode("\n");
                    })
                    ->wrap()
                    ->description(fn (FlourAllocation $record) => $record->periods
                        ->map(fn ($p) => number_format($p->used_kg, 0).'/'
                            .number_format((float) $p->allocated_kg, 0))
                        ->implode('   •   ').'  (مصرف/سهمیه kg)'),

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
