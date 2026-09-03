<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Resources\SupplierResource\RelationManagers;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The shop's side of the mill's book.
 *
 * The balance is derived from the invoices and the payments, never typed,
 * so this page cannot disagree with the ones it is drawn from.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'خرید';

    protected static ?string $navigationLabel = 'تأمین‌کنندگان';

    protected static ?string $modelLabel = 'تأمین‌کننده';

    protected static ?string $pluralModelLabel = 'تأمین‌کنندگان';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات تأمین‌کننده')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('تلفن')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('kind')
                        ->label('چه می‌فروشد')
                        ->placeholder('کارخانه آرد، بنکدار، حمل و نقل')
                        // Free text on purpose: the shop's own words, not a
                        // list this project invented and made them choose
                        // from. A picker with the wrong five options in it
                        // is answered by picking «سایر» every time.
                        ->maxLength(100),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true)
                        ->helperText('غیرفعال از فهرست انتخاب فاکتور برداشته می‌شود'),

                    Forms\Components\Textarea::make('note')
                        ->label('توضیحات')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('kind')
                    ->label('چه می‌فروشد')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('تلفن')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('purchases_count')
                    ->label('فاکتورها')
                    ->counts('purchases')
                    ->alignCenter(),

                // Derived, so it cannot be sorted in the database. Sorting
                // it there would need the figure stored, and a stored
                // total is a total that can drift from its parts.
                Tables\Columns\TextColumn::make('balance')
                    ->label('مانده بدهی')
                    ->state(fn (Supplier $record) => $record->balance)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color(fn ($state) => match (true) {
                        $state > 0.01 => 'danger',
                        $state < -0.01 => 'info',
                        default => 'success',
                    })
                    ->description(fn ($state) => match (true) {
                        $state > 0.01 => 'بدهکاریم',
                        $state < -0.01 => 'طلبکاریم',
                        default => 'تسویه',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('فعال')
                    ->placeholder('همه')
                    ->trueLabel('فعال')
                    ->falseLabel('غیرفعال'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    // A supplier whose invoices are gone is a supplier the
                    // shop cannot be audited on, so history wins over
                    // tidiness. Deactivating is what «حذف» usually means
                    // here, and the form offers it.
                    ->hidden(fn (Supplier $record) => $record->purchases()->exists()
                        || $record->payments()->exists()),
            ])
            ->defaultSort('name')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    /** How many accounts are not square, for the badge on the menu. */
    public static function getNavigationBadge(): ?string
    {
        $owing = Supplier::query()->with(['purchases', 'payments'])->get()
            ->filter(fn (Supplier $s) => $s->balance > 0.01)
            ->count();

        return $owing > 0 ? (string) $owing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
