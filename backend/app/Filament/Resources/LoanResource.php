<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers\PaymentsRelationManager;
use App\Models\Loan;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Money the shop borrowed, and how far through paying it back it is.
 *
 * What is left is counted from the repayments rather than typed, so it
 * cannot drift from what was actually paid.
 */
class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?string $navigationLabel = 'وام‌ها';

    protected static ?string $modelLabel = 'وام';

    protected static ?string $pluralModelLabel = 'وام‌ها';

    protected static ?int $navigationSort = 41;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات وام')->columns(2)->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان')
                    ->placeholder('مثلاً وام خرید تنور')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('lender')
                    ->label('وام‌دهنده')
                    ->placeholder('نام بانک یا شخص')
                    ->maxLength(255),

                Forms\Components\TextInput::make('principal')
                    ->label('مبلغ کل وام')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(Money::label())
                    ->required(),

                Forms\Components\TextInput::make('instalment_amount')
                    ->label('مبلغ هر قسط')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(Money::label())
                    ->helperText('برای حساب‌کردن تاریخ قسط بعدی'),

                Forms\Components\TextInput::make('instalment_count')
                    ->label('تعداد اقساط')
                    ->numeric()
                    ->minValue(0),

                Forms\Components\DatePicker::make('first_due_on')
                    ->label('سررسید اولین قسط')
                    ->native(false),

                Forms\Components\DatePicker::make('settled_on')
                    ->label('تاریخ تسویه کامل')
                    ->native(false)
                    ->helperText('اگر پر شود، دیگر جزو بدهی حساب نمی‌شود.'),

                Forms\Components\Textarea::make('note')
                    ->label('توضیحات')
                    ->columnSpanFull()
                    ->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->weight('bold')
                    ->description(fn (Loan $record) => $record->lender)
                    ->searchable(),

                Tables\Columns\TextColumn::make('principal')
                    ->label('مبلغ وام')
                    ->formatStateUsing(fn ($state) => Money::format($state)),

                Tables\Columns\TextColumn::make('paid_formatted')->label('پرداخت‌شده')->color('success'),

                Tables\Columns\TextColumn::make('remaining_formatted')
                    ->label('مانده')
                    ->weight('bold')
                    ->color(fn (Loan $record) => $record->remaining > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('progress_percent')
                    ->label('پیشرفت')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).'٪')
                    ->badge(),

                Tables\Columns\TextColumn::make('next_due_on_display')
                    ->label('قسط بعدی')
                    ->badge()
                    // Overdue is the one state worth chasing today.
                    ->color(fn (Loan $record) => $record->is_overdue ? 'danger' : 'gray')
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('settled_on')
                    ->label('وضعیت')
                    ->placeholder('همه')
                    ->trueLabel('تسویه‌شده')
                    ->falseLabel('در جریان')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('settled_on'),
                        false: fn ($query) => $query->whereNull('settled_on'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->emptyStateHeading('وامی ثبت نشده')
            ->emptyStateDescription('وام‌ها اینجا ثبت می‌شوند تا مانده‌شان در تراز مالی بیاید.');
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }
}
