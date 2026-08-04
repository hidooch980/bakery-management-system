<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\BankAccount;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Each repayment against a loan.
 *
 * Recorded here rather than as a figure on the loan, so what is left is
 * counted — a remaining balance kept by hand drifts the first time someone
 * pays twice in a month or misses one.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'اقساط پرداخت‌شده';

    protected static ?string $modelLabel = 'قسط';

    protected static ?string $pluralModelLabel = 'اقساط';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label('مبلغ')
                ->numeric()
                ->minValue(0)
                ->suffix(Money::label())
                ->default(fn () => $this->getOwnerRecord()->instalment_amount)
                ->required(),

            Forms\Components\DatePicker::make('paid_on')
                ->label('تاریخ پرداخت')
                ->native(false)
                ->default(now())
                ->required(),

            // A repayment leaves the account it was paid from, like any
            // other cost. Left empty, it came out of the till.
            Forms\Components\Select::make('bank_account_id')
                ->label('از حساب')
                ->options(fn () => BankAccount::active()->pluck('title', 'id'))
                ->default(fn () => BankAccount::defaultAccount()?->id)
                ->native(false)
                ->placeholder('پرداخت نقدی')
                ->helperText('اگر انتخاب شود، مبلغ از همان حساب کم می‌شود'),

            Forms\Components\Textarea::make('note')->label('توضیحات')->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount_formatted')
            ->columns([
                Tables\Columns\TextColumn::make('paid_on_display')->label('تاریخ'),
                Tables\Columns\TextColumn::make('amount_formatted')
                    ->label('مبلغ')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('note')->label('توضیحات')->wrap()->placeholder('—'),
            ])
            ->defaultSort('paid_on', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('ثبت قسط')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->emptyStateHeading('هنوز قسطی پرداخت نشده');
    }
}
