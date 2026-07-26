<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConsignmentFlourResource\Pages;
use App\Models\ConsignmentFlour;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConsignmentFlourResource extends Resource
{
    protected static ?string $model = ConsignmentFlour::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'انبار';

    protected static ?string $navigationLabel = 'آرد امانی';

    protected static ?string $modelLabel = 'آرد امانی';

    protected static ?string $pluralModelLabel = 'آرد امانی';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('آرد امانی همکار')
                ->description('آردی که از نانوایی همکار گرفته یا به او داده‌اید. جدا از سهمیه محاسبه می‌شود.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('همکار / نانوایی')
                        ->relationship(
                            'partner',
                            'name',
                            fn ($query) => $query->partners()->active()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        // Lets the admin define a new partner without leaving.
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('نام همکار / نانوایی')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('phone')
                                ->label('تلفن')
                                ->tel()
                                ->maxLength(20),
                        ])
                        ->createOptionUsing(fn (array $data) => \App\Models\Customer::create(
                            $data + ['type' => \App\Models\Customer::PARTNER_TYPE, 'is_active' => true]
                        )->id)
                        ->helperText('از فهرست انتخاب کنید یا با + همکار جدید تعریف کنید.'),

                    Forms\Components\Select::make('direction')
                        ->label('نوع')
                        ->options(ConsignmentFlour::DIRECTIONS)
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('amount_kg')
                        ->label('مقدار')
                        ->numeric()
                        ->minValue(0.001)
                        ->required()
                        ->suffix('کیلوگرم'),

                    \App\Filament\Forms\JalaliDateInput::today('occurred_on', 'تاریخ')
                        ->required(),

                    \App\Filament\Forms\JalaliDateInput::make('settled_on', 'تاریخ تسویه')
                        ->helperText('خالی بگذارید تا در وضعیت «تسویه‌نشده» بماند.'),

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
                Tables\Columns\TextColumn::make('occurred_on')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('partner.name')
                    ->label('همکار')
                    ->state(fn (ConsignmentFlour $record) => $record->partner_label)
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-m-building-storefront'),

                Tables\Columns\TextColumn::make('direction')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ConsignmentFlour::DIRECTIONS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'borrowed' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('amount_kg')
                    ->label('مقدار')
                    ->numeric(3)
                    ->suffix(' کیلوگرم')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('جمع')),

                Tables\Columns\TextColumn::make('settled_on')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state
                        ? 'تسویه شد: '.AppCalendar::date($state)
                        : 'تسویه‌نشده')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->label('نوع')
                    ->options(ConsignmentFlour::DIRECTIONS),

                Tables\Filters\Filter::make('outstanding')
                    ->label('فقط تسویه‌نشده‌ها')
                    ->query(fn ($query) => $query->whereNull('settled_on'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('ثبت تسویه')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ConsignmentFlour $record) => ! $record->is_settled)
                    ->action(fn (ConsignmentFlour $record) => $record->update(['settled_on' => now()])),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConsignmentFlour::route('/'),
            'create' => Pages\CreateConsignmentFlour::route('/create'),
            'edit' => Pages\EditConsignmentFlour::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $outstanding = static::getModel()::whereNull('settled_on')->count();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
