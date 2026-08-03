<?php

namespace App\Filament\Pages;

use App\Models\Bakery;
use App\Models\FlourAllocation;
use App\Support\DoughFormula;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

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
                            ->live(onBlur: true)
                            ->helperText('مثلاً ۰٫۳۸۰'),

                        Forms\Components\TextInput::make('chane_per_tray')
                            ->label('تعداد چانه در هر تشتک')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->suffix('عدد')
                            ->helperText('چانه‌گیر تشتک‌به‌تشتک ثبت می‌کند؛ این عدد پیش‌فرض هر تشتک است و تشتک آخر معمولاً کمتر می‌شود.'),

                        \App\Filament\Forms\MoneyInput::make('bread_price', 'قیمت هر نان')
                            ->helperText('برای پیشنهاد مبلغ فروش در اپلیکیشن'),

                        Forms\Components\TextInput::make('flour_bag_weight_kg')
                            ->label('وزن هر کیسه آرد')
                            ->numeric()
                            ->minValue(0.1)
                            ->required()
                            ->suffix('کیلوگرم')
                            ->helperText('پایه فرمول تولید'),

                        \App\Filament\Forms\MoneyInput::make('flour_price_per_kg', 'قیمت هر کیلو آرد')
                            ->helperText('برای فروش آرد به‌صورت کیلویی'),

                        \App\Filament\Forms\MoneyInput::make('flour_price_per_bag', 'قیمت هر کیسه آرد')
                            ->helperText('اگر خالی بماند، از قیمت کیلویی × وزن کیسه محاسبه می‌شود'),

                        \App\Filament\Forms\MoneyInput::make('flour_purchase_price_per_kg', 'قیمت خرید هر کیلو آرد از کارخانه')
                            ->helperText('برای محاسبه بهای تمام‌شده؛ جدا از قیمت فروش آرد به مشتری'),

                        Forms\Components\Toggle::make('flour_transport_by_factory')
                            ->label('حمل توسط کارخانه')
                            ->default(true)
                            ->helperText('اگر خاموش باشد، هزینه حمل بر عهده نانوایی است و باید جدا در «هزینه‌ها» ثبت شود'),

                        Forms\Components\TimePicker::make('chane_start_deadline')
                            ->label('مهلت شروع چانه‌گیری')
                            ->seconds(false)
                            ->default('05:40')
                            ->helperText('ثبت بعد از این ساعت، تأخیر و مشمول کسر حقوق است'),

                        Forms\Components\TimePicker::make('baking_start_deadline')
                            ->label('مهلت شروع پخت')
                            ->seconds(false)
                            ->default('06:00')
                            ->helperText('ثبت بعد از این ساعت، تأخیر و مشمول کسر حقوق است'),

                    ]),

                Forms\Components\Section::make('قوانین تأخیر و کسر حقوق')
                    ->description('این قوانین به همه کارکنان در اپلیکیشن اعلام می‌شود. جریمه به‌ازای هر «روز» تأخیر است، نه هر مورد؛ اگر در یک روز هم چانه‌گیری و هم پخت دیر شود، یک بار حساب می‌شود.')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('late_free_days')
                            ->label('تعداد تأخیر بدون جریمه در ماه')
                            ->numeric()
                            ->minValue(0)
                            ->default(3)
                            ->suffix('روز')
                            ->helperText('تا این تعداد فقط اخطار داده می‌شود'),

                        Forms\Components\TextInput::make('late_tier1_last_day')
                            ->label('آخرین روز مشمول نرخ اول')
                            ->numeric()
                            ->minValue(1)
                            ->default(7)
                            ->suffix('روز')
                            ->helperText('مثلاً ۷ یعنی روزهای ۴ تا ۷'),

                        \App\Filament\Forms\MoneyInput::make('late_tier1_amount', 'جریمه روزانه — نرخ اول')
                            ->helperText('برای روزهای بعد از اخطارها'),

                        \App\Filament\Forms\MoneyInput::make('late_tier2_amount', 'جریمه روزانه — نرخ دوم')
                            ->helperText('برای روزهای بعد از نرخ اول'),

                        Forms\Components\Placeholder::make('late_rules_preview')
                            ->label('خلاصه قانون')
                            ->columnSpanFull()
                            ->content(fn () => \App\Support\LatePenalty::tariff()['summary']),
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
                            ->helperText('مثلاً ۰٫۰۱۷۵ یعنی ۷۰۰ گرم در کیسه ۴۰ کیلویی'),

                        Forms\Components\TextInput::make('yeast_ratio')
                            ->label('نسبت خمیرمایه به آرد')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.00001)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('مثلاً ۰٫۰۰۵ یعنی ۲۰۰ گرم در کیسه ۴۰ کیلویی'),

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
                                $nanino = (float) ($get('nanino_chane_weight_kg') ?: 0);

                                if ($bag <= 0) {
                                    return 'ابتدا وزن کیسه را وارد کنید.';
                                }

                                $dough = ($bag + $bag * $water + $bag * $salt) * (1 - $loss);
                                $count = $normal > 0 ? floor($dough / $normal) : null;
                                $naninoCount = $nanino > 0 ? floor($dough / $nanino) : null;

                                // Shaping spends the dough it was given, so
                                // the line says what each chane costs and
                                // what a whole bag's worth is used for —
                                // the figures the warehouse is deducted by.
                                $consumption = '';

                                if ($count !== null) {
                                    $used = $count * $normal;
                                    $leftOver = $dough - $used;

                                    $consumption = sprintf(
                                        '<br>مصرف خمیر: هر چانه %s کیلوگرم  •  %s چانه = %s کیلوگرم%s',
                                        number_format($normal, 3),
                                        number_format($count),
                                        number_format($used, 2),
                                        // Dough short of a whole chane is not
                                        // waste; it is what the next batch is
                                        // started with, so it is named rather
                                        // than left as a gap in the numbers.
                                        $leftOver >= 0.005
                                            ? '  •  باقیمانده '.number_format($leftOver, 2).' کیلوگرم'
                                            : ''
                                    );
                                }

                                return sprintf(
                                    'آرد %s + آب %s + نمک %s  ←  خمیر %s کیلوگرم%s%s%s',
                                    number_format($bag, 1),
                                    number_format($bag * $water, 1),
                                    number_format($bag * $salt, 2),
                                    number_format($dough, 2),
                                    $count !== null ? '  ←  حدود '.number_format($count).' چانه عادی' : '',
                                    $naninoCount !== null ? '  یا  حدود '.number_format($naninoCount).' چانه نانینو' : '',
                                    $consumption
                                );
                            }),

                        Forms\Components\Placeholder::make('period_preview')
                            ->label('پیش‌نمایش دوره‌های سهمیه این ماه')
                            ->columnSpanFull()
                            // The same formula applied to the quota actually
                            // registered, so the admin sees what a month of
                            // flour turns into rather than only one bag.
                            ->content(function (Forms\Get $get) {
                                $bag = (float) ($get('flour_bag_weight_kg') ?: 0);

                                if ($bag <= 0) {
                                    return 'ابتدا وزن کیسه را وارد کنید.';
                                }

                                $allocation = FlourAllocation::forJalaliMonthOf(now());

                                if (! $allocation) {
                                    return 'برای این ماه سهمیه‌ای ثبت نشده است.';
                                }

                                $formula = new DoughFormula(
                                    bagWeightKg: $bag,
                                    waterRatio: (float) ($get('water_ratio') ?: 0),
                                    saltRatio: (float) ($get('salt_ratio') ?: 0),
                                    yeastRatio: (float) ($get('yeast_ratio') ?: 0),
                                    lossRatio: (float) ($get('dough_loss_ratio') ?: 0),
                                    normalChaneWeightKg: ((float) $get('normal_chane_weight_kg')) ?: null,
                                    naninoChaneWeightKg: ((float) $get('nanino_chane_weight_kg')) ?: null,
                                );

                                $lines = [];
                                $totalBags = 0.0;

                                foreach ($allocation->periods as $period) {
                                    $bags = $allocation->bagsForPeriod($period);
                                    $totalBags += $bags;
                                    $lines[] = self::previewLine($period->label, $bags, $formula);
                                }

                                $lines[] = self::previewLine('سرجمع ماه', $totalBags, $formula);

                                return new HtmlString(implode('<br>', $lines));
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

    /** One "N bags → dough → chane" line of the period preview. */
    private static function previewLine(string $label, float $bags, DoughFormula $formula): string
    {
        $normal = $formula->normalChaneCount($bags);
        $nanino = $formula->naninoChaneCount($bags);

        return sprintf(
            '<b>%s</b>: %s کیسه  ←  خمیر %s کیلوگرم%s%s',
            e($label),
            number_format($bags, 1),
            number_format($formula->doughKg($bags), 1),
            $normal !== null ? '  ←  حدود '.number_format($normal).' چانه عادی' : '',
            $nanino !== null ? '  یا  حدود '.number_format($nanino).' چانه نانینو' : '',
        );
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
