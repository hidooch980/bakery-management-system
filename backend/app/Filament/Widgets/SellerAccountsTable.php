<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Each seller's temporary account — everything they are answerable for
 * until it is cleared: cash in hand, a money gap, bread nobody paid for,
 * and credit they handed out.
 *
 * The settle action covers only the first three. Credit is the customer's
 * debt to pay, so it clears from the customer's side rather than by the
 * seller handing over money they never took.
 */
class SellerAccountsTable extends BaseWidget
{
    protected static ?string $heading = 'حساب موقت فروشندگان';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()->whereHas('sales', fn ($q) => $q->sellerAccountOutstanding())
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('فروشنده')
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('cash')
                    ->label('پول نقد')
                    ->state(fn (User $record) => Money::format(
                        self::sumFor($record, fn (Sale $s) => $s->cash_held)
                    ))
                    ->color('warning'),

                Tables\Columns\TextColumn::make('difference')
                    ->label('اختلاف مالی')
                    ->state(function (User $record) {
                        $gap = self::sumFor($record, fn (Sale $s) => $s->open_difference);

                        return ($gap > 0 ? '+' : '').Money::format($gap);
                    })
                    ->color(fn (User $record) => self::sumFor(
                        $record,
                        fn (Sale $s) => $s->open_difference
                    ) == 0 ? 'gray' : 'danger'),

                Tables\Columns\TextColumn::make('shortfall')
                    ->label('کسری نان')
                    ->state(function (User $record) {
                        $sales = self::outstandingFor($record)
                            ->filter(fn (Sale $s) => $s->open_shortfall > 0);

                        if ($sales->isEmpty()) {
                            return '—';
                        }

                        return number_format((int) $sales->sum('shortfall_count')).' نان'
                            .'   —   '.Money::format($sales->sum(fn (Sale $s) => $s->open_shortfall));
                    })
                    ->color(fn (User $record) => self::sumFor(
                        $record,
                        fn (Sale $s) => $s->open_shortfall
                    ) > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('credit')
                    ->label('نسیه وصول‌نشده')
                    ->state(function (User $record) {
                        $sales = self::outstandingFor($record)
                            ->filter(fn (Sale $s) => $s->open_credit > 0);

                        if ($sales->isEmpty()) {
                            return '—';
                        }

                        return Money::format($sales->sum(fn (Sale $s) => $s->open_credit))
                            .'   ('.$sales->count().' فقره)';
                    })
                    ->description(fn (User $record) => self::sumFor(
                        $record,
                        fn (Sale $s) => $s->open_credit
                    ) > 0 ? 'با پرداخت مشتری تسویه می‌شود' : null)
                    ->color(fn (User $record) => self::sumFor(
                        $record,
                        fn (Sale $s) => $s->open_credit
                    ) > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('total')
                    ->label('جمع بدهی موقت')
                    ->state(fn (User $record) => Money::format(self::totalFor($record)))
                    ->weight('bold')
                    ->badge()
                    ->color('danger'),
            ])
            ->actions([
                Tables\Actions\Action::make('settleSellerAccount')
                    ->label('تسویه حساب')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تسویه حساب فروشنده')
                    ->modalDescription(fn (User $record) => 'مبلغ '
                        .Money::format(self::settleableFor($record))
                        .' شامل پول نقد، اختلاف مالی و کسری نان از '.$record->name.' دریافت شد؟'
                        .(self::sumFor($record, fn (Sale $s) => $s->open_credit) > 0
                            ? ' نسیه وصول‌نشده در حساب می‌ماند تا مشتری پرداخت کند.'
                            : ''))
                    ->modalSubmitActionLabel('تسویه شد')
                    ->action(function (User $record) {
                        $amount = self::settleableFor($record);

                        \App\Support\SellerSettlement::settle($record);

                        Notification::make()
                            ->title('حساب '.$record->name.' تسویه شد')
                            ->body('مبلغ '.Money::format($amount).' تسویه شد.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('حساب تسویه‌نشده‌ای وجود ندارد')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }

    /** @return \Illuminate\Support\Collection<int, Sale> */
    private static function outstandingFor(User $seller): \Illuminate\Support\Collection
    {
        return Sale::query()
            ->where('user_id', $seller->id)
            ->sellerAccountOutstanding()
            ->get();
    }

    private static function sumFor(User $seller, callable $value): float
    {
        return round(self::outstandingFor($seller)->sum($value), 2);
    }

    private static function totalFor(User $seller): float
    {
        return self::sumFor($seller, fn (Sale $s) => $s->seller_account_amount);
    }

    /** The part of the account the seller can hand over today. */
    private static function settleableFor(User $seller): float
    {
        return round(
            self::totalFor($seller) - self::sumFor($seller, fn (Sale $s) => $s->open_credit),
            2
        );
    }
}
