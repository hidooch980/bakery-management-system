<?php

namespace App\Filament\Pages;

use App\Models\BaleSetting;
use App\Support\AppCalendar;
use App\Support\BaleNotifier;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The Bale bot the nightly backup can travel through — see ManageTelegram,
 * the sibling page this mirrors. Bale is reachable inside Iran where
 * Telegram itself is not, which is the whole reason this page exists
 * alongside it rather than instead of it.
 */
class ManageBale extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'ربات بله';

    protected static ?string $title = 'تنظیمات ربات بله';

    protected static ?int $navigationSort = -6;

    protected static string $view = 'filament.pages.manage-bale';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = BaleSetting::current();

        $this->form->fill([
            ...$settings->toArray(),
            'bot_token' => $settings->bot_token,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بازو')
                    ->description('یک بار در بله با @botfather چت کنید تا این دو مقدار را بگیرید.')
                    ->icon('heroicon-o-cpu-chip')
                    ->schema([
                        Forms\Components\TextInput::make('bot_token')
                            ->label('توکن بازو')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('از @botfather در بله. رمزنگاری‌شده ذخیره می‌شود.'),

                        Forms\Components\TextInput::make('chat_id')
                            ->label('شناسه چت')
                            ->maxLength(255)
                            ->helperText(
                                'یک پیام به بازو بفرستید، بعد در مرورگر آدرس '
                                .'tapi.bale.ai/bot<توکن>/getUpdates را باز '
                                .'کنید و عدد chat.id را از آنجا بردارید.'
                            ),
                    ]),

                Forms\Components\Section::make('ارسال خودکار پشتیبان')
                    ->description('هر شب یک نسخه از دیتابیس به همین بازو فرستاده می‌شود.')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->schema([
                        Forms\Components\Toggle::make('backup_bale_enabled')
                            ->label('ارسال شبانه‌ی پشتیبان به بله فعال باشد')
                            ->helperText('تا وقتی ارسال آزمایشی موفق نشده، روشن نکنید.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $settings = BaleSetting::current();
        $settings->fill($this->form->getState())->save();

        Notification::make()
            ->title('تنظیمات بله ذخیره شد.')
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
        $settings = BaleSetting::current();
        $settings->fill($this->form->getState())->save();

        if (! $settings->is_configured) {
            Notification::make()
                ->title('تنظیمات کامل نیست.')
                ->body('توکن بازو و شناسه چت لازم است.')
                ->warning()
                ->send();

            return;
        }

        $result = BaleNotifier::sendMessage(
            $settings->bot_token,
            $settings->chat_id,
            "این یک پیام آزمایشی از پنل نانوایی است.\nتاریخ: ".AppCalendar::dateTime(now()),
        );

        $settings->update([
            'last_tested_at' => now(),
            'last_test_succeeded' => $result['ok'],
            'last_test_error' => $result['ok'] ? null : $result['error'],
        ]);

        if ($result['ok']) {
            Notification::make()
                ->title('پیام آزمایشی ارسال شد.')
                ->body('اگر توی چت ندیدیدش، شناسه چت را دوباره چک کنید.')
                ->success()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title('ارسال ناموفق بود')
                ->body(str($result['error'])->limit(300))
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
                ->label('ارسال پیام آزمایشی')
                ->action('sendTest')
                ->color('gray')
                ->icon('heroicon-o-paper-airplane'),
        ];
    }
}
