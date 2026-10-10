<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Pages;
use App\Models\Customer;
use App\Support\AppCalendar;
use App\Support\PartnerStatement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * همکاران — نانوایی‌هایی که با آن‌ها آرد امانی رد و بدل می‌شود.
 *
 * همان جدول customers با نوع «همکار / نانوایی»؛ مدل جدیدی ساخته نشده.
 * هر نام در فهرست به پروندهٔ همان همکار می‌رود: گردش ریز به کیسه با
 * ماندهٔ بعد از هر ردیف.
 */
class PartnerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'partners';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'همکاران';

    protected static ?string $modelLabel = 'همکار';

    protected static ?string $pluralModelLabel = 'همکاران';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->partners()
            ->withCount('consignments');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('همکار / نانوایی')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام همکار / نانوایی')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('phone')
                        ->label('تلفن')
                        ->tel()
                        ->maxLength(20),
                    Forms\Components\TextInput::make('address')
                        ->label('نشانی')
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true)
                        ->inline(false),
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
                    ->label('نام همکار (برای دیدن گردش کلیک کنید)')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary')
                    ->url(fn (Customer $record) => self::getUrl('statement', ['record' => $record])),

                Tables\Columns\TextColumn::make('phone')
                    ->label('تلفن')
                    ->placeholder('ثبت نشده'),

                Tables\Columns\TextColumn::make('consignments_count')
                    ->label('تعداد ثبت')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('آخرین گردش')
                    ->state(function (Customer $record) {
                        $last = PartnerStatement::events($record)->max('date');

                        return $last ? AppCalendar::date($last) : null;
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('مانده (کیسه)')
                    ->state(fn (Customer $record) => PartnerStatement::headline(
                        PartnerStatement::balanceOf($record)
                    )['label'])
                    ->weight('bold')
                    ->color(fn (Customer $record) => match (PartnerStatement::headline(
                        PartnerStatement::balanceOf($record)
                    )['tone']) {
                        'owed' => 'success',
                        'owes' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('فعال'),
            ])
            ->actions([
                Tables\Actions\Action::make('statement')
                    ->label('پرونده')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Customer $record) => self::getUrl('statement', ['record' => $record])),
                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->defaultSort('name')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
            'statement' => Pages\PartnerStatementPage::route('/{record}/statement'),
        ];
    }
}
