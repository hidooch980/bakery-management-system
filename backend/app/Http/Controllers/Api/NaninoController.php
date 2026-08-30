<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppCalendar;
use App\Support\CurrentBakery;
use App\Support\Nanino;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Connecting the shop to its own card terminal on nanino.
 *
 * This is the first half only: getting signed in. Reading the sales
 * history comes after somebody has proved a session can be obtained at
 * all — the token's place in nanino's answer is an informed guess until
 * then, and building the rest on a guess would mean building it twice.
 *
 * Signing in needs a captcha and an SMS code, both of which a person has
 * to supply. That is the point of a captcha, and nothing here tries to
 * get around it.
 */
class NaninoController extends Controller
{
    use ApiResponse;

    /** Whether the shop is connected, and what went wrong if not. */
    public function show(): JsonResponse
    {
        $bakery = CurrentBakery::get();

        return $this->success([
            'connected' => filled($bakery?->nanino_token),
            'connected_at_display' => $bakery?->nanino_connected_at
                ? AppCalendar::dateTime($bakery->nanino_connected_at)
                : null,
            'last_error' => $bakery?->nanino_last_error,
            // Prefilled so the owner is not typing his own national number
            // into a phone every time the session lapses.
            'mobile' => $bakery?->nanino_mobile,
            'national_number' => $bakery?->nanino_national_number,
        ]);
    }

    /** A fresh captcha for the person to read. */
    public function captcha(): JsonResponse
    {
        try {
            return $this->success(Nanino::captcha());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 502);
        }
    }

    /** Asks nanino to text the owner a code. */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'national_number' => ['required', 'string', 'max:20'],
            'access_key' => ['required', 'string'],
            'captcha' => ['required', 'string', 'max:20'],
        ]);

        try {
            Nanino::requestCode(
                $data['mobile'],
                $data['national_number'],
                $data['access_key'],
                $data['captcha'],
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success([], 'کد به گوشی شما ارسال شد.');
    }

    /** Exchanges the code for a session and keeps it. */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'national_number' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $bakery = CurrentBakery::get();

        if ($bakery === null) {
            return $this->error('نانوایی مشخص نیست.', 422);
        }

        try {
            Nanino::connect(
                $bakery,
                $data['mobile'],
                $data['national_number'],
                $data['code'],
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success(
            ['connected' => true],
            'به نانینو وصل شد.',
        );
    }

    /** Forgets the session. */
    public function disconnect(): JsonResponse
    {
        CurrentBakery::get()?->forceFill([
            'nanino_token' => null,
            'nanino_refresh_token' => null,
            'nanino_connected_at' => null,
            'nanino_last_error' => null,
        ])->save();

        return $this->success([], 'اتصال نانینو قطع شد.');
    }
}
