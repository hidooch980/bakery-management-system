<?php

namespace App\Filament\Widgets;

use App\Http\Controllers\Api\CustomerDebtController;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * What each school or office owes, as one figure per buyer.
 *
 * The sale-by-sale list next to it is the record; this is the collection
 * list. Chasing a debt is one conversation about one number, so the lines
 * are summed per customer and the longest-waiting comes first.
 */
class CustomerDebtsTable extends BaseWidget
{
    protected static ?string $heading = 'بدهی معوقه مدارس و ادارات';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Customer::query()
                    ->buyers()
                    ->whereHas('sales', fn ($q) => $q->outstanding())
                    ->withSum(['sales as debt_amount' => fn ($q) => $q->outstanding()], 'amount')
                    ->withSum(['sales as debt_bread' => fn ($q) => $q->outstanding()], 'bread_count')
                    ->withCount(['sales as debt_sales' => fn ($q) => $q->outstanding()])
                    ->withMin(['sales as debt_since' => fn ($q) => $q->outstanding()], 'created_at')
                    ->orderBy('debt_since')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('مشتری')
                    ->weight('bold')
                    ->icon('heroicon-m-building-library')
                    ->searchable()
                    ->description(fn (Customer $record) => $record->type_label),

                Tables\Columns\TextColumn::make('debt_amount')
                    ->label('مانده بدهی')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->weight('bold')
                    ->color('danger')
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('جمع کل')
                            ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ),

                Tables\Columns\TextColumn::make('debt_bread')
                    ->label('تعداد نان')
                    ->numeric()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('debt_sales')
                    ->label('تعداد فاکتور')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('debt_since')
                    ->label('قدیمی‌ترین بدهی')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->badge()
                    ->color(fn ($state) => self::daysWaiting($state) >= CustomerDebtController::OVERDUE_DAYS
                        ? 'danger'
                        : 'warning')
                    ->description(fn ($state) => self::daysWaiting($state).' روز'
                        .(self::daysWaiting($state) >= CustomerDebtController::OVERDUE_DAYS
                            ? ' — معوق'
                            : '')),
            ])
            ->actions([
                Tables\Actions\Action::make('settleAll')
                    ->label('تسویه کامل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تسویه بدهی مشتری')
                    ->modalDescription(fn (Customer $record) => 'کل بدهی '.$record->name
                        .' به مبلغ '.Money::format((float) $record->debt_amount)
                        .' دریافت شده است؟')
                    ->action(function (Customer $record) {
                        // Partial payment is settled sale by sale in the
                        // sales list, where the individual lines show.
                        $settled = Sale::query()
                            ->outstanding()
                            ->where('customer_id', $record->id)
                            ->update(['settled_on' => now()]);

                        Notification::make()
                            ->title('بدهی '.$record->name.' تسویه شد')
                            ->body($settled.' فاکتور تسویه شد.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('بدهی معوقه‌ای ثبت نشده است')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([10, 25, 50])
            ->striped();
    }

    private static function daysWaiting($since): int
    {
        return $since === null
            ? 0
            : (int) \Illuminate\Support\Carbon::parse($since)
                ->startOfDay()
                ->diffInDays(now()->startOfDay());
    }
}
