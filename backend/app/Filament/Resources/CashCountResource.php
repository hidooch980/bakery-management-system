<?php

namespace App\Filament\Resources;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\CashCountResource\Pages;
use App\Models\BankAccount;
use App\Models\CashCount;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The drawer, counted against the books.
 *
 * Everything closed this month was about money *reaching* the till.
 * Nothing said whether the figure is true: change is given from the same
 * drawer, notes are handed over in a hurry, and a sale typed at the wrong
 * price leaves a gap both sides of the ledger agree about.
 *
 * A count is never edited. It is what somebody saw in the drawer at one
 * moment, and a figure that can be corrected afterwards into agreement is
 * not evidence of anything.
 */
class CashCountResource extends Resource
{
    protected static ?string $model = CashCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'شمارش صندوق';

    protected static ?string $modelLabel = 'شمارش صندوق';

    protected static ?string $pluralModelLabel = 'شمارش‌های صندوق';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('شمارش کشو')
                ->description(fn () => 'دفتر می‌گوید '
                    .Money::format((float) (BankAccount::cashBox()?->balance ?? 0))
                    .' در صندوق است. پول واقعی کشو را بشمارید و همین‌جا بنویسید.')
                ->columns(2)
                ->schema([
                    MoneyInput::make('counted_amount', 'پول شمرده‌شده')
                        ->required(),

                    Forms\Components\Toggle::make('adjust')
                        ->label('دفتر با کشو یکی شود')
                        ->helperText('خاموش بگذارید تا فقط ثبت شود و چیزی'
                            .' جابه‌جا نشود. روشن کنید تا اختلاف به‌عنوان یک'
                            .' ردیف اصلاحی در همین صندوق نوشته شود.')
                        ->default(false)
                        ->dehydrated(false),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیح')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('اگر دلیلی برای اختلاف می‌دانید، همین‌جا'
                            .' بنویسید — یک ماه بعد کسی یادش نیست.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('counted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('counted_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('counted_amount')
                    ->label('شمرده‌شده')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state)),

                Tables\Columns\TextColumn::make('expected_amount')
                    ->label('دفتر می‌گفت')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->color('gray'),

                // The one column worth reading. Said in words as well as
                // sign: «‎-۱۲۳٬۰۰۰» glanced at on a busy morning is the
                // one figure nobody should misread.
                Tables\Columns\TextColumn::make('difference')
                    ->label('اختلاف')
                    ->state(fn (CashCount $r) => $r->is_exact
                        ? 'می‌خواند'
                        : ($r->difference > 0 ? 'اضافه ' : 'کسری ')
                            .Money::format(abs($r->difference)))
                    ->badge()
                    ->color(fn (CashCount $r) => $r->is_exact ? 'success' : 'danger'),

                Tables\Columns\IconColumn::make('adjusted')
                    ->label('دفتر اصلاح شد')
                    ->state(fn (CashCount $r) => $r->adjustment !== null)
                    ->boolean(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('شمارنده')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیح')
                    ->wrap()
                    ->toggleable(),
            ])
            ->emptyStateHeading('هنوز صندوق شمرده نشده')
            ->emptyStateDescription('یک بار پول کشو را بشمارید. اختلافی که'
                .' همان شب دیده شود سؤالی است که هنوز جواب دارد؛ همان'
                .' اختلاف آخر ماه فقط یک عدد است.');
    }

    /** A count is what somebody saw. It is not corrected afterwards. */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashCounts::route('/'),
            'create' => Pages\CreateCashCount::route('/create'),
        ];
    }
}
