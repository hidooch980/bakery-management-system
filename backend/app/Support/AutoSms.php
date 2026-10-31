<?php

namespace App\Support;

use App\Models\SmsLog;
use App\Models\User;

/**
 * The one door every automatic SMS goes through: it checks the switch for
 * that kind of message, sends through the shop's existing provider and
 * writes the outcome to the log with the number masked.
 */
class AutoSms
{
    public static function send(
        string $kind,
        ?string $name,
        ?string $phone,
        string $message,
        bool $enabled,
        ?User $by = null,
    ): SmsLog {
        $log = fn (string $status, ?string $error = null) => SmsLog::create([
            'kind' => $kind,
            'recipient_name' => $name,
            'phone_masked' => SmsLog::mask($phone),
            'message' => $message,
            'channel' => 'sms',
            'status' => $status,
            'error' => $error,
            'sent_by' => $by?->id,
        ]);

        if (! $enabled) {
            return $log('skipped', 'این نوع پیامک در تنظیمات خاموش است.');
        }

        if (Sms::normalise($phone) === null) {
            return $log('skipped', 'شمارهٔ همراه ثبت نشده یا نادرست است.');
        }

        try {
            $ok = Sms::sendReport((string) $phone, $message);
        } catch (\Throwable $e) {
            report($e);

            return $log('failed', mb_substr($e->getMessage(), 0, 250));
        }

        return $log($ok ? 'sent' : 'failed', $ok ? null : 'سرویس پیامک پیام را نپذیرفت.');
    }
}
