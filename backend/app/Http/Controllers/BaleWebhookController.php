<?php

namespace App\Http\Controllers;

use App\Models\BaleSetting;
use App\Support\BaleLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * دریافت آپدیت‌های ربات بله از راه وب‌هوک.
 *
 * بخش مخفی نشانی، نانوایی را مشخص می‌کند. تا وقتی مدیر وب‌هوک را در بله
 * ثبت نکند هیچ آپدیتی به این‌جا نمی‌رسد، و تا «پذیرش اتصال» خاموش است
 * هیچ کاری انجام نمی‌شود.
 */
class BaleWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        if (strlen($secret) < 32) {
            abort(404);
        }

        $settings = BaleSetting::query()->acrossBakeries()->where('webhook_secret', $secret)->first();
        if ($settings === null || ! hash_equals((string) $settings->webhook_secret, $secret)) {
            abort(404);
        }

        $outcome = BaleLink::handle($settings, $request->json()->all());

        // همیشه ۲۰۰، تا بله یک آپدیت ردشده را بارها دوباره نفرستد.
        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }
}
