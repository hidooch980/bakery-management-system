<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffAdvanceRequestResource\Pages;
use App\Models\BankAccount;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff asking for pay early, and the answer.
 *
 * Read-only as a form: a request is the employee's own words, and an
 * answer is given through the approve and reject actions rather than by
 * editing what they asked for.
 */
class StaffAdvanceRequestResource extends Resource
{
    protected static ?string $model = StaffAdvanceRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'درخواست علی‌الحساب';

    protected static ?string $modelLabel = 'درخواست علی‌الحساب';

    protected static ?string $pluralModelLabel = 'درخواست‌های علی‌الحساب';

    protected static ?int $navigationSort = 4;

    /** Someone is waiting on an answer, so the menu says so. */
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
        // Staff ask from their phone; the panel answers. An advance handed
        // over directly is recorded as an advance, not as a request.
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

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->weight('bold')
                    ->sortable(),

                // What they already owe, beside what they are asking for:
                // the two figures only mean anything together.
                Tables\Columns\TextColumn::make('user_id')
                    ->label('بدهی فعلی')
                    ->state(fn (StaffAdvanceRequest $r) => Money::format(
                        StaffAdvance::outstandingFor($r->user_id)
                    ))
                    ->color('danger'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('علت')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(70),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => StaffAdvanceRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        StaffAdvanceRequest::APPROVED => 'success',
                        StaffAdvanceRequest::REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (StaffAdvanceRequest $r) => $r->decidedBy?->name),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(StaffAdvanceRequest::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('تأیید و پرداخت')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (StaffAdvanceRequest $r) => $r->is_pending)
                    ->modalHeading('تأیید درخواست علی‌الحساب')
                    ->modalDescription(fn (StaffAdvanceRequest $r) => $r->user?->name
                        .' — '.Money::format((float) $r->amount)
                        .'. با تأیید، این مبلغ به‌عنوان علی‌الحساب ثبت می‌شود و از حقوق بعدی کسر خواهد شد.')
                    ->form([
                        Forms\Components\Select::make('bank_account_id')
                            ->label('از کدام حساب پرداخت شد؟')
                            ->options(fn () => BankAccount::orderBy('name')->pluck('name', 'id'))
                            ->native(false)
                            ->helperText('خالی بگذارید اگر نقدی پرداخت شده.'),

                        Forms\Components\Textarea::make('note')
                            ->label('توضیح')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (StaffAdvanceRequest $record, array $data) {
                        $record->approve(
                            auth()->user(),
                            $data['bank_account_id'] ?? null,
                            $data['note'] ?? null,
                        );

                        Notification::make()
                            ->title('علی‌الحساب ثبت شد')
                            ->body('از حقوق بعدی '.$record->user?->name.' کسر می‌شود.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (StaffAdvanceRequest $r) => $r->is_pending)
                    ->form([
                        // Required, because a bare "no" to someone asking
                        // for money early is worse than no answer at all.
                        Forms\Components\Textarea::make('note')
                            ->label('علت رد')
                            ->rows(2)
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (StaffAdvanceRequest $record, array $data) {
                        $record->reject(auth()->user(), $data['note']);

                        Notification::make()
                            ->title('درخواست رد شد')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('درخواستی ثبت نشده است')
            ->emptyStateIcon('heroicon-o-hand-raised')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'decidedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffAdvanceRequests::route('/'),
        ];
    }
}
