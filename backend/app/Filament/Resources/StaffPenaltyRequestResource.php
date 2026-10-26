<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffPenaltyRequestResource\Pages;
use App\Models\StaffPenaltyRequest;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penalties the seller asked for, and the manager's answer. Only an
 * approved one becomes a penalty StaffAdjustment the payslip counts.
 */
class StaffPenaltyRequestResource extends Resource
{
    protected static ?string $model = StaffPenaltyRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'درخواست تنبیه';

    protected static ?string $modelLabel = 'درخواست تنبیه';

    protected static ?string $pluralModelLabel = 'درخواست‌های تنبیه';

    protected static ?int $navigationSort = 5;

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
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('کارمند')
                    ->weight('bold')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label('نوع')
                    ->formatStateUsing(fn ($state) => $state === StaffPenaltyRequest::FINE ? 'جریمه' : 'اخطار'),
                Tables\Columns\TextColumn::make('reason')->label('علت')
                    ->formatStateUsing(fn ($state) => StaffPenaltyRequest::REASONS[$state] ?? $state)
                    ->description(fn (StaffPenaltyRequest $r) => $r->note),
                Tables\Columns\TextColumn::make('amount')->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))->placeholder('—'),
                Tables\Columns\TextColumn::make('requestedBy.name')->label('درخواست‌دهنده'),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()
                    ->state(fn (StaffPenaltyRequest $r) => $r->is_cancelled ? 'cancelled' : $r->status)
                    ->formatStateUsing(fn ($state) => $state === 'cancelled' ? 'لغو شد' : (StaffPenaltyRequest::STATUS_LABELS[$state] ?? $state))
                    ->color(fn ($state) => match ($state) {
                        StaffPenaltyRequest::APPROVED => 'success',
                        StaffPenaltyRequest::REJECTED => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (StaffPenaltyRequest $r) => $r->decidedBy?->name),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')
                    ->options(StaffPenaltyRequest::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('تأیید')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (StaffPenaltyRequest $r) => $r->is_pending)
                    ->modalHeading('تأیید درخواست تنبیه')
                    ->modalDescription(fn (StaffPenaltyRequest $r) => $r->user?->name.' — '.$r->type_label.' — '.$r->reason_label
                        .($r->lateThatDay() !== null ? '. توجه: همین روز '.$r->lateThatDay().' دقیقه تأخیر ثبت شده و جریمهٔ خودکار تأخیر جداگانه کسر می‌شود.' : ''))
                    ->form(fn (StaffPenaltyRequest $r) => array_values(array_filter([
                        $r->type === StaffPenaltyRequest::FINE
                            ? Forms\Components\TextInput::make('amount')->label('مبلغ نهایی')
                                ->numeric()->minValue(1)->required()
                                ->default(Money::convert((float) $r->amount))
                            : null,
                        Forms\Components\Textarea::make('note')->label('توضیح')->rows(2)->maxLength(500),
                    ])))
                    ->action(function (StaffPenaltyRequest $record, array $data) {
                        try {
                            $record->approve(
                                auth()->user(),
                                isset($data['amount']) ? Money::toToman($data['amount']) : null,
                                $data['note'] ?? null,
                            );
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('تنبیه تأیید شد')
                            ->body('در کسورات '.$record->user?->name.' ثبت شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رد')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (StaffPenaltyRequest $r) => $r->is_pending)
                    ->form([
                        Forms\Components\Textarea::make('note')->label('دلیل رد')->rows(2)->required()->maxLength(500),
                    ])
                    ->action(function (StaffPenaltyRequest $record, array $data) {
                        try {
                            $record->reject(auth()->user(), $data['note']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('درخواست رد شد')->success()->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('لغو تنبیه')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (StaffPenaltyRequest $r) => $r->status === StaffPenaltyRequest::APPROVED && ! $r->is_cancelled)
                    ->requiresConfirmation()
                    ->modalDescription('تنبیه حذف نمی‌شود؛ بخشیده می‌شود و دیگر در فیش حقوق کسر نمی‌شود.')
                    ->action(function (StaffPenaltyRequest $record) {
                        try {
                            $record->cancel(auth()->user());
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('تنبیه لغو شد')->success()->send();
                    }),
            ])
            ->emptyStateHeading('درخواست تنبیهی ثبت نشده است')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'requestedBy', 'decidedBy', 'adjustment']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffPenaltyRequests::route('/'),
        ];
    }
}
