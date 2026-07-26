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
 * Each seller's temporary account: the cash they are still holding plus any
 * gap between the money they recorded and what the bread was worth. It is
 * their debt until they settle it, which is what "تسویه حساب" clears.
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

                Tables\Columns\TextColumn::make('cash_held')
                    ->label('پول نقد نزد فروشنده')
                    ->state(fn (User $record) => Money::format(
                        self::outstandingFor($record)->sum(fn (Sale $s) => $s->cash_held)
                    ))
                    ->color('warning'),

                Tables\Columns\TextColumn::make('difference')
                    ->label('اختلاف مالی')
                    ->state(function (User $record) {
                        $difference = self::outstandingFor($record)
                            ->sum(fn (Sale $s) => (float) $s->amount_difference);

                        return ($difference > 0 ? '+' : '').Money::format($difference);
                    })
                    ->color(fn (User $record) => self::outstandingFor($record)
                        ->sum(fn (Sale $s) => (float) $s->amount_difference) == 0
                            ? 'gray'
                            : 'danger'),

                Tables\Columns\TextColumn::make('total')
                    ->label('جمع بدهی موقت')
                    ->state(fn (User $record) => Money::format(self::totalFor($record)))
                    ->weight('bold')
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('sales_count')
                    ->label('فقره تسویه‌نشده')
                    ->state(fn (User $record) => self::outstandingFor($record)->count()),
            ])
            ->actions([
                Tables\Actions\Action::make('settleSellerAccount')
                    ->label('تسویه حساب')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تسویه حساب فروشنده')
                    ->modalDescription(fn (User $record) => 'مبلغ '
                        .Money::format(self::totalFor($record))
                        .' از '.$record->name.' دریافت شد؟')
                    ->action(function (User $record) {
                        $total = self::totalFor($record);

                        Sale::query()
                            ->where('user_id', $record->id)
                            ->sellerAccountOutstanding()
                            ->update(['cash_settled_on' => now()]);

                        Notification::make()
                            ->title('حساب '.$record->name.' تسویه شد')
                            ->body('مبلغ '.Money::format($total).' تسویه شد.')
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

    private static function totalFor(User $seller): float
    {
        return round(
            self::outstandingFor($seller)->sum(fn (Sale $s) => $s->seller_account_amount),
            2
        );
    }
}
