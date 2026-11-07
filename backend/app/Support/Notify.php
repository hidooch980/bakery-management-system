<?php

namespace App\Support;

use App\Models\BaleSetting;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * درِ مشترک پیام‌های خودکار به افراد: اول بله، اگر شخص به ربات وصل نیست
 * پیامک.
 *
 * - کلید همان نوع پیام خاموش باشد، هیچ چیز نمی‌رود.
 * - شخص وصل و «ارسال از بله» روشن: فقط بله. اگر بله خطا داد، پیامکِ
 *   هزینه‌دار جایش نمی‌رود؛ خطا ثبت می‌شود تا مدیر تصمیم بگیرد.
 * - شخص وصل نیست: همان پیامک قبلی از AutoSms.
 *
 * کد بازیابی رمز از این‌جا نمی‌گذرد و همیشه پیامک می‌ماند.
 */
class Notify
{
    public static function person(
        string $kind,
        Model $person,
        string $message,
        bool $enabled,
        ?User $by = null,
    ): SmsLog {
        $name = $person->getAttribute('name');
        $phone = $person->getAttribute('phone');
        $chatId = $person->getAttribute('bale_chat_id');

        if ($enabled && filled($chatId)) {
            $bale = BaleSetting::current();
            if ($bale->deliver_via_bale && filled($bale->bot_token)) {
                return self::viaBale($kind, $name, $phone, (string) $chatId, $message, $bale, $by);
            }
        }

        return AutoSms::send($kind, $name, $phone, $message, $enabled, $by);
    }

    private static function viaBale(
        string $kind, ?string $name, ?string $phone, string $chatId, string $message, BaleSetting $bale, ?User $by,
    ): SmsLog {
        $result = BaleNotifier::sendMessage($bale->bot_token, $chatId, $message);

        return SmsLog::create([
            'kind' => $kind,
            'recipient_name' => $name,
            'phone_masked' => SmsLog::mask($phone),
            'message' => $message,
            'channel' => 'bale',
            'status' => $result['ok'] ? 'sent' : 'failed',
            // متن خام خطا ممکن است نشانی همراه توکن را داشته باشد.
            'error' => $result['ok'] ? null : 'بله پیام را نپذیرفت؛ پیامک جایگزین فرستاده نشد.',
            'sent_by' => $by?->id,
        ]);
    }
}
