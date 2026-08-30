<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * The Bale bot the shop's backup travels through — see TelegramSetting,
 * which this mirrors. Bale's bot platform is deliberately Telegram-API
 * compatible, and reachable where Telegram itself is not.
 */
class BaleSetting extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'bot_token',
        'chat_id',
        'backup_bale_enabled',
        'last_tested_at',
        'last_test_succeeded',
        'last_test_error',
    ];

    protected $hidden = ['bot_token'];

    protected function casts(): array
    {
        return [
            'backup_bale_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    /** Encrypted going in, decrypted coming out — see MailSetting::password(). */
    protected function botToken(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable) {
                    return null;
                }
            },
            set: fn (?string $value) => $value === null || $value === ''
                ? null
                : Crypt::encryptString($value),
        );
    }

    /** The one row for the current shop, created empty on first look. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** Enough to attempt a send. */
    public function getIsConfiguredAttribute(): bool
    {
        return filled($this->bot_token) && filled($this->chat_id);
    }
}
