<?php

namespace App\Filament\Pages;

use App\Exceptions\DuplicateRecord;
use App\Models\BankAccount;
use App\Models\BankSms;
use App\Models\BankSmsAccount;
use App\Models\Expense;
use App\Models\Income;
use App\Support\BankSmsBook;
use App\Support\BankSmsMatcher;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * «پیامک‌های بانکی»: the same inbox the owner's phone shows, and the
 * table that ties «حساب …۷۸۹۰» to one of the shop's accounts.
 *
 * Every button goes through BankSmsBook, the code the app's API uses, so
 * an SMS booked here and one booked on the phone are the same record.
 */
class BankSmsInbox extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'امور مالی';

    protected static ?string $navigationLabel = 'پیامک‌های بانکی';

    protected static ?string $title = 'پیامک‌های بانکی';

    protected static string $view = 'filament.pages.bank-sms-inbox';

    protected static ?string $slug = 'bank-sms';

    /** @var array<int, string> chosen category per SMS id */
    public array $category = [];

    /** @var array<int, ?int> chosen account per SMS id */
    public array $account = [];

    /** @var array<int, bool> «بله، جداست» after a twin was named */
    public array $force = [];

    public ?string $newLast4 = null;

    public ?int $newAccount = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        $open = BankSms::query()->whereIn('status', BankSms::OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        BankSmsBook::reopenOrphans();
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $open = BankSms::query()->with(['account', 'transaction.source'])
            ->whereIn('status', BankSms::OPEN)->latest('received_at')->limit(200)->get();
        $done = BankSms::query()->with(['account', 'transaction.source'])
            ->whereNotIn('status', BankSms::OPEN)->latest('received_at')->limit(50)->get();

        foreach ($open as $sms) {
            $this->category[$sms->id] ??= $sms->suggested_category ?? 'other';
        }

        return $open->concat($done)->map(fn ($s) => BankSmsBook::present($s))->all();
    }

    public function categoriesFor(string $direction): array
    {
        return $direction === 'in' ? Income::CATEGORIES : Expense::CATEGORIES;
    }

    public function accounts(): array
    {
        return BankAccount::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function mappings(): array
    {
        return BankSmsAccount::query()->with('account')->orderBy('last4')->get()
            ->map(fn ($m) => ['id' => $m->id, 'last4' => $m->last4, 'account' => $m->account?->title])->all();
    }

    /** @return array<int, string> digits seen in SMS and tied to nothing */
    public function unmapped(): array
    {
        return BankSms::query()->whereNotNull('last4')
            ->whereNotIn('last4', BankSmsAccount::query()->select('last4'))
            ->distinct()->pluck('last4')->all();
    }

    public function confirm(int $id): void
    {
        $sms = BankSms::findOrFail($id);

        try {
            BankSmsBook::confirm($sms, auth()->user(),
                $this->category[$id] ?? $sms->suggested_category ?? 'other',
                ($this->account[$id] ?? null) ? (int) $this->account[$id] : null,
                null, (bool) ($this->force[$id] ?? false));
        } catch (DuplicateRecord $e) {
            $this->force[$id] = true;
            Notification::make()->title('همین مبلغ امروز ثبت شده: '.$e->twin->describe())
                ->body('اگر واقعاً جداست، دوباره «ثبت» را بزنید.')->warning()->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        Notification::make()->title($sms->direction === 'in' ? 'درآمد ثبت شد.' : 'هزینه ثبت شد.')->success()->send();
    }

    public function ignore(int $id): void
    {
        try {
            BankSmsBook::ignore(BankSms::findOrFail($id), auth()->user());
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }
        Notification::make()->title('نادیده گرفته شد.')->success()->send();
    }

    public function link(int $id, int $transactionId): void
    {
        try {
            BankSmsBook::link(BankSms::findOrFail($id), auth()->user(), $transactionId);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }
        Notification::make()->title('به ثبت قبلی وصل شد.')->success()->send();
    }

    public function saveMapping(): void
    {
        $this->validate([
            'newLast4' => ['required', 'digits:4'],
            'newAccount' => ['required', 'integer', 'exists:bank_accounts,id'],
        ], [], ['newLast4' => 'چهار رقم آخر', 'newAccount' => 'حساب']);

        BankSmsAccount::query()->updateOrCreate(['last4' => $this->newLast4], ['bank_account_id' => $this->newAccount]);

        BankSms::query()->whereIn('status', BankSms::OPEN)->where('last4', $this->newLast4)->get()
            ->each(function (BankSms $sms) {
                $sms->forceFill(['bank_account_id' => $this->newAccount])->save();
                BankSmsMatcher::settle($sms);
            });

        $this->reset('newLast4', 'newAccount');
        Notification::make()->title('حساب وصل شد.')->success()->send();
    }

    public function useDigits(string $last4): void
    {
        $this->newLast4 = $last4;
    }

    public function deleteMapping(int $id): void
    {
        BankSmsAccount::query()->whereKey($id)->delete();
        Notification::make()->title('وصل حساب برداشته شد.')->success()->send();
    }
}
