<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalaryPaymentRequestResource\Pages;
use App\Models\SalaryPaymentRequest;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff asking to be paid for the month, on a screen.
 *
 * The feature shipped with an API and a phone screen and nothing in the
 * panel, so a request made from a handset could be seen only from a
 * handset — and the person who writes the wages does it at a desk. This
 * shop has already learned once that built is not the same as reachable:
 * the balance sheet went weeks without a page while the 1,543,344,000
 * Rial it would have shown sat unfound.
 *
 * There is no approve action here, and that is the point rather than an
 * omission. Paying somebody for the month is what a yes means, so the
 * only way forward from this page is the pay sheet, where the figures are
 * on screen before the button is pressed.
 */
class SalaryPaymentRequestResource extends Resource
{
    protected static ?string $model = SalaryPaymentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'درخواست حقوق';

    protected static ?string $modelLabel = 'درخواست حقوق';

    protected static ?string $pluralModelLabel = 'درخواست‌های حقوق';

    protected static ?int $navigationSort = 5;

    /** Someone is waiting on their wages, so the menu says so. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->pending()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        // Staff ask from their phone. A wage handed over at the desk is a
        // payslip, not a request somebody then answers on their behalf.
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ درخواست')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('کارمند')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('period_label')
                    ->label('دورهٔ حقوق')
                    ->sortable(),

                // How long they have been waiting, beside the asking. A
                // request made this morning and one a fortnight old are
                // not the same piece of work, and the list sorts newest
                // first so the old one sinks out of sight.
                Tables\Columns\TextColumn::make('days_waiting')
                    ->label('در انتظار')
                    ->state(fn (SalaryPaymentRequest $r) => $r->is_pending
                        ? $r->days_waiting.' روز'
                        : '—')
                    ->color(fn (SalaryPaymentRequest $r) => $r->is_pending && $r->days_waiting >= 3
                        ? 'danger'
                        : null),

                // What it would come to if paid today, so whoever reads
                // the row knows the size of what is being asked for.
                Tables\Columns\TextColumn::make('estimated_net')
                    ->label('برآورد خالص')
                    ->state(fn (SalaryPaymentRequest $r) => ($net = $r->estimatedNet()) === null
                        ? 'حقوق ثبت نشده'
                        : Money::format($net))
                    // Asked of the wage rather than of `estimatedNet()`,
                    // which counts the advances and would run the whole
                    // sum a second time for every row just to pick a colour.
                    ->color(fn (SalaryPaymentRequest $r) => $r->user?->monthly_salary === null ? 'danger' : null)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح کارمند')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(70),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalaryPaymentRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        SalaryPaymentRequest::PAID => 'success',
                        SalaryPaymentRequest::REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (SalaryPaymentRequest $r) => $r->decision_note ?: $r->decidedBy?->name),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(SalaryPaymentRequest::STATUS_LABELS),
            ])
            ->actions([
                // Not an approve button: it opens the pay sheet with the
                // person and the month already filled in. The payslip is
                // what marks the request answered, so a wage can never be
                // agreed to from a screen that did not show the figures.
                Tables\Actions\Action::make('pay')
                    ->label('ثبت فیش حقوقی')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->visible(fn (SalaryPaymentRequest $r) => $r->is_pending)
                    ->url(fn (SalaryPaymentRequest $r) => SalaryPaymentResource::getUrl('create', [
                        'user_id' => $r->user_id,
                        'period_start' => $r->period_start?->toDateString(),
                    ])),

                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (SalaryPaymentRequest $r) => $r->is_pending)
                    ->form([
                        // Required. Someone asking for wages they have
                        // already earned deserves better than a silent no.
                        Forms\Components\Textarea::make('decision_note')
                            ->label('علت رد')
                            ->rows(2)
                            ->required()
                            ->minLength(3)
                            ->maxLength(300),
                    ])
                    ->action(function (SalaryPaymentRequest $record, array $data) {
                        $record->reject(auth()->user(), $data['decision_note']);

                        Notification::make()
                            ->title('درخواست رد شد')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('درخواستی ثبت نشده است')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'decidedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalaryPaymentRequests::route('/'),
        ];
    }
}
