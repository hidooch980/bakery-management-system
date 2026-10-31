<?php

namespace App\Filament\Pages;

use App\Models\NotificationSetting;
use App\Models\SmsLog;
use App\Support\AppCalendar;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * «پیامک‌ها و هشدارها»: one switch per kind of automatic message, every one
 * off until the owner turns it on, and the log of what went where — with
 * each number masked.
 */
class ManageNotifications extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'پیامک‌ها و هشدارها';

    protected static ?string $title = 'پیامک‌ها و هشدارها';

    protected static ?int $navigationSort = -7;

    protected static string $view = 'filament.pages.manage-notifications';

    public ?array $data = [];

    public function mount(): void
    {
        $s = NotificationSetting::current();
        $this->form->fill([
            ...$s->toArray(),
            'seller_debt_threshold' => $s->seller_debt_threshold === null ? null : Money::convert((float) $s->seller_debt_threshold),
            'card_reader_tolerance' => Money::convert((float) $s->card_reader_tolerance),
        ]);
    }

    public function form(Form $form): Form
    {
        $unit = Money::label();

        return $form->schema([
            Forms\Components\Section::make('پیامک')
                ->description('از همان سامانهٔ پیامک فعلی (SMS.ir) فرستاده می‌شود. هر نوع جداست و همه خاموش شروع می‌شوند.')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->schema([
                    Forms\Components\Toggle::make('sms_partner_weekly')
                        ->label('خلاصهٔ هفتگی حساب آرد به همکارها (شنبه‌ها ساعت ۹، به کیسه)')
                        ->helperText('فقط برای همکارهایی که شمارهٔ همراه دارند و حسابشان صفر نیست. دکمهٔ «ارسال» دستی همیشه فعال است.'),
                    Forms\Components\Toggle::make('sms_staff_penalty')
                        ->label('پیامک به پرسنل وقتی جریمه یا اخطار تأیید یا لغو می‌شود'),
                ]),
            Forms\Components\Section::make('هشدار بدهی فروشنده')
                ->description('اگر ماندهٔ فروشنده از این مبلغ بیشتر شود، هر روز ساعت ۱۰ در بله به مدیر خبر داده می‌شود و فروشنده در اپ هشدار می‌بیند.')
                ->icon('heroicon-o-exclamation-triangle')
                ->schema([
                    Forms\Components\TextInput::make('seller_debt_threshold')
                        ->label("سقف بدهی ({$unit})")
                        ->numeric()->minValue(0)
                        ->helperText('خالی یعنی هشدار خاموش.'),
                ]),
            Forms\Components\Section::make('یادآوری نسیهٔ مدارس و مشتری‌ها')
                ->description('نسیهٔ قدیمی‌تر از این تعداد روز، یا مشتری بالای سقف اعتبارش، هر روز در بله یادآوری می‌شود. سقف اعتبار هر مشتری در پروندهٔ همان مشتری است.')
                ->icon('heroicon-o-academic-cap')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('credit_reminder_enabled')->label('یادآوری روشن'),
                    Forms\Components\TextInput::make('credit_reminder_days')
                        ->label('قدیمی‌تر از (روز)')->numeric()->minValue(1)->maxValue(365)->default(30),
                ]),
            Forms\Components\Section::make('مقایسهٔ کارتخوان با پیامک بانک')
                ->description('هر روز ساعت ۱۱ فروش کارتخوانِ ثبت‌شدهٔ دیروز با واریز کارتخوان (که روز بعد پیامکش می‌رسد) مقایسه می‌شود و اگر اختلاف بیش از این مقدار باشد در بله خبر داده می‌شود.')
                ->icon('heroicon-o-credit-card')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('card_reader_check_enabled')->label('مقایسه روشن'),
                    Forms\Components\TextInput::make('card_reader_tolerance')
                        ->label("اختلاف قابل چشم‌پوشی ({$unit})")->numeric()->minValue(0),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $state['seller_debt_threshold'] = filled($state['seller_debt_threshold'] ?? null)
            ? Money::toToman($state['seller_debt_threshold']) : null;
        $state['card_reader_tolerance'] = Money::toToman($state['card_reader_tolerance'] ?? 0);

        NotificationSetting::current()->fill($state)->save();

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('ذخیره تغییرات')->submit('save')->icon('heroicon-o-check'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('گزارش ارسال‌ها')
            ->query(SmsLog::query()->with('sender:id,name'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('زمان')
                    ->formatStateUsing(fn ($state) => AppCalendar::dateTime($state)),
                Tables\Columns\TextColumn::make('kind')->label('نوع')
                    ->formatStateUsing(fn ($state) => SmsLog::KIND_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('recipient_name')->label('گیرنده'),
                Tables\Columns\TextColumn::make('phone_masked')->label('شماره'),
                Tables\Columns\TextColumn::make('channel')->label('راه')
                    ->formatStateUsing(fn ($state) => $state === 'bale' ? 'بله' : 'پیامک'),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn ($state) => SmsLog::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('error')->label('توضیح')->wrap()->limit(80),
                Tables\Columns\TextColumn::make('message')->label('متن')->wrap()->limit(120)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('نوع')->options(SmsLog::KIND_LABELS),
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(SmsLog::STATUS_LABELS),
            ])
            ->emptyStateHeading('هنوز پیامی ثبت نشده است.');
    }
}
