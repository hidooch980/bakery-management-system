<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends the shop's nightly backup — or a test ping — to Bale.
 *
 * Bale's bot platform deliberately mirrors Telegram's (see TelegramNotifier):
 * same base shape, same 50 MB cap on an uploaded document. One documented
 * difference: a failed call is only guaranteed an integer `error_code`, not
 * a human-readable `description` — Telegram always sends both, Bale's own
 * docs only promise the former — so the error message falls back through
 * both before resorting to the raw body.
 */
class BaleNotifier
{
    public const MAX_DOCUMENT_BYTES = 50 * 1024 * 1024;

    /** @return array{ok: bool, error: ?string} */
    public static function sendMessage(string $token, string $chatId, string $text): array
    {
        return self::call(fn () => Http::timeout(15)->asForm()->post(
            "https://tapi.bale.ai/bot{$token}/sendMessage",
            ['chat_id' => $chatId, 'text' => $text],
        ));
    }

    /** @return array{ok: bool, error: ?string} */
    public static function sendDocument(string $token, string $chatId, string $path, string $caption): array
    {
        return self::call(fn () => Http::timeout(120)
            ->attach('document', file_get_contents($path), basename($path))
            ->post("https://tapi.bale.ai/bot{$token}/sendDocument", [
                'chat_id' => $chatId,
                'caption' => $caption,
            ]));
    }

    /**
     * @param  \Closure(): Response  $request
     * @return array{ok: bool, error: ?string}
     */
    private static function call(\Closure $request): array
    {
        try {
            $response = $request();

            if ($response->successful() && $response->json('ok') === true) {
                return ['ok' => true, 'error' => null];
            }

            $error = $response->json('description')
                ?? (($code = $response->json('error_code')) !== null ? "error_code {$code}" : null)
                ?? $response->body();

            return ['ok' => false, 'error' => (string) $error];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
