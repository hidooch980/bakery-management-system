<?php

namespace App\Support;

use App\Models\Bakery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reading the shop's own card-terminal history from nanino.
 *
 * The card reader is what the flour quota is measured against, and its
 * figures reached this system by somebody opening another website and
 * typing them in. This reads them.
 *
 * **Signing in is deliberately not automated.** nanino asks for a captcha
 * and an SMS code, and a captcha exists precisely to require a person.
 * So the person supplies both: this fetches the captcha image and hands
 * it to the app to show, and posts back what they typed. Nothing here
 * solves one, and nothing here should ever be changed to.
 *
 * There is no password to store, because nanino does not use one. What is
 * kept is a session token, encrypted, and it expires — when it does the
 * shop is told to sign in again rather than shown a stale figure as
 * though it were current.
 *
 * These are nanino's own internal endpoints, not a published API. They
 * can change without warning. Every failure here is reported, never
 * swallowed: a link that has quietly stopped working is worse than one
 * that is plainly down.
 */
class Nanino
{
    public const BASE = 'https://business.nanino.ir';

    private const TIMEOUT = 20;

    /**
     * A fresh captcha for the sign-in form.
     *
     * @return array{image: string, access_key: string}
     */
    public static function captcha(): array
    {
        $response = Http::timeout(self::TIMEOUT)->get(self::BASE.'/api/captcha');

        if (! $response->successful()) {
            throw new RuntimeException('نانینو پاسخ نداد. بعداً دوباره امتحان کنید.');
        }

        return [
            'image' => (string) $response->json('encodedImage'),
            'access_key' => (string) $response->json('accessKey'),
        ];
    }

    /**
     * Asks nanino to text the owner a code.
     *
     * The captcha the person read is passed straight through. If it was
     * wrong nanino says so, and they get a new one.
     */
    public static function requestCode(
        string $mobile,
        string $nationalNumber,
        string $accessKey,
        string $captcha,
    ): void {
        $response = Http::timeout(self::TIMEOUT)->post(self::BASE.'/api/otp/generate', [
            'mobile' => $mobile,
            'nationalNumber' => $nationalNumber,
            'accessKey' => $accessKey,
            'captcha' => $captcha,
            'userType' => 'MERCHANT',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'کد ارسال نشد. کد امنیتی یا شمارهٔ همراه را بررسی کنید.'
            );
        }
    }

    /**
     * Exchanges the code the owner typed for a session, and keeps it.
     */
    public static function connect(
        Bakery $bakery,
        string $mobile,
        string $nationalNumber,
        string $code,
    ): void {
        $response = Http::timeout(self::TIMEOUT)->post(self::BASE.'/api/otp/validate', [
            'mobile' => $mobile,
            'nationalNumber' => $nationalNumber,
            'otp' => $code,
            'userType' => 'MERCHANT',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('کد وارد شده درست نبود.');
        }

        $token = self::tokenFrom($response->json());

        if ($token === null) {
            // Better to refuse than to record a connection that will fail
            // on its first read with something less legible than this.
            throw new RuntimeException('نانینو نشستی برنگرداند. ساختار پاسخ عوض شده است.');
        }

        $bakery->forceFill([
            'nanino_mobile' => $mobile,
            'nanino_national_number' => $nationalNumber,
            'nanino_token' => $token,
            'nanino_refresh_token' => self::refreshFrom($response->json()),
            'nanino_connected_at' => now(),
            'nanino_last_error' => null,
        ])->save();
    }

    /**
     * Completed orders between two dates, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function orders(Bakery $bakery, string $from, string $to, int $page = 0): array
    {
        $token = $bakery->nanino_token;

        if (blank($token)) {
            throw new RuntimeException('هنوز به نانینو وصل نشده‌اید.');
        }

        $response = Http::timeout(self::TIMEOUT)
            ->withToken($token)
            ->post(self::BASE.'/api/order/user/page', [
                'filter' => [
                    'fromDate' => $from,
                    'toDate' => $to,
                    'status' => 'COMPLETE',
                ],
                'pageNumber' => $page,
                'pageSize' => 20,
                'sortField' => 'id',
                'sortClass' => 'Transaction',
                'direction' => 'DESC',
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            $bakery->forceFill([
                'nanino_token' => null,
                'nanino_last_error' => 'نشست نانینو منقضی شده — دوباره وارد شوید.',
            ])->save();

            throw new RuntimeException('نشست نانینو منقضی شده — دوباره وارد شوید.');
        }

        if (! $response->successful()) {
            $bakery->forceFill(['nanino_last_error' => 'نانینو پاسخ نداد.'])->save();

            throw new RuntimeException('نانینو پاسخ نداد.');
        }

        $bakery->forceFill(['nanino_last_error' => null])->save();

        return (array) ($response->json('content') ?? []);
    }

    /**
     * The token, wherever in the envelope it turns out to be.
     *
     * Written to look rather than to assume, because this is somebody
     * else's internal API and the shape is not promised to anyone.
     */
    private static function tokenFrom(mixed $body): ?string
    {
        foreach (['token', 'accessToken', 'access_token'] as $key) {
            $value = data_get($body, $key) ?? data_get($body, "data.{$key}");

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function refreshFrom(mixed $body): ?string
    {
        foreach (['refreshToken', 'refresh_token'] as $key) {
            $value = data_get($body, $key) ?? data_get($body, "data.{$key}");

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
