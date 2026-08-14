<?php

namespace App\Filament\Pages;

use App\Actions\OpenBakery as OpenBakeryAction;
use App\Models\Bakery;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Opening another shop, from the panel.
 *
 * This was a server command only, on the reasoning that an admin belongs to
 * one shop and so is in no position to create a second. That holds for the
 * admin of a shop opened here — it does not hold for the owner, who runs the
 * head shop, owns all of them, and should not need a terminal to open one.
 *
 * So the page exists and is reachable only from the head shop: it is absent
 * from every other shop's menu, and refuses the request even if the address
 * is typed in by hand.
 */
class OpenBakery extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'نانوایی جدید';

    protected static ?string $title = 'ساخت نانوایی جدید';

    protected static ?int $navigationSort = -9;

    protected static string $view = 'filament.pages.open-bakery';

    public ?array $data = [];

    /**
     * The first shop on the installation — the one the owner runs.
     *
     * Every other shop was opened from it, and the same reading of "the
     * first shop" is what CurrentBakery falls back to, so the two agree
     * on which shop is the head one.
     */
    public static function headShop(): ?Bakery
    {
        return Bakery::query()->oldest('id')->first();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $head = self::headShop();

        return $user !== null
            && $head !== null
            && $user->bakery_id === $head->id;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->form->fill(['copy_from' => self::headShop()?->id]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('نانوایی')
                    ->icon('heroicon-o-building-storefront')
                    ->schema([
                        Forms\Components\Placeholder::make('existing')
                            ->label('نانوایی‌های فعلی')
                            ->content(fn () => new HtmlString($this->existingShops())),

                        Forms\Components\TextInput::make('name')
                            ->label('نام نانوایی جدید')
                            ->required()
                            ->maxLength(255)
                            ->helperText('همین نام در پنل و در اپلیکیشن کارکنان آن نانوایی دیده می‌شود.'),

                        Forms\Components\Select::make('copy_from')
                            ->label('کپی تنظیمات از')
                            ->options(fn () => Bakery::query()
                                ->orderBy('id')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('هیچ‌کدام — تنظیمات را خودم وارد می‌کنم')
                            ->helperText('وزن کیسه، وزن چانه، فرمول خمیر، قیمت نان و تقویم از این'
                                .' نانوایی کپی می‌شود. نام، آدرس، تلفن و لوگو کپی نمی‌شود و'
                                .' حساب‌های مالی هر نانوایی از روز اول جداست.'),
                    ]),

                Forms\Components\Section::make('مدیر این نانوایی')
                    ->icon('heroicon-o-user-circle')
                    ->description('این شخص با همین ایمیل یا تلفن وارد پنل و اپلیکیشن می‌شود و'
                        .' فقط نانوایی خودش را می‌بیند.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('admin_name')
                            ->label('نام مدیر')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('شماره تلفن')
                            ->tel()
                            ->maxLength(20)
                            ->unique('users', 'phone')
                            ->helperText('اختیاری — اگر وارد شود، با همین هم می‌تواند وارد شود.'),

                        Forms\Components\TextInput::make('email')
                            ->label('ایمیل (نام کاربری)')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique('users', 'email')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('password')
                            ->label('رمز عبور')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->same('password_confirmation')
                            ->helperText('حداقل ۸ کاراکتر. خود مدیر بعداً می‌تواند عوضش کند.'),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('تکرار رمز عبور')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false)
                            ->helperText('رمزی که کسی نتواند با آن وارد شود، نانوایی را قفل می‌کند.'),
                    ]),
            ])
            ->statePath('data');
    }

    /** What is already open, so one shop is not created twice under two names. */
    private function existingShops(): string
    {
        return Bakery::query()
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Bakery $shop) => sprintf(
                '%d — <b>%s</b> (%s کاربر)',
                $shop->id,
                e($shop->name),
                number_format($shop->users_count),
            ))
            ->implode('<br>');
    }

    public function create(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();

        $bakery = app(OpenBakeryAction::class)->run(
            name: $data['name'],
            adminName: $data['admin_name'],
            email: $data['email'],
            phone: filled($data['phone'] ?? null) ? $data['phone'] : null,
            password: $data['password'],
            copyFrom: filled($data['copy_from'] ?? null) ? Bakery::find($data['copy_from']) : null,
        );

        Notification::make()
            ->title("نانوایی «{$bakery->name}» ساخته شد.")
            ->body("مدیر آن با {$data['email']} وارد می‌شود. تعداد پخت را خودش از پنل خودش تنظیم می‌کند.")
            ->success()
            ->persistent()
            ->send();

        // Emptied rather than left filled: the next shop is a different shop,
        // and a form still holding the last admin's email invites creating
        // the same person twice.
        $this->form->fill(['copy_from' => self::headShop()?->id]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('ساخت نانوایی')
                ->submit('create')
                ->icon('heroicon-o-plus-circle')
                ->requiresConfirmation()
                ->modalHeading('ساخت نانوایی جدید')
                ->modalDescription('یک نانوایی تازه با حساب‌های مالی کاملاً جدا و یک مدیر'
                    .' برای آن ساخته می‌شود. این کار از پنل برگشت‌پذیر نیست.')
                ->modalSubmitActionLabel('بله، بساز'),
        ];
    }
}
