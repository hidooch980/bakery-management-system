<?php

namespace App\Filament\Resources;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Rules\NotAGuessablePassword;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'کاربران';

    protected static ?string $modelLabel = 'کاربر';

    protected static ?string $pluralModelLabel = 'کاربران';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Three sections rather than one, because the seven fields
            // answer three different questions and were being asked as
            // though they were one. In a flat two-column grid the password
            // landed beside the monthly wage, the password's helper text
            // made its row taller than the one next to it, and the
            // «حساب فعال» toggle sat alone with a gap for company.
            Forms\Components\Section::make('کیست')
                ->icon('heroicon-o-user-circle')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام و نام خانوادگی')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('شماره تلفن')
                        ->tel()
                        ->unique(ignoreRecord: true)
                        ->maxLength(20),
                ]),

            Forms\Components\Section::make('ورود به سامانه')
                ->description('با ایمیل یا شماره تلفن وارد می‌شود.')
                ->icon('heroicon-o-key')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('ایمیل')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\Select::make('role')
                        ->label('نقش')
                        ->options(fn () => Role::pluck('name', 'name')->map(fn ($n) => self::roleLabel($n))->toArray())
                        ->required()
                        ->native(false)
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\Select $component, ?User $record) {
                            $component->state($record?->getRoleNames()->first());
                        }),

                    // Full width: its helper text is two lines on a phone,
                    // and in a half-width cell it dragged whatever sat
                    // beside it out of line with the rest of the form.
                    Forms\Components\TextInput::make('password')
                        ->label('رمز عبور')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        // Length was never the test. One of this shop's
                        // five accounts had eight characters of a password
                        // that is on every published list of the commonest
                        // ones, and it would be guessed in under a second.
                        ->rule(new NotAGuessablePassword)
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText('در حالت ویرایش، خالی بگذارید تا تغییر نکند.')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('حساب فعال')
                        ->helperText('حساب غیرفعال نمی‌تواند وارد شود، و سابقه‌اش می‌ماند.')
                        ->default(true)
                        ->inline(false)
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpanFull(),
                ]),

            // On its own, because a wage is not an account setting. It
                // prefills the payroll form and nothing else, and mixing it
            // in with the password was how this form read as a jumble.
            Forms\Components\Section::make('حقوق')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    MoneyInput::make('monthly_salary', 'حقوق ماهانه')
                        ->helperText('برای پیش‌پر کردن فرم حقوق استفاده می‌شود. پرداخت از همین‌جا انجام نمی‌شود.'),
                ]),
        ]);
    }

    /**
     * Staff belong to one shop, and the user model carries no global scope
     * — resolving the signed-in user is how the current shop is worked out,
     * so scoping it by that would ask the question to answer it. The panel
     * therefore says so here.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ofCurrentBakery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('تلفن')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('email')
                    ->label('ایمیل')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('نقش')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::roleLabel($state))
                    ->color(fn ($state) => match ($state) {
                        'admin' => 'danger',
                        'dough_maker' => 'warning',
                        'chane_gir' => 'info',
                        'shater' => 'primary',
                        'seller' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('monthly_salary')
                    ->label('حقوق ماهانه')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('آخرین ورود')
                    ->formatStateUsing(fn ($state) => Jalali::dateTime($state))
                    ->placeholder('هرگز')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ساخت')
                    ->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('نقش')
                    ->relationship('roles', 'name')
                    ->options(fn () => Role::pluck('name', 'name')->map(fn ($n) => self::roleLabel($n))->toArray()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('وضعیت')
                    ->trueLabel('فعال')
                    ->falseLabel('غیرفعال'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'غیرفعال کردن' : 'فعال کردن')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->hidden(fn (User $record) => $record->id === auth()->id())
                    ->action(function (User $record) {
                        $record->update(['is_active' => ! $record->is_active]);
                        $record->tokens()->delete();
                    }),
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->hidden(fn (User $record) => $record->id === auth()->id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده‌ها'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin' => 'مدیر',
            'dough_maker' => 'خمیرگیر',
            'chane_gir' => 'چانه‌گیر',
            'shater' => 'شاطر',
            'seller' => 'فروشنده',
            default => (string) $role,
        };
    }
}
