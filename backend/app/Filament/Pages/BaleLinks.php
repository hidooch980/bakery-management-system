<?php

namespace App\Filament\Pages;

use App\Models\BaleSetting;
use App\Support\BaleLink;
use App\Support\Sms;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * پنل مدیر: چه کسی به ربات بله وصل است، پیوند و QR دعوت، و کلیدهای
 * اتصال. هیچ دکمه‌ای در این صفحه پیامی برای کسی نمی‌فرستد.
 */
class BaleLinks extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'اتصال افراد به بله';

    protected static ?string $title = 'اتصال همکارها و کارکنان به بله';

    protected static ?int $navigationSort = -5;

    protected static string $view = 'filament.pages.bale-links';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_active && (bool) auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        $s = BaleSetting::current();
        $this->form->fill([
            'bot_username' => $s->bot_username,
            'link_enabled' => (bool) $s->link_enabled,
            'deliver_via_bale' => (bool) $s->deliver_via_bale,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('کلیدها')
                ->description('هر دو خاموش شروع می‌شوند. کد بازیابی رمز همیشه پیامکی می‌ماند.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('bot_username')
                        ->label('نام کاربری ربات در بله')
                        ->prefix('@')->maxLength(64)
                        ->regex('/^@?[A-Za-z][A-Za-z0-9_]{3,63}$/'),
                    Forms\Components\Toggle::make('link_enabled')
                        ->label('پذیرش شماره در ربات')
                        ->helperText('روشن: ربات به «ارسال شماره» پاسخ می‌دهد و شخص را وصل می‌کند.'),
                    Forms\Components\Toggle::make('deliver_via_bale')
                        ->label('ارسال پیام‌ها از بله برای افراد وصل')
                        ->helperText('خاموش: همه مثل قبل پیامک می‌گیرند.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $state['bot_username'] = filled($state['bot_username'] ?? null) ? ltrim($state['bot_username'], '@') : null;
        BaleSetting::current()->fill($state)->save();

        Notification::make()->title('ذخیره شد.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('makeWebhook')
                ->label('ساخت نشانی وب‌هوک')
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->modalDescription('نشانی تازه ساخته می‌شود و نشانی قبلی دیگر کار نمی‌کند. چیزی در بله ثبت یا فرستاده نمی‌شود.')
                ->action(function () {
                    BaleSetting::current()->forceFill(['webhook_secret' => Str::random(48)])->save();
                    Notification::make()->title('نشانی وب‌هوک ساخته شد.')->success()->send();
                }),
        ];
    }

    /** پیوند وصل‌شدن یک نفر را برمی‌دارد تا دوباره شماره‌اش را بفرستد. */
    public function unlink(string $type, int $id): void
    {
        $class = BaleLink::TYPES[$type] ?? null;
        abort_if($class === null, 404);

        $query = $class::query();
        if ($type === 'user') {
            $query->ofCurrentBakery();
        }
        $query->findOrFail($id)->forceFill(['bale_chat_id' => null, 'bale_linked_at' => null])->save();

        Notification::make()->title('اتصال برداشته شد.')->success()->send();
    }

    public function getInviteUrl(): ?string
    {
        return BaleLink::inviteUrl(BaleSetting::current());
    }

    public function getInviteQrSvg(): ?string
    {
        $url = $this->getInviteUrl();
        if ($url === null) {
            return null;
        }

        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
    }

    public function getWebhookUrl(): ?string
    {
        $secret = BaleSetting::current()->webhook_secret;

        return $secret ? route('bale.webhook', ['secret' => $secret]) : null;
    }

    /** @return array<string, array{label: string, rows: array}> */
    public function getGroups(): array
    {
        $labels = ['customer' => 'همکارها و مشتری‌ها', 'user' => 'کارکنان', 'supplier' => 'تأمین‌کننده‌ها'];
        $groups = [];
        foreach (BaleLink::TYPES as $type => $class) {
            $query = $class::query()->where('is_active', true)->orderBy('name');
            if ($type === 'user') {
                $query->ofCurrentBakery();
            }
            $groups[$type] = [
                'label' => $labels[$type],
                'rows' => $query->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'has_phone' => Sms::normalise($p->phone) !== null,
                    'linked' => filled($p->bale_chat_id),
                    'linked_at' => $p->bale_linked_at,
                ])->all(),
            ];
        }

        return $groups;
    }
}
