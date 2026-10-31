<?php

namespace App\Support;

use App\Models\BaleSetting;

/** A short message to the manager's Bale chat. Never throws. */
class ManagerAlert
{
    /** @var array<int, string>|null captured in tests instead of sending */
    private static ?array $captured = null;

    public static function fake(): void
    {
        self::$captured = [];
    }

    /** @return array<int, string> */
    public static function sent(): array
    {
        return self::$captured ?? [];
    }

    public static function send(string $text): bool
    {
        if (self::$captured !== null) {
            self::$captured[] = $text;

            return true;
        }

        try {
            $setting = BaleSetting::current();
            if (! $setting->is_configured) {
                return false;
            }
            $result = BaleNotifier::sendMessage($setting->bot_token, (string) $setting->chat_id, $text);

            return (bool) ($result['ok'] ?? false);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
