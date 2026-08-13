<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Resources\DieselAllocationResource\Pages;
use App\Models\DieselAllocation;
use App\Support\AppCalendar;
use App\Support\Jalali;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * The month's diesel quota, and how much of it is left.
 *
 * Fuel was an expense category and nothing more, so nobody could say how
 * many litres the shop still had a right to. An oven running dry mid-bake
 * is not a bookkeeping problem.
 */
class DieselAllocationResource extends Resource
{
    protected static ?string $model = DieselAllocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'سهمیه گازوئیل';

    protected static ?string $modelLabel = 'سهمیه گازوئیل';

    protected static ?string $pluralModelLabel = 'سهمیه گازوئیل';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('سهمیه ماه')
                ->icon('heroicon-o-fire')
                ->columns(2)
                ->schema([
                    JalaliDateInput::make('month_start', 'شروع ماه')
                        ->required()
                        ->default(fn () => Jalali::currentMonthRange()[0]->toDateString())
                        ->helperText('اول ماه شمسی — مثال: 1405/05/01'),

                    Forms\Components\TextInput::make('total_litres')
                        ->label('سهمیه (لیتر)')
                        ->numeric()
                        ->minValue(0)
                        // Derived from the flour quota, but left editable:
                        // the depot occasionally issues something other than
                        // the formula, and the docket is the truth.
                        ->helperText(function (Forms\Get $get) {
                            $month = $get('month_start');

                            if (blank($month)) {
                                return 'ابتدا ماه را انتخاب کنید.';
                            }

                            $derived = DieselAllocation::litresFor(
                                Carbon::parse($month)
                            );

                            return $derived === null
                                ? 'برای این ماه سهمیه آردی ثبت نشده، پس مقدار را دستی وارد کنید.'
                                : 'بر اساس سهمیه آرد این ماه: '
                                    .number_format($derived, 0).' لیتر';
                        }),

                    Forms\Components\TextInput::make('carryover_litres')
                        ->label('انتقالی از ماه قبل (لیتر)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('اگر سهمیه استفاده‌نشده ماه قبل منتقل شده.'),

                    Forms\Components\TextInput::make('note')
                        ->label('توضیح')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('month_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('month_start')
                    ->label('ماه')
                    ->formatStateUsing(fn ($state) => AppCalendar::monthLabel($state))
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('available_litres')
                    ->label('قابل برداشت')
                    ->state(fn (DieselAllocation $r) => number_format($r->available_litres, 0).' لیتر'),

                Tables\Columns\TextColumn::make('delivered_litres')
                    ->label('تحویل گرفته')
                    ->state(fn (DieselAllocation $r) => number_format($r->delivered_litres, 0).' لیتر'),

                Tables\Columns\TextColumn::make('remaining_litres')
                    ->label('مانده')
                    ->state(fn (DieselAllocation $r) => number_format($r->remaining_litres, 0).' لیتر')
                    ->badge()
                    // Overdrawn is worth seeing at a glance; so is nearly out.
                    ->color(fn (DieselAllocation $r) => match (true) {
                        $r->is_overdrawn => 'danger',
                        $r->used_percent >= 80 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('used_percent')
                    ->label('مصرف‌شده')
                    ->state(fn (DieselAllocation $r) => $r->used_percent.'٪'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDieselAllocations::route('/'),
            'create' => Pages\CreateDieselAllocation::route('/create'),
            'edit' => Pages\EditDieselAllocation::route('/{record}/edit'),
        ];
    }
}
