<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Filament\Forms\JalaliDateInput;
use App\Filament\Forms\MoneyInput;
use App\Models\BankAccount;
use App\Models\Purchase;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Money paid to this supplier after the delivery.
 *
 * What was handed over at the door lives on the invoice, because that is
 * one event. This is the other kind: a round figure paid on account days
 * later, which is how this shop settles with a mill.
 *
 * Naming an invoice is offered and never required. Forcing the choice
 * would make somebody pick one at random, and a payment filed against the
 * wrong invoice is harder to unpick than one filed against none.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'پرداخت‌ها';

    protected static ?string $modelLabel = 'پرداخت';

    protected static ?string $pluralModelLabel = 'پرداخت‌ها';

    public function form(Form $form): Form
    {
        return $form->schema([
            MoneyInput::make('amount', 'مبلغ')
                ->required(),

            JalaliDateInput::today('paid_on', 'تاریخ پرداخت')
                ->required(),

            Forms\Components\Select::make('purchase_id')
                ->label('بابت فاکتور')
                ->options(fn () => Purchase::query()
                    ->where('supplier_id', $this->getOwnerRecord()->getKey())
                    ->latest('purchased_on')
                    ->get()
                    ->mapWithKeys(fn (Purchase $p) => [
                        $p->id => trim(($p->invoice_no ? "فاکتور {$p->invoice_no} — " : '')
                            .$p->purchased_on_jalali.' — '.$p->amount_formatted),
                    ]))
                ->native(false)
                ->searchable()
                ->placeholder('علی‌الحساب — بابت هیچ فاکتور مشخصی')
                ->helperText('خالی بگذارید تا از کل حساب کم شود'),

            Forms\Components\Select::make('bank_account_id')
                ->label('از حساب')
                ->options(fn () => BankAccount::active()->pluck('title', 'id'))
                ->default(fn () => BankAccount::defaultAccount()?->id)
                ->native(false)
                ->searchable()
                ->placeholder('پرداخت نقدی')
                ->helperText('اگر انتخاب شود، مبلغ از همان حساب کم می‌شود'),

            Forms\Components\Textarea::make('note')
                ->label('توضیحات')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount_formatted')
            ->columns([
                Tables\Columns\TextColumn::make('paid_on_jalali')->label('تاریخ'),

                Tables\Columns\TextColumn::make('amount_formatted')
                    ->label('مبلغ')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('purchase.invoice_no')
                    ->label('بابت فاکتور')
                    ->placeholder('علی‌الحساب'),

                Tables\Columns\TextColumn::make('bankAccount.title')
                    ->label('از حساب')
                    ->placeholder('نقدی'),

                Tables\Columns\TextColumn::make('note')
                    ->label('توضیحات')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_on', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('ثبت پرداخت')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->emptyStateHeading('هنوز پرداختی ثبت نشده');
    }
}
