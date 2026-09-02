<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliMonthInput;
use App\Filament\Resources\FlourAllocationResource\Pages;
use App\Models\FlourAllocation;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class FlourAllocationResource extends Resource
{
    protected static ?string $model = FlourAllocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

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
                    JalaliMonthInput::make('month_start', 'ماه')
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

                            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
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

                            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

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

                            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

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

                            $start = Carbon::parse($month);
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

                            return new HtmlString(
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
                                '%d) %s تا %s  (%d روز کاری از %d)',
                                $p->period_number,
                                Jalali::date($p->starts_on),
                                Jalali::date($p->ends_on),
                                $p->working_days,
                                $p->total_days,
                            ))
                            ->implode("\n");
                    })
                    ->wrap()
                    ->description(fn (FlourAllocation $record) => $record->periods
                        ->map(fn ($p) => number_format($p->used_kg, 0).'/'
                            .number_format((float) $p->allocated_kg, 0))
                        ->implode('   •   ').'  (مصرف/سهمیه کیلوگرم)'),

                // Flour is only ever measured against the card reader, and
                // the gap between the two is worked out for each period
                // rather than left to be counted by hand.
                Tables\Columns\TextColumn::make('card_difference')
                    ->label('اختلاف با کارتخوان')
                    ->state(fn (FlourAllocation $record) => $record->periods
                        ->map(fn ($p) => sprintf(
                            '%d) %s%s',
                            $p->period_number,
                            $p->bread_remainder >= 0 ? '+' : '−',
                            number_format(abs($p->bread_remainder)),
                        ))
                        ->implode('   •   '))
                    ->description('سهمیه دوره منهای نان کارتخوان')
                    ->toggleable(),

                // Reader against our own record. Blank where nobody has
                // checked yet, because «not checked» and «agrees» must not
                // look the same.
                Tables\Columns\TextColumn::make('reader_gap')
                    ->label('کارتخوان در برابر ثبت ما')
                    ->state(fn (FlourAllocation $record) => $record->periods
                        ->map(function ($p) {
                            if (! $p->is_checked_against_reader) {
                                return $p->period_number.') بررسی نشده';
                            }

                            $gap = $p->system_gap;

                            return sprintf(
                                '%d) %s',
                                $p->period_number,
                                $gap === 0
                                    ? 'می‌خواند'
                                    : ($gap > 0 ? '+' : '−').number_format(abs($gap)),
                            );
                        })
                        ->implode('   •   '))
                    ->description('منفی یعنی سامانه کمتر از ثبت ما دیده')
                    ->toggleable(),

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
                // The one number the shop cannot work out for itself.
                // «اختلاف با کارتخوان» above compares the quota against
                // the shop's *own* record of card sales; this compares
                // that record against what the reader actually
                // registered, which is the figure next month's flour is
                // worked out from.
                Tables\Actions\Action::make('readerFigures')
                    ->label('ثبت رقم کارتخوان')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->modalHeading('رقمی که خود کارتخوان نشان می‌دهد')
                    ->modalDescription(
                        'تعداد نان هر دوره را از سامانه بخوانید و اینجا بنویسید.'
                        .' خالی گذاشتن یعنی هنوز بررسی نشده — صفر معنای دیگری دارد.'
                    )
                    ->fillForm(fn (FlourAllocation $record) => $record->periods
                        ->mapWithKeys(fn ($p) => [
                            'p'.$p->period_number => $p->system_bread_count,
                        ])->all())
                    ->form(fn (FlourAllocation $record) => $record->periods
                        ->map(fn ($p) => Forms\Components\TextInput::make('p'.$p->period_number)
                            ->label($p->label)
                            ->helperText('ثبت خود ما: '.number_format($p->card_bread_count).' نان')
                            ->numeric()
                            ->minValue(0)
                            ->nullable())
                        ->all())
                    ->action(function (FlourAllocation $record, array $data) {
                        foreach ($record->periods as $period) {
                            $typed = $data['p'.$period->period_number] ?? null;

                            $period->update([
                                'system_bread_count' => $typed === '' || $typed === null
                                    ? null
                                    : (int) $typed,
                            ]);
                        }
                    })
                    ->successNotificationTitle('رقم کارتخوان ثبت شد'),

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
