<?php

namespace App\Filament\Widgets;

use App\Models\SettlementRequest;
use App\Support\Money;
use App\Support\SellerSettlement;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Sellers saying they have handed their account over, waiting on an answer.
 *
 * The seller starts it because they know when the money changed hands; the
 * admin closes it because a debtor clearing their own debt would undo the
 * point of recording it.
 */
class SettlementRequestsTable extends BaseWidget
{
    protected static ?string $heading = 'درخواست‌های تسویه فروشندگان';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(SettlementRequest::query()->pending()->with('user')->oldest())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('زمان درخواست')
                    ->formatStateUsing(fn ($state) => \App\Support\AppCalendar::dateTime($state))
                    ->description(fn (SettlementRequest $record) => $record->created_at?->diffForHumans()),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('فروشنده')
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('cash_amount')
                    ->label('پول نقد')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state)),

                Tables\Columns\TextColumn::make('difference_amount')
                    ->label('اختلاف مالی')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '')
                        .Money::format((float) $state))
                    ->color(fn ($state) => (float) $state == 0 ? 'gray' : 'danger'),

                Tables\Columns\TextColumn::make('shortfall_amount')
                    ->label('کسری نان')
                    ->formatStateUsing(fn ($state) => (float) $state > 0
                        ? Money::format((float) $state)
                        : '—')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('جمع اعلامی')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->weight('bold')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('paid_cash')
                    ->label('تحویل نقدی')
                    ->formatStateUsing(fn ($state) => (float) $state > 0
                        ? Money::format((float) $state)
                        : '—')
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('paid_card')
                    ->label('تحویل کارتخوان')
                    ->formatStateUsing(fn ($state) => (float) $state > 0
                        ? Money::format((float) $state)
                        : '—')
                    ->description(fn (SettlementRequest $record) => (float) $record->paid_card > 0
                        ? 'به حساب بانکی واریز می‌شود'
                        : null)
                    ->color(fn ($state) => (float) $state > 0 ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح فروشنده')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('تأیید و تسویه')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تأیید تسویه')
                    ->modalDescription(fn (SettlementRequest $record) => 'مبلغ '
                        .$record->amount_formatted.' از '.$record->user->name
                        .' دریافت شد؟ نسیه وصول‌نشده در حساب او باقی می‌ماند.')
                    ->form(fn (SettlementRequest $record) => (float) $record->paid_card > 0
                        ? [
                            Forms\Components\Select::make('bank_account_id')
                                ->label('واریز کارتخوان به حساب')
                                ->options(\App\Models\BankAccount::pluck('title', 'id'))
                                ->default(\App\Models\BankAccount::where('is_default', true)->value('id'))
                                ->required()
                                ->native(false),
                        ]
                        : [])
                    ->action(function (SettlementRequest $record, array $data) {
                        SellerSettlement::confirm(
                            $record,
                            auth()->user(),
                            isset($data['bank_account_id'])
                                ? \App\Models\BankAccount::find($data['bank_account_id'])
                                : null,
                        );

                        Notification::make()
                            ->title('حساب '.$record->user->name.' تسویه شد')
                            ->body('مبلغ '.$record->amount_formatted.' تأیید شد.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('علت رد')
                            ->helperText('برای فروشنده نمایش داده می‌شود.')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (SettlementRequest $record, array $data) {
                        // Nothing is settled — the account stays exactly as
                        // it was, and the seller is told why.
                        $record->update([
                            'rejected_at' => now(),
                            'rejection_reason' => $data['reason'],
                        ]);

                        Notification::make()
                            ->title('درخواست رد شد')
                            ->warning()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('درخواست تسویه‌ای در انتظار نیست')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }
}
