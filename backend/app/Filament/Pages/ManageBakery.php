<?php

namespace App\Filament\Pages;

use App\Models\Bakery;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageBakery extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'اطلاعات نانوایی';

    protected static ?string $title = 'اطلاعات نانوایی';

    protected static ?int $navigationSort = -10;

    protected static string $view = 'filament.pages.manage-bakery';

    public ?array $data = [];

    public function mount(): void
    {
        $bakery = Bakery::firstOrCreate(['id' => 1], ['name' => 'نانوایی من']);
        $this->form->fill($bakery->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('مشخصات نانوایی')
                    ->description('این اطلاعات در اپلیکیشن موبایل کارکنان نمایش داده می‌شود.')
                    ->icon('heroicon-o-building-storefront')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('نام نانوایی')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('تلفن')
                            ->tel()
                            ->maxLength(20),

                        Forms\Components\TextInput::make('address')
                            ->label('آدرس')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('logo')
                            ->label('لوگو')
                            ->image()
                            ->imageEditor()
                            ->directory('bakery')
                            ->maxSize(2048)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('توضیحات')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('تعاریف تولید و قیمت')
                    ->description('این مقادیر در اپلیکیشن برای پیش‌پر کردن وزن‌ها و پیشنهاد مبلغ فروش استفاده می‌شوند.')
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('normal_chane_weight_kg')
                            ->label('وزن هر چانه عادی')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.001)
                            ->suffix('کیلوگرم')
                            ->helperText('مثلاً ۰٫۴۳۰'),

                        Forms\Components\TextInput::make('nanino_chane_weight_kg')
                            ->label('وزن هر چانه نانینو')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.001)
                            ->suffix('کیلوگرم')
                            ->helperText('مثلاً ۰٫۳۸۰'),

                        \App\Filament\Forms\MoneyInput::make('bread_price', 'قیمت هر نان')
                            ->helperText('برای پیشنهاد مبلغ فروش در اپلیکیشن'),

                        Forms\Components\TextInput::make('flour_bag_weight_kg')
                            ->label('وزن هر کیسه آرد')
                            ->numeric()
                            ->minValue(0.1)
                            ->required()
                            ->suffix('کیلوگرم')
                            ->helperText('پایه فرمول تولید'),

                    ]),

                Forms\Components\Section::make('فرمول تولید خمیر')
                    ->description('سیستم از این نسبت‌ها تعداد و وزن چانه را خودکار حساب می‌کند؛ کارکنان نمی‌توانند وزن را دستی تغییر دهند.')
                    ->icon('heroicon-o-beaker')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('water_ratio')
                            ->label('نسبت آب به آرد')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->step(0.01)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('مثلاً ۰٫۶ یعنی ۶۰٪ وزن آرد'),

                        Forms\Components\TextInput::make('salt_ratio')
                            ->label('نسبت نمک به آرد')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.001)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('مثلاً ۰٫۰۱۵ یعنی ۱٫۵٪'),

                        Forms\Components\TextInput::make('dough_loss_ratio')
                            ->label('ضایعات و تبخیر')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(0.9)
                            ->step(0.001)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('مثلاً ۰٫۰۲ یعنی ۲٪'),

                        Forms\Components\Placeholder::make('formula_preview')
                            ->label('پیش‌نمایش برای یک کیسه')
                            ->columnSpanFull()
                            ->content(function (Forms\Get $get) {
                                $bag = (float) ($get('flour_bag_weight_kg') ?: 0);
                                $water = (float) ($get('water_ratio') ?: 0);
                                $salt = (float) ($get('salt_ratio') ?: 0);
                                $loss = (float) ($get('dough_loss_ratio') ?: 0);
                                $normal = (float) ($get('normal_chane_weight_kg') ?: 0);

                                if ($bag <= 0) {
                                    return 'ابتدا وزن کیسه را وارد کنید.';
                                }

                                $dough = ($bag + $bag * $water + $bag * $salt) * (1 - $loss);
                                $count = $normal > 0 ? floor($dough / $normal) : null;

                                return sprintf(
                                    'آرد %s + آب %s + نمک %s  ←  خمیر %s کیلوگرم%s',
                                    number_format($bag, 1),
                                    number_format($bag * $water, 1),
                                    number_format($bag * $salt, 2),
                                    number_format($dough, 2),
                                    $count !== null ? '  ←  حدود '.number_format($count).' چانه عادی' : ''
                                );
                            }),
                    ]),

                Forms\Components\Section::make('نمایش و واحدها')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('currency')
                            ->label('واحد پول نمایشی')
                            ->options(\App\Support\Money::UNITS)
                            ->default(\App\Support\Money::TOMAN)
                            ->required()
                            ->native(false)
                            ->helperText('مبالغ به تومان ذخیره و با این واحد نمایش داده می‌شوند.'),

                        Forms\Components\Select::make('calendar')
                            ->label('تقویم')
                            ->options(\App\Support\AppCalendar::OPTIONS)
                            ->default(\App\Support\AppCalendar::JALALI)
                            ->required()
                            ->native(false)
                            ->helperText('تاریخ‌ها در پنل و اپلیکیشن با این تقویم نمایش داده می‌شوند.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Bakery::firstOrNew(['id' => 1])->fill($data)->save();

        // The formatter caches the unit, so drop it after a settings change.
        \App\Support\Money::forgetCache();
        \App\Support\AppCalendar::forgetCache();

        Notification::make()
            ->title('اطلاعات نانوایی ذخیره شد.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('ذخیره تغییرات')
                ->submit('save')
                ->icon('heroicon-o-check'),
        ];
    }
}
