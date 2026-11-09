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
 * - شخص وصل و «ارسال از بله» روشن: بله. اگر بله خطا داد، خطا ثبت و
 *   همان پیام یک بار با پیامک فرستاده می‌شود (تصمیم مدیر).
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
        ?string $smsMessage = null,
    ): SmsLog {
        $smsMessage ??= $message;
        $name = $person->getAttribute('name');
        $phone = $person->getAttribute('phone');
        $chatId = $person->getAttribute('bale_chat_id');

        if ($enabled && filled($chatId)) {
            $bale = BaleSetting::current();
            if ($bale->deliver_via_bale && filled($bale->bot_token)) {
                $log = self::viaBale($kind, $name, $phone, (string) $chatId, $message, $bale, $by);
                if ($log->status === 'sent') {
                    return $log;
                }
                // بله نرسید؛ پیامک جایگزین، یک بار.
            }
        }

        return AutoSms::send($kind, $name, $phone, $smsMessage, $enabled, $by);
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
            'error' => $result['ok'] ? null : 'بله پیام را نپذیرفت؛ پیامک جایگزین فرستاده شد.',
            'sent_by' => $by?->id,
        ]);
    }
}
