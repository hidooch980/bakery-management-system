<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SellerWarningRuleResource\Pages;
use App\Models\SellerWarningRule;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Seller-warning rules. Each active rule is checked every morning against
 * yesterday and files a pending warning into «درخواست تنبیه», where the
 * manager approves or rejects it. Nothing is docked by a rule.
 */
class SellerWarningRuleResource extends Resource
{
    protected static ?string $model = SellerWarningRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'قوانین اخطار فروشنده';

    protected static ?string $modelLabel = 'قانون اخطار';

    protected static ?string $pluralModelLabel = 'قوانین اخطار فروشنده';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('نام قانون')->required()->maxLength(255),
            Forms\Components\TextInput::make('threshold')->label(fn (?SellerWarningRule $record) => 'مقدار ('
                .($record?->isMoney() ? Money::label() : (SellerWarningRule::UNIT_LABELS[$record?->unit ?? 'money'] ?? '')).')')
                ->numeric()->minValue(0)
                ->formatStateUsing(fn ($state, ?SellerWarningRule $record) => $state === null ? null
                    : ($record?->isMoney() ? Money::convert((float) $state) : (float) $state))
                ->dehydrateStateUsing(fn ($state, ?SellerWarningRule $record) => $state === null || $state === '' ? null
                    : ($record?->isMoney() ? Money::toToman($state) : $state)),
            Forms\Components\Textarea::make('description')->label('متن قانون / توضیح')->rows(4)->maxLength(2000)
                ->helperText('متن قانون را بعداً اینجا بنویسید؛ همراه اخطار دیده می‌شود.'),
            Forms\Components\Toggle::make('is_active')->label('فعال'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('قانون')->weight('bold')
                    ->description(fn (SellerWarningRule $r) => $r->condition_label),
                Tables\Columns\TextColumn::make('threshold')->label('مقدار')
                    ->state(fn (SellerWarningRule $r) => $r->threshold === null ? 'تعیین نشده'
                        : ($r->isMoney() ? Money::format((float) $r->threshold)
                            : (float) $r->threshold.' '.SellerWarningRule::UNIT_LABELS[$r->unit])),
                Tables\Columns\TextColumn::make('source')->label('منبع')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'nanino' ? 'نانینو' : 'داخلی')
                    ->url(fn (SellerWarningRule $r) => $r->source_url, shouldOpenInNewTab: true),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->before(function (SellerWarningRule $record, array $data, Tables\Actions\EditAction $action) {
                        if (($data['is_active'] ?? false) && ((float) ($data['threshold'] ?? 0)) <= 0) {
                            Notification::make()->title('اول مقدار این قانون را وارد کنید، بعد روشنش کنید.')->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->emptyStateHeading('قانونی تعریف نشده')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellerWarningRules::route('/'),
        ];
    }
}
