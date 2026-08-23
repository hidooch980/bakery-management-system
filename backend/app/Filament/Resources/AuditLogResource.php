<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The record of who changed a figure.
 *
 * Read-only in every direction, and the model refuses writes as well —
 * neither belt nor braces is worth much alone here.
 *
 * A trail nobody can open is the same failure as a report nobody can open,
 * which this panel has already shipped twice. It is on the menu.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'تاریخچهٔ تغییرات';

    protected static ?string $modelLabel = 'تغییر';

    protected static ?string $pluralModelLabel = 'تاریخچهٔ تغییرات';

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** What the log calls each kind of record, in the shop's own words. */
    public static function kinds(): array
    {
        return [
            SalaryPayment::class => 'فیش حقوقی',
            StaffAdvance::class => 'علی‌الحساب',
            StaffAdjustment::class => 'پاداش و جریمه',
            Expense::class => 'هزینه',
            Income::class => 'درآمد',
            Sale::class => 'فروش',
            FlourSale::class => 'فروش آرد',
            Loan::class => 'وام',
            LoanPayment::class => 'قسط وام',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('کِی')
                    ->formatStateUsing(fn ($state) => AppCalendar::dateTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('actor')
                    ->label('چه کسی')
                    ->weight('bold')
                    ->searchable(query: fn (Builder $q, string $search) => $q->where('user_name', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('event')
                    ->label('چه شد')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AuditLog::EVENT_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        AuditLog::CREATED => 'success',
                        AuditLog::DELETED => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('روی چه چیزی')
                    ->formatStateUsing(fn ($state) => static::kinds()[$state] ?? class_basename($state))
                    ->description(fn (AuditLog $log) => $log->subject),

                // How many figures moved, not which — the row is a pointer
                // and the detail is one click away. Putting a whole diff in
                // a table cell makes the list unreadable at exactly the
                // moment somebody is scanning it for one bad day.
                Tables\Columns\TextColumn::make('before')
                    ->label('چند مقدار')
                    ->state(fn (AuditLog $log) => count($log->changes()).' مورد'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('نوع رکورد')
                    ->options(static::kinds()),

                Tables\Filters\SelectFilter::make('event')
                    ->label('نوع تغییر')
                    ->options(AuditLog::EVENT_LABELS),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('کاربر')
                    ->relationship('user', 'name')
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('جزئیات'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('هنوز تغییری ثبت نشده است')
            ->emptyStateDescription('هر تغییر روی پول یا کالا از این پس اینجا نوشته می‌شود.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->striped();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('چه کسی و کِی')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('actor')->label('کاربر'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('زمان')
                        ->formatStateUsing(fn ($state) => AppCalendar::dateTime($state)),
                    Infolists\Components\TextEntry::make('ip')->label('نشانی شبکه')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('چه چیزی')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('auditable_type')
                        ->label('نوع رکورد')
                        ->formatStateUsing(fn ($state) => static::kinds()[$state] ?? class_basename($state)),
                    Infolists\Components\TextEntry::make('subject')->label('عنوان')->placeholder('—'),
                ]),

            // The whole point of the table, laid out so the eye lands on
            // the number that moved.
            Infolists\Components\Section::make('چه چیزی عوض شد')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('change_rows')
                        ->label('')
                        ->state(fn (AuditLog $log) => $log->changes())
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('field')->label('مقدار'),
                            Infolists\Components\TextEntry::make('from')
                                ->label('از')
                                ->placeholder('—')
                                ->color('danger')
                                ->formatStateUsing(fn ($state, $record) => static::say($state, $record['field'] ?? null)),
                            Infolists\Components\TextEntry::make('to')
                                ->label('به')
                                ->placeholder('—')
                                ->color('success')
                                ->formatStateUsing(fn ($state, $record) => static::say($state, $record['field'] ?? null)),
                        ]),
                ]),
        ]);
    }

    /**
     * A stored value, written the way this shop writes numbers.
     *
     * The raw column reads back as «1000000.00», which is a decimal point
     * to a database and a thousands separator to anyone raised on these
     * ledgers — exactly the ambiguity the shop's own convention avoids by
     * grouping with the Persian comma: 1،000،000. A trail is read on the day a
     * figure is disputed, and that is the worst possible day for the
     * reader to be unsure where the decimal point is.
     *
     * Identifiers are left alone. «1،234» for a user id would be a
     * separator pretending to be an amount.
     */
    public static function say($value, ?string $field = null): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'بله' : 'خیر';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $isIdentifier = $field !== null && str_ends_with($field, '_id');

        if (is_numeric($value) && ! $isIdentifier) {
            $number = (float) $value;

            // Whole figures lose the .00; a genuine fraction keeps two
            // places rather than being rounded away behind the reader.
            return $number == (int) $number
                ? number_format($number, 0, '.', Money::GROUP_SEPARATOR)
                : number_format($number, 2, '.', Money::GROUP_SEPARATOR);
        }

        return (string) $value;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
