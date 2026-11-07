<?php

namespace App\Support;

use App\Models\BaleSetting;
use App\Models\BaleUpdate;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * وصل‌کردن حساب بلهٔ یک همکار، کارمند یا تأمین‌کننده به رکوردش.
 *
 * فقط کارت تماسِ خودِ فرستنده در چت خصوصی پذیرفته می‌شود
 * (contact.user_id == from.id)، و فقط وقتی شماره دقیقاً به یک نفر در
 * همین نانوایی بخورد. هر حالت دیگری بسته می‌ماند تا مدیر تصمیم بگیرد —
 * وصل‌شدن به رکورد اشتباه یعنی رفتن بدهی یک نفر برای دیگری.
 */
class BaleLink
{
    public const TYPES = [
        'customer' => Customer::class,
        'user' => User::class,
        'supplier' => Supplier::class,
    ];

    public const REPLIES = [
        'prompted' => 'سلام! برای دریافت پیام‌های نانوایی در بله، دکمهٔ «ارسال شماره» را بزنید.',
        'linked' => 'شمارهٔ شما ثبت شد. از این پس پیام‌های نانوایی را در بله دریافت می‌کنید.',
        'already_linked' => 'حساب بلهٔ شما از قبل وصل است.',
        'foreign_contact' => 'فقط شمارهٔ خودتان را با دکمهٔ «ارسال شماره» بفرستید.',
        'invalid_phone' => 'این شماره معتبر نیست. شمارهٔ همراه ایران لازم است.',
        'not_found' => 'این شماره در فهرست نانوایی نیست. لطفاً با مدیر نانوایی تماس بگیرید.',
        'ambiguous' => 'این شماره برای بیش از یک نفر ثبت شده. لطفاً با مدیر نانوایی تماس بگیرید.',
        'taken' => 'این شماره به حساب بلهٔ دیگری وصل است. برای تغییر با مدیر نانوایی تماس بگیرید.',
        'chat_in_use' => 'این حساب بله به نفر دیگری وصل است. لطفاً با مدیر نانوایی تماس بگیرید.',
    ];

    /**
     * یک آپدیت بله را پردازش می‌کند و نتیجه را برمی‌گرداند.
     * پاسخ به خود فرستنده فقط وقتی می‌رود که پذیرش اتصال روشن باشد.
     */
    public static function handle(BaleSetting $settings, array $update): string
    {
        if (! $settings->link_enabled) {
            return 'disabled';
        }

        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId)) {
            return 'ignored';
        }

        return CurrentBakery::for((int) $settings->bakery_id, function () use ($settings, $update, $updateId) {
            try {
                $row = BaleUpdate::create(['update_id' => $updateId, 'outcome' => 'processing']);
            } catch (QueryException) {
                // همین آپدیت پیش‌تر رسیده؛ دوباره پاسخ نمی‌دهیم.
                return 'duplicate';
            }

            [$outcome, $chatId] = self::decide($update);
            $row->update(['outcome' => $outcome]);

            if ($chatId !== null && isset(self::REPLIES[$outcome]) && filled($settings->bot_token)) {
                $markup = in_array($outcome, ['prompted', 'foreign_contact'], true) ? self::contactKeyboard() : null;
                $sent = BaleNotifier::sendMessage($settings->bot_token, $chatId, self::REPLIES[$outcome], $markup);
                if (! $sent['ok']) {
                    // متن خطا ممکن است نشانی همراه توکن داشته باشد؛ ثبت نمی‌شود.
                    logger()->warning('پاسخ ربات بله ارسال نشد.', ['outcome' => $outcome]);
                }
            }

            return $outcome;
        });
    }

    /** @return array{0: string, 1: ?string} نتیجه و چتی که باید پاسخ بگیرد */
    private static function decide(array $update): array
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private') {
            // گروه و کانال پاسخی نمی‌گیرند.
            return ['ignored', null];
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $fromId = $message['from']['id'] ?? null;
        if ($chatId === '' || $fromId === null) {
            return ['ignored', null];
        }

        $contact = $message['contact'] ?? null;
        if (! is_array($contact)) {
            return ['prompted', $chatId];
        }

        if (! isset($contact['user_id']) || (string) $contact['user_id'] !== (string) $fromId) {
            return ['foreign_contact', $chatId];
        }

        $phone = Sms::normalise(isset($contact['phone_number']) ? (string) $contact['phone_number'] : null);
        if ($phone === null) {
            return ['invalid_phone', $chatId];
        }

        return [self::link($phone, $chatId), $chatId];
    }

    private static function link(string $phone, string $chatId): string
    {
        return DB::transaction(function () use ($phone, $chatId) {
            $matches = self::matching($phone);
            if ($matches->isEmpty()) {
                return 'not_found';
            }
            if ($matches->count() > 1) {
                return 'ambiguous';
            }

            /** @var Model $person */
            $person = $matches->first();
            if (filled($person->bale_chat_id)) {
                return $person->bale_chat_id === $chatId ? 'already_linked' : 'taken';
            }

            if (self::linkedTo($chatId)->isNotEmpty()) {
                return 'chat_in_use';
            }

            $person->forceFill(['bale_chat_id' => $chatId, 'bale_linked_at' => now()])->save();

            return 'linked';
        });
    }

    /** همهٔ افراد همین نانوایی با این شماره، در سه نوع. */
    public static function matching(string $phone): Collection
    {
        return self::people()->filter(fn (Model $p) => Sms::normalise($p->phone) === $phone)->values();
    }

    private static function linkedTo(string $chatId): Collection
    {
        return self::people()->filter(fn (Model $p) => $p->bale_chat_id === $chatId)->values();
    }

    /** افراد نانوایی جاری؛ کاربرها جدا محدود می‌شوند چون دامنهٔ سراسری ندارند. */
    public static function people(): Collection
    {
        return collect()
            ->concat(Customer::query()->lockForUpdate()->get())
            ->concat(User::query()->ofCurrentBakery()->lockForUpdate()->get())
            ->concat(Supplier::query()->lockForUpdate()->get());
    }

    public static function contactKeyboard(): array
    {
        return [
            'keyboard' => [[['text' => 'ارسال شماره', 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    public static function inviteUrl(BaleSetting $settings): ?string
    {
        $username = ltrim((string) $settings->bot_username, '@');

        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,63}$/', $username) === 1 ? 'https://ble.ir/'.$username : null;
    }
}
