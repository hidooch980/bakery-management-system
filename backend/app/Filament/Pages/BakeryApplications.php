<?php

namespace App\Filament\Pages;

use App\Actions\OpenBakery as OpenBakeryAction;
use App\Models\BakeryApplication;
use App\Models\Subscription;
use App\Rules\NotAGuessablePassword;
use App\Support\Exclusively;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * نانوایی‌هایی که درخواست داده‌اند، و جواب دادن به آن‌ها.
 *
 * درِ عمومی از قبل ساخته شده بود و ردیف می‌نوشت، ولی هیچ صفحه‌ای
 * نداشت — یعنی درخواست‌ها می‌آمدند و در جدولی می‌نشستند که هیچ‌کس
 * بازش نمی‌کرد. یک دفترِ پستی بدون کسی که نامه‌ها را بردارد.
 *
 * فقط از نانوایی مادر دیده می‌شود، مثل صفحهٔ «نانوایی جدید» و به همان
 * دلیل: مدیرِ نانوایی‌ای که خودش از همین راه باز شده، همان اجازه‌ای را
 * دارد که نانوایی خودش به او می‌دهد، و آن اجازه نباید برای باز کردن
 * نانوایی روی سامانهٔ کسِ دیگر کافی باشد.
 */
class BakeryApplications extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'درخواست‌های نانوایی';

    protected static ?string $title = 'درخواست‌های نانوایی تازه';

    protected static ?int $navigationSort = -8;

    protected static ?string $slug = 'bakery-applications';

    protected static string $view = 'filament.pages.bakery-applications';

    /** همان قاعدهٔ صفحهٔ «نانوایی جدید» — یک جا نوشته، دو جا خوانده. */
    public static function canAccess(): bool
    {
        return OpenBakery::canAccess();
    }

    /**
     * عددِ کنار نام در منو: چند درخواست منتظر جواب است.
     *
     * بدون این، صفحه فقط وقتی باز می‌شود که کسی یادش بیفتد — و
     * درخواستی که هفته‌ها بی‌جواب بماند، همان نانوایی‌ای است که
     * جای دیگری می‌رود.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        $waiting = BakeryApplication::pending()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** @return Collection<int, BakeryApplication> */
    public function applications(): Collection
    {
        return BakeryApplication::query()
            ->with(['reviewedBy:id,name', 'bakery:id,name'])
            // در انتظارها اول، چون کارِ نکرده است؛ بقیه به ترتیب تازگی.
            ->orderByRaw("status = 'pending' desc")
            ->latest('id')
            ->get();
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('پذیرش و ساخت نانوایی')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('نانوایی ساخته شود')
            ->modalDescription('نانوایی با همین نام باز می‌شود و مدیرش'
                .' می‌تواند بلافاصله وارد شود.')
            ->modalSubmitActionLabel('بساز')
            ->form([
                Forms\Components\TextInput::make('email')
                    ->label('ایمیل ورود')
                    ->email()
                    ->required()
                    ->unique('users', 'email')
                    // درخواست‌دهنده فقط شماره لازم است بدهد و
                    // users.email خالی را نمی‌پذیرد، پس اینجا پرسیده
                    // می‌شود — وقتی که به‌هرحال پای تلفن با همان شخص
                    // هستید.
                    ->helperText('اگر در فرم ایمیل داده بود همین‌جا آمده؛'
                        .' وگرنه یکی بگیرید.'),

                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->rule(new NotAGuessablePassword)
                    ->helperText('همین را به خودش بدهید؛ بعداً عوضش می‌کند.'),

                Forms\Components\TextInput::make('months')
                    ->label('اشتراک برای چند ماه')
                    ->numeric()
                    ->default(12)
                    ->minValue(1)
                    ->maxValue(36)
                    ->required(),
            ])
            ->fillForm(fn (array $arguments) => [
                'email' => BakeryApplication::find($arguments['id'])?->email,
                'months' => 12,
            ])
            ->action(fn (array $arguments, array $data) => $this->approve($arguments['id'], $data));
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('رد')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('این درخواست رد شود')
            ->modalSubmitActionLabel('رد کن')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('دلیل')
                    ->required()
                    ->maxLength(500)
                    // اجباری، چون ردی بدون دلیل همان چیزی است که سه ماه
                    // بعد کسی نمی‌فهمد چرا.
                    ->helperText('برای خودتان می‌ماند؛ به درخواست‌دهنده'
                        .' فرستاده نمی‌شود.'),
            ])
            ->action(fn (array $arguments, array $data) => $this->reject($arguments['id'], $data['reason']));
    }

    private function approve(int $id, array $data): void
    {
        $application = BakeryApplication::findOrFail($id);
        $bakery = null;

        // دو نفر که یک لحظه فاصله دارند نمی‌توانند یک درخواست را دو بار
        // بپذیرند — نانوایی دوم شبحی می‌شد که هیچ‌کس هرگز واردش نمی‌شود.
        Exclusively::claim(
            $application,
            fn (BakeryApplication $a) => $a->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            function (BakeryApplication $a) use ($data, &$bakery) {
                $bakery = (new OpenBakeryAction)->run(
                    name: $a->bakery_name,
                    adminName: $a->owner_name,
                    email: $data['email'],
                    phone: $a->phone,
                    password: $data['password'],
                );

                Subscription::create([
                    'bakery_id' => $bakery->id,
                    'plan' => 'standard',
                    'starts_on' => now(),
                    'ends_on' => now()->copy()->addMonths((int) $data['months']),
                    'created_by' => auth()->id(),
                ]);

                $a->update([
                    'status' => BakeryApplication::APPROVED,
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'bakery_id' => $bakery->id,
                ]);
            },
        );

        Notification::make()
            ->title('نانوایی «'.$application->bakery_name.'» باز شد.')
            ->body('مدیرش با '.$data['email'].' وارد می‌شود.')
            ->success()
            ->persistent()
            ->send();
    }

    private function reject(int $id, string $reason): void
    {
        $application = BakeryApplication::findOrFail($id);

        Exclusively::claim(
            $application,
            fn (BakeryApplication $a) => $a->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            fn (BakeryApplication $a) => $a->update([
                'status' => BakeryApplication::REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
                'rejection_reason' => $reason,
            ]),
        );

        Notification::make()->title('درخواست رد شد.')->success()->send();
    }
}
