<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\SalaryPaymentResource\Pages;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SalaryPaymentResource extends Resource
{
    protected static ?string $model = SalaryPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'حقوق کارکنان';

    protected static ?string $modelLabel = 'حقوق';

    protected static ?string $pluralModelLabel = 'حقوق کارکنان';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('دوره و کارمند')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('کارمند')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        // Pre-fill the base pay from the employee's configured salary.
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $salary = User::find($state)?->monthly_salary;
                            if ($salary) {
                                $set('base_amount', Money::convert($salary));
                            }
                        }),

                    JalaliDateInput::make('period_start', 'شروع دوره')
                        ->required()
                        ->default(fn () => Jalali::currentMonthRange()[0]->toDateString())
                        ->live(onBlur: true)
                        ->helperText(fn ($state) => $state && Jalali::parse($state)
                            ? 'دوره: '.Jalali::monthLabel(Jalali::parse($state))
                            : 'اول ماه شمسی — مثال: 1405/05/01'),
                ]),

            Forms\Components\Section::make('مبالغ')
                ->icon('heroicon-o-calculator')
                ->columns(3)
                ->schema([
                    MoneyInput::make('base_amount', 'حقوق پایه')
                        ->required()
                        ->default(0)
                        ->live(onBlur: true),

                    MoneyInput::make('bonus', 'پاداش / اضافه‌کاری')
                        ->default(0)
                        ->live(onBlur: true),

                    MoneyInput::make('deduction', 'کسورات')
                        ->default(0)
                        ->live(onBlur: true),

                    Forms\Components\Placeholder::make('net_preview')
                        ->label('خالص پرداختی')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get, ?SalaryPayment $record) {
                            // Form state is in the display unit, so build the
                            // preview there rather than double-converting.
                            $gross = (float) $get('base_amount')
                                + (float) $get('bonus')
                                - (float) $get('deduction');

                            // The advance too. The payslip has always taken
                            // it off on save; this preview did not, so the
                            // figure agreed to and the figure stored were
                            // different numbers and only one of them was
                            // ever shown before pressing the button.
                            $userId = (int) $get('user_id');
                            $outstanding = $userId === 0
                                ? 0.0
                                : Money::convert(StaffAdvance::outstandingFor($userId, $record?->id));

                            $advance = max(0.0, min($outstanding, max(0.0, $gross)));
                            $net = $gross - $advance;

                            $say = fn (float $v) => number_format($v, 0, '.', Money::GROUP_SEPARATOR)
                                .' '.Money::label();

                            if ($advance <= 0) {
                                return $say($net);
                            }

                            $line = $say($net).'  —  پس از کسر '.$say($advance).' علی‌الحساب';

                            // An advance larger than the month's pay is not a
                            // negative payslip; what is left of it stands and
                            // comes off the month after.
                            return $outstanding > $advance
                                ? $line.'، '.$say($outstanding - $advance).' به ماه بعد می‌ماند'
                                : $line;
                        }),
                ]),

            Forms\Components\Section::make('پرداخت')
                ->columns(2)
                ->schema([
                    JalaliDateInput::make('paid_on', 'تاریخ پرداخت')
                        ->helperText('خالی بگذارید تا در وضعیت «پرداخت‌نشده» بماند.'),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیحات')
                        ->rows(2),
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

                Tables\Columns\TextColumn::make('period_label')
                    ->label('دوره')
                    ->badge()
                    ->color('info')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('period_start', $direction)),

                Tables\Columns\TextColumn::make('base_amount')
                    ->label('پایه')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('bonus')
                    ->label('پاداش')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('deduction')
                    ->label('کسورات')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                // Not hidden by default, unlike the other parts. This is the
                // one deduction the shop actually uses, and a payslip that
                // silently came out smaller than the wage is what sent the
                // owner looking for it.
                Tables\Columns\TextColumn::make('advance_deduction')
                    ->label('کسر علی‌الحساب')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? Money::format($state) : '—')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('خالص')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->weight('bold')
                    ->color('success')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                    ),

                Tables\Columns\TextColumn::make('paid_on')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state
                        ? 'پرداخت شد: '.Jalali::date($state)
                        : 'پرداخت‌نشده')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('کارمند')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('unpaid')
                    ->label('فقط پرداخت‌نشده‌ها')
                    ->query(fn ($query) => $query->whereNull('paid_on'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('markPaid')
                    ->label('ثبت پرداخت')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SalaryPayment $record) => ! $record->is_paid)
                    ->action(fn (SalaryPayment $record) => $record->update(['paid_on' => now()])),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->defaultSort('period_start', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalaryPayments::route('/'),
            'create' => Pages\CreateSalaryPayment::route('/create'),
            'edit' => Pages\EditSalaryPayment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $unpaid = static::getModel()::whereNull('paid_on')->count();

        return $unpaid > 0 ? (string) $unpaid : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
