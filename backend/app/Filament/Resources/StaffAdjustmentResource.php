<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\StaffAdjustmentResource\Pages;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Rewards and penalties, recorded on the day they were earned.
 *
 * The payslip has always had a bonus box and a deduction box, both typed
 * at the moment of payment — which is the end of a long month, when nobody
 * remembers who came in late on the 12th or who covered the extra shift on
 * the 20th. A figure recalled at payday is a figure guessed at, and the
 * person it is taken from has no way to check it.
 *
 * So each one is written down when it happens, with a reason, and the pay
 * sheet opens on the month's total.
 */
class StaffAdjustmentResource extends Resource
{
    protected static ?string $model = StaffAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'تشویقی و تنبیهی';

    protected static ?string $modelLabel = 'تشویقی / تنبیهی';

    protected static ?string $pluralModelLabel = 'تشویقی و تنبیهی';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('کارمند و نوع')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('کارمند')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\ToggleButtons::make('kind')
                        ->label('نوع')
                        ->options([
                            StaffAdjustment::REWARD => 'تشویقی',
                            StaffAdjustment::PENALTY => 'تنبیهی',
                        ])
                        ->colors([
                            StaffAdjustment::REWARD => 'success',
                            StaffAdjustment::PENALTY => 'danger',
                        ])
                        ->icons([
                            StaffAdjustment::REWARD => 'heroicon-o-plus-circle',
                            StaffAdjustment::PENALTY => 'heroicon-o-minus-circle',
                        ])
                        ->default(StaffAdjustment::REWARD)
                        ->inline()
                        ->required(),
                ]),

            Forms\Components\Section::make('مبنا')
                ->description('روز، از حقوق ماهانهٔ همان کارمند قیمت می‌خورد — پس یک روز برای هر کس مبلغ خودش را دارد.')
                ->columns(2)
                ->schema([
                    Forms\Components\ToggleButtons::make('basis')
                        ->label('بر چه مبنایی')
                        ->options([
                            StaffAdjustment::BY_AMOUNT => 'مبلغ',
                            StaffAdjustment::BY_DAYS => 'روز',
                            StaffAdjustment::BY_NOTE => 'فقط ثبت',
                        ])
                        ->default(StaffAdjustment::BY_AMOUNT)
                        ->inline()
                        ->live()
                        ->required()
                        ->columnSpanFull(),

                    MoneyInput::make('amount', 'مبلغ')
                        ->visible(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_AMOUNT)
                        ->required(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_AMOUNT),

                    Forms\Components\TextInput::make('days')
                        ->label('تعداد روز')
                        ->numeric()
                        ->step(0.25)
                        ->minValue(0.25)
                        ->maxValue(31)
                        ->suffix('روز')
                        ->helperText('نیم روز را ۰٫۵ بزنید.')
                        ->visible(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_DAYS)
                        ->required(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_DAYS)
                        ->live(onBlur: true),

                    Forms\Components\Placeholder::make('days_worth')
                        ->label('معادل مبلغ')
                        ->visible(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_DAYS)
                        ->content(function (Forms\Get $get) {
                            $person = User::find($get('user_id'));
                            $monthly = (float) ($person?->monthly_salary ?? 0);

                            if ($monthly <= 0) {
                                return 'حقوق ماهانهٔ این کارمند ثبت نشده — روز به مبلغ تبدیل نمی‌شود.';
                            }

                            $worth = $monthly / StaffAdjustment::DAYS_IN_MONTH * (float) $get('days');

                            return Money::format($worth);
                        }),

                    Forms\Components\Placeholder::make('note_only_hint')
                        ->label('')
                        ->visible(fn (Forms\Get $get) => $get('basis') === StaffAdjustment::BY_NOTE)
                        ->columnSpanFull()
                        ->content('روی حقوق اثری ندارد؛ فقط در سابقهٔ کارمند می‌ماند.'),
                ]),

            Forms\Components\Section::make('چرا و کِی')
                ->columns(2)
                ->schema([
                    JalaliDateInput::make('occurred_on', 'تاریخ')
                        ->required()
                        ->default(fn () => now()->toDateString()),

                    Forms\Components\TextInput::make('reason')
                        ->label('دلیل')
                        ->required()
                        ->minLength(3)
                        ->maxLength(300)
                        // Not optional on purpose: a deduction nobody can
                        // explain a month later is one the person it was
                        // taken from will dispute, and they will be right.
                        ->helperText('چند کلمه کافی است — ولی بدون دلیل ثبت نمی‌شود.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('کارمند')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('kind')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === StaffAdjustment::REWARD ? 'تشویقی' : 'تنبیهی')
                    ->color(fn ($state) => $state === StaffAdjustment::REWARD ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('value')
                    ->label('مبلغ')
                    ->state(fn (StaffAdjustment $r) => $r->is_note_only ? '—' : Money::format($r->value))
                    ->description(fn (StaffAdjustment $r) => $r->basis_label ?: null)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('دلیل')
                    ->wrap()
                    ->limit(60),

                Tables\Columns\TextColumn::make('occurred_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->sortable(),

                Tables\Columns\IconColumn::make('salary_payment_id')
                    ->label('در فیش')
                    ->boolean()
                    ->getStateUsing(fn (StaffAdjustment $r) => $r->salary_payment_id !== null)
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('کارمند')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('kind')
                    ->label('نوع')
                    ->options([
                        StaffAdjustment::REWARD => 'تشویقی',
                        StaffAdjustment::PENALTY => 'تنبیهی',
                    ]),

                Tables\Filters\Filter::make('unsettled')
                    ->label('هنوز در فیش نیامده')
                    ->query(fn ($query) => $query->whereNull('salary_payment_id'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // Once a payslip has answered for it, changing it would
                    // leave the slip docked for something that no longer
                    // says what it said.
                    ->visible(fn (StaffAdjustment $r) => $r->salary_payment_id === null),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (StaffAdjustment $r) => $r->salary_payment_id === null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffAdjustments::route('/'),
            'create' => Pages\CreateStaffAdjustment::route('/create'),
            'edit' => Pages\EditStaffAdjustment::route('/{record}/edit'),
        ];
    }
}
