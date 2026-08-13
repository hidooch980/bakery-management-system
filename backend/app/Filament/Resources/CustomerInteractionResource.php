<?php

namespace App\Filament\Resources;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Resources\CustomerInteractionResource\Pages;
use App\Models\CustomerInteraction;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every dealing with a school or office that was not a sale, across all
 * of them at once.
 *
 * The same records already show on a customer's own page, one customer at
 * a time, which answers "what did we say to this school". It cannot
 * answer "who have we not called back", because that question spans every
 * customer and the answer was reachable only by opening each in turn.
 */
class CustomerInteractionResource extends Resource
{
    protected static ?string $model = CustomerInteraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'تولید و فروش';

    protected static ?string $navigationLabel = 'تماس‌ها و پیگیری';

    protected static ?string $modelLabel = 'تماس';

    protected static ?string $pluralModelLabel = 'تماس‌ها و پیگیری';

    protected static ?int $navigationSort = 6;

    /** The calls owed today, so the menu itself asks for them. */
    public static function getNavigationBadge(): ?string
    {
        $due = static::getModel()::query()->due()->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('گفت‌وگو')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('مشتری')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('type')
                        ->label('نوع')
                        ->options(CustomerInteraction::TYPES)
                        ->default('call')
                        ->required()
                        ->native(false),

                    Forms\Components\Textarea::make('summary')
                        ->label('شرح')
                        ->rows(3)
                        ->required()
                        ->maxLength(1000)
                        ->columnSpanFull()
                        ->placeholder('مثلاً: قول داد تا آخر هفته تسویه کند.'),

                    // Optional on purpose: not every call leaves something
                    // owed, and a date forced onto one that does not makes
                    // a chore nobody meant to create.
                    JalaliDateInput::make('follow_up_on', 'پیگیری بعدی')
                        ->helperText('خالی بگذارید اگر پیگیری لازم نیست.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->weight('bold')
                    ->icon('heroicon-m-building-library')
                    ->searchable()
                    ->sortable()
                    ->description(fn (CustomerInteraction $record) => $record->customer?->phone),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => CustomerInteraction::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'complaint' => 'danger',
                        'debt_chase' => 'warning',
                        'order' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('summary')
                    ->label('شرح')
                    ->wrap()
                    ->limit(90)
                    ->searchable(),

                // Colour carries the state here: a list where every row
                // looks the same is a list nobody reads twice.
                Tables\Columns\TextColumn::make('follow_up_on')
                    ->label('پیگیری')
                    ->placeholder('—')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->color(fn (CustomerInteraction $record) => match (true) {
                        $record->completed_at !== null => 'success',
                        $record->follow_up_on === null => 'gray',
                        $record->is_overdue => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (CustomerInteraction $record) => match (true) {
                        $record->completed_at !== null => 'انجام شد',
                        $record->follow_up_on === null => null,
                        $record->is_overdue => 'عقب‌افتاده',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('due')
                    ->label('فقط پیگیری‌های سررسیدشده')
                    ->query(fn ($query) => $query->due()),

                Tables\Filters\Filter::make('open')
                    ->label('فقط پیگیری‌های باز')
                    ->query(fn ($query) => $query->open()),

                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(CustomerInteraction::TYPES),

                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('مشتری')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('complete')
                    ->label('انجام شد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Only where something is actually owed: a tick beside
                    // a call that promised nothing means nothing.
                    ->visible(fn (CustomerInteraction $record) => $record->follow_up_on !== null
                        && $record->completed_at === null)
                    ->action(function (CustomerInteraction $record) {
                        $record->update(['completed_at' => now()]);

                        Notification::make()
                            ->title('پیگیری انجام شد')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->emptyStateHeading('تماسی ثبت نشده است')
            ->emptyStateIcon('heroicon-o-phone')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerInteractions::route('/'),
            'create' => Pages\CreateCustomerInteraction::route('/create'),
            'edit' => Pages\EditCustomerInteraction::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['summary', 'customer.name'];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return ($record->customer?->name ?? 'مشتری حذف‌شده').' — '
            .(CustomerInteraction::TYPES[$record->type] ?? $record->type);
    }
}
