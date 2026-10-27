<?php

namespace App\Support;

use App\Models\BaleSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** ارسال به شماره تلفن با سفیر؛ مستقل از ربات پشتیبان شبانه. */
class BaleSafir
{
    public static function send(BaleSetting $setting, string $phone, string $message, string $requestId): string
    {
        if (blank($setting->safir_api_key) || (int) $setting->safir_bot_id <= 0) {
            return 'failed';
        }

        $phone = Sms::normalise($phone);
        if ($phone === null) {
            return 'failed';
        }

        try {
            $response = Http::timeout(15)->withHeaders(['api-access-key' => $setting->safir_api_key])
                ->acceptJson()->post('https://safir.bale.ai/api/v3/send_message', [
                    'request_id' => $requestId,
                    'bot_id' => (int) $setting->safir_bot_id,
                    'phone_number' => '98'.substr($phone, 1),
                    'message_data' => ['message' => ['text' => $message]],
                ]);

            $id = $response->json('message_id');
            $errors = $response->json('error_data');
            if ($response->successful() && is_string($id) && $id !== '' && empty($errors)) {
                return 'sent';
            }

            // فقط عدم عضویت صریح همان شماره اجازهٔ مسیر جایگزین می‌دهد.
            if ($response->successful() && blank($id) && is_array($errors) && count($errors) === 1) {
                $error = array_values($errors)[0];
                if (is_array($error) && (int) ($error['code'] ?? 0) === 17
                    && Sms::normalise($error['phone_number'] ?? null) === $phone) {
                    return 'not_member';
                }
            }
        } catch (\Throwable $e) {
            // متن خطا ممکن است کلید داشته باشد؛ ارسال مجدد با شناسهٔ یکسان انجام می‌شود.
            Log::warning('پاسخ قطعی ارسال گزارش بله دریافت نشد.', ['type' => $e::class]);

            return 'unknown';
        }

        return 'failed';
    }
}
