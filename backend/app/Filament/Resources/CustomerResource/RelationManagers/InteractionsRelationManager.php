<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CustomerInteraction;
use App\Support\AppCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The record of dealing with this customer: the calls, the visits, the
 * promises. A sale says what they bought; this says what was said about it.
 */
class InteractionsRelationManager extends RelationManager
{
    protected static string $relationship = 'interactions';

    protected static ?string $title = 'سابقه تماس و پیگیری';

    protected static ?string $modelLabel = 'تماس';

    protected static ?string $pluralModelLabel = 'تماس‌ها';

    public function form(Form $form): Form
    {
        return $form->schema([
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
                ->columnSpanFull(),

            \App\Filament\Forms\JalaliDateInput::make('follow_up_on', 'پیگیری بعدی')
                ->helperText('خالی بگذارید اگر پیگیری لازم نیست.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state)),

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
                    ->limit(120),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('ثبت‌کننده')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('follow_up_on')
                    ->label('پیگیری بعدی')
                    ->placeholder('—')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AppCalendar::date($state))
                    ->color(fn (CustomerInteraction $record) => match (true) {
                        ! $record->is_open => 'success',
                        $record->is_overdue => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (CustomerInteraction $record) => match (true) {
                        $record->completed_at !== null => 'انجام شد',
                        $record->is_overdue => 'عقب‌افتاده',
                        default => null,
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('ثبت تماس')
                    // The record is of who dealt with the customer, so the
                    // panel signs it rather than asking.
                    ->mutateFormDataUsing(function (array $data) {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('complete')
                    ->label('انجام شد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerInteraction $record) => $record->is_open)
                    ->action(fn (CustomerInteraction $record) => $record->update([
                        'completed_at' => now(),
                    ])),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
