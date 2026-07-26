<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BakeryShareResource\Pages;
use App\Models\BakeryShare;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BakeryShareResource extends Resource
{
    protected static ?string $model = BakeryShare::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'دانگ و شرکا';

    protected static ?string $modelLabel = 'شریک';

    protected static ?string $pluralModelLabel = 'دانگ و شرکا';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات شریک')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام شریک')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('dang')
                        ->label('دانگ')
                        ->numeric()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->required()
                        ->suffix('دانگ')
                        ->helperText(fn () => 'کل دانگ ثبت‌شده در حال حاضر: '
                            .BakeryShare::totalDang().' — سهم هر شریک نسبت به همین جمع محاسبه می‌شود'),

                    Forms\Components\Select::make('user_id')
                        ->label('کاربر مرتبط')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->placeholder('—')
                        ->helperText('اختیاری؛ اگر شریک در سیستم حساب کاربری دارد'),

                    Forms\Components\TextInput::make('phone')
                        ->label('شماره تماس')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true)
                        ->helperText('شریک غیرفعال در تقسیم سود شرکت داده نمی‌شود'),

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
                    ->label('شریک')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('dang')
                    ->label('دانگ')
                    ->formatStateUsing(fn (BakeryShare $record) => $record->dang_label)
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                Tables\Columns\TextColumn::make('share_percent')
                    ->label('سهم')
                    ->formatStateUsing(fn ($state) => $state.'٪')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('current_share')
                    ->label('سهم سود این ماه')
                    // Derived from the same ledger the reports use, so the
                    // two figures can never drift apart.
                    ->getStateUsing(function (BakeryShare $record) {
                        [$from, $to] = Jalali::currentMonthRange();

                        return Money::format($record->profitShare($from, $to));
                    })
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('تماس')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('split')
                    ->label('تقسیم سود این ماه')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->action(function () {
                        [$from, $to] = Jalali::currentMonthRange();
                        $profit = Ledger::profit($from, $to);
                        $split = BakeryShare::splitFor($from, $to);

                        $lines = collect($split['holders'])
                            ->map(fn ($h) => $h['name'].' ('.$h['dang_label'].'): '.$h['amount_formatted'])
                            ->implode("\n");

                        Notification::make()
                            ->title('سود '.Jalali::monthLabel($from).': '.Money::format($profit))
                            ->body($lines ?: 'هنوز شریکی ثبت نشده است.')
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->defaultSort('dang', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBakeryShares::route('/'),
            'create' => Pages\CreateBakeryShare::route('/create'),
            'edit' => Pages\EditBakeryShare::route('/{record}/edit'),
        ];
    }
}
