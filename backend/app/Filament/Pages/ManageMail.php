<?php

namespace App\Filament\Pages;

use App\Models\MailSetting;
use App\Support\AppCalendar;
use App\Support\MailConfigurator;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

/**
 * The mail server, set here rather than in a file on the server.
 *
 * The shop's nightly database backup is mailed off the machine, and the
 * credential doing it had quietly expired — invisible to everyone who could
 * not SSH in. It is now something the admin can see the state of, change,
 * and prove works, without touching the box.
 */
class ManageMail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'سرور ایمیل';

    protected static ?string $title = 'تنظیمات سرور ایمیل';

    protected static ?int $navigationSort = -8;

    protected static string $view = 'filament.pages.manage-mail';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = MailSetting::current();

        $this->form->fill([
            ...$settings->toArray(),
            // toArray() drops it, being hidden, and an empty box would read
            // as "no password set" when one is.
            'password' => $settings->password,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('سرور ارسال')
                    ->description('این تنظیمات را از پنل سرویس ایمیل خود بردارید.')
                    ->icon('heroicon-o-server')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('host')
                            ->label('آدرس سرور (SMTP Host)')
                            ->placeholder('smtp.example.com')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('port')
                            ->label('پورت')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(587)
                            ->helperText('معمولاً ۵۸۷ برای TLS و ۴۶۵ برای SSL'),

                        Forms\Components\TextInput::make('username')
                            ->label('نام کاربری')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('رمز عبور')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('رمزنگاری‌شده ذخیره می‌شود.'),

                        Forms\Components\Select::make('encryption')
                            ->label('رمزنگاری')
                            ->options([
                                'tls' => 'TLS (پورت ۵۸۷)',
                                'ssl' => 'SSL (پورت ۴۶۵)',
                            ])
                            ->default('tls'),
                    ]),

                Forms\Components\Section::make('فرستنده')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('from_address')
                            ->label('آدرس فرستنده')
                            ->email()
                            ->maxLength(255)
                            ->helperText('بیشتر سرویس‌ها این آدرس را باید از قبل تأیید کرده باشند.'),

                        Forms\Components\TextInput::make('from_name')
                            ->label('نام فرستنده')
                            ->maxLength(255),
                    ]),

                Forms\Components\Section::make('ارسال خودکار پشتیبان')
                    ->description('هر شب یک نسخه از دیتابیس به این آدرس‌ها فرستاده می‌شود.')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->schema([
                        Forms\Components\TextInput::make('backup_mail_to')
                            ->label('گیرندگان')
                            ->maxLength(500)
                            ->helperText('چند آدرس را با کاما جدا کنید. یک صندوق پر، پشتیبانی است که ندارید.'),

                        Forms\Components\Toggle::make('backup_mail_enabled')
                            ->label('ارسال شبانه‌ی پشتیبان فعال باشد')
                            ->helperText('تا وقتی ارسال آزمایشی موفق نشده، روشن نکنید.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $settings = MailSetting::current();
        $settings->fill($this->form->getState())->save();

        Notification::make()
            ->title('تنظیمات ایمیل ذخیره شد.')
            ->body('برای اطمینان، یک ارسال آزمایشی بزنید.')
            ->success()
            ->send();
    }

    /**
     * Sends one real message through the saved settings.
     *
     * Saving first, so the button tests what is in the boxes rather than
     * what was there before the admin edited them.
     */
    public function sendTest(): void
    {
        $settings = MailSetting::current();
        $settings->fill($this->form->getState())->save();

        if (! $settings->is_configured) {
            Notification::make()
                ->title('تنظیمات کامل نیست.')
                ->body('آدرس سرور، نام کاربری، رمز و آدرس فرستنده لازم است.')
                ->warning()
                ->send();

            return;
        }

        $recipients = $settings->recipients() ?: [$settings->from_address];

        MailConfigurator::apply();

        try {
            Mail::raw(
                "این یک پیام آزمایشی از پنل نانوایی است.\nتاریخ: ".AppCalendar::dateTime(now()),
                fn ($message) => $message->to($recipients[0])->subject('آزمایش سرور ایمیل'),
            );

            $settings->update([
                'last_tested_at' => now(),
                'last_test_succeeded' => true,
                'last_test_error' => null,
            ]);

            Notification::make()
                ->title('ارسال شد به '.$recipients[0])
                ->body('اگر در صندوق ورودی نبود، پوشه‌ی اسپم را ببینید.')
                ->success()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            // Kept rather than shown once: the reason a send failed is the
            // whole diagnosis, and it is gone the moment the toast fades.
            $settings->update([
                'last_tested_at' => now(),
                'last_test_succeeded' => false,
                'last_test_error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('ارسال ناموفق بود')
                ->body(str($e->getMessage())->limit(300))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('ذخیره تغییرات')
                ->submit('save')
                ->icon('heroicon-o-check'),

            Action::make('sendTest')
                ->label('ارسال ایمیل آزمایشی')
                ->action('sendTest')
                ->color('gray')
                ->icon('heroicon-o-paper-airplane'),
        ];
    }
}
