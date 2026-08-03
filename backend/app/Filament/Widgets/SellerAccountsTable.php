<?php

namespace App\Filament\Widgets;

use App\Models\BankAccount;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SellerSettlement;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

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
        // Cleared per render: stale rows here would show an account that
        // has already been settled.
        self::$cache = [];

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
                    ->modalHeading('تسویه حساب فروشنده')
                    ->modalDescription(fn (User $record) => 'مبلغ '
                        .Money::format(self::settleableFor($record))
                        .' شامل پول نقد، اختلاف مالی و کسری نان از '.$record->name.' دریافت شد؟'
                        .(self::sumFor($record, fn (Sale $s) => $s->open_credit) > 0
                            ? ' نسیه وصول‌نشده در حساب می‌ماند تا مشتری پرداخت کند.'
                            : ''))
                    ->modalSubmitActionLabel('تسویه شد')
                    // A handover arrives partly in notes and partly through the
                    // reader, and the two do not land in the same place: cash
                    // stays in the till, the card share reaches a bank account.
                    // Settling without asking left that money unbanked.
                    ->form(fn (User $record) => [
                        Forms\Components\TextInput::make('paid_cash')
                            ->label('تحویل نقدی')
                            ->numeric()
                            ->minValue(0)
                            // Typed in the display unit, so the default is
                            // converted out of stored Toman the same way.
                            ->default(Money::convert(self::settleableFor($record)))
                            ->suffix(Money::label())
                            ->live(onBlur: true)
                            ->required(),

                        Forms\Components\TextInput::make('paid_card')
                            ->label('کارتخوان')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix(Money::label())
                            ->live(onBlur: true)
                            ->required(),

                        Forms\Components\Select::make('bank_account_id')
                            ->label('واریز کارتخوان به حساب')
                            ->options(BankAccount::pluck('title', 'id'))
                            ->default(BankAccount::where('is_default', true)->value('id'))
                            ->native(false)
                            // Only a card share needs an account to land in.
                            ->visible(fn (Forms\Get $get) => (float) $get('paid_card') > 0)
                            ->required(fn (Forms\Get $get) => (float) $get('paid_card') > 0),
                    ])
                    ->action(function (User $record, array $data) {
                        $settleable = self::settleableFor($record);
                        $cash = Money::toToman((float) $data['paid_cash']);
                        $card = Money::toToman((float) $data['paid_card']);

                        // The parts have to come to the whole. Letting them
                        // differ would mark the account clear on a figure
                        // nobody actually handed over.
                        if (abs($cash + $card - $settleable) > 0.01) {
                            Notification::make()
                                ->title('جمع نقد و کارتخوان با مبلغ حساب نمی‌خواند')
                                ->body('باید '.Money::format($settleable).' باشد،'
                                    .' ولی '.Money::format($cash + $card).' وارد شده.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $banked = SellerSettlement::settleWithMethod(
                            $record,
                            auth()->user(),
                            $card,
                            isset($data['bank_account_id'])
                                ? BankAccount::find($data['bank_account_id'])
                                : null,
                        );

                        // The account just changed, so the cached rows are stale.
                        unset(self::$cache[$record->id]);

                        Notification::make()
                            ->title('حساب '.$record->name.' تسویه شد')
                            ->body('نقد '.Money::format($cash).'   •   کارتخوان '.Money::format($card)
                                .($banked ? ' به حساب '.$banked->title : ''))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('حساب تسویه‌نشده‌ای وجود ندارد')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }

    /**
     * Held for the length of the request. Every column asks the same
     * question of the same seller, so without this a five-seller table
     * ran the query dozens of times to render one screen.
     *
     * @var array<int, Collection<int, Sale>>
     */
    private static array $cache = [];

    /** @return Collection<int, Sale> */
    private static function outstandingFor(User $seller): Collection
    {
        return self::$cache[$seller->id] ??= Sale::query()
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
