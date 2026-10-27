<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** تنظیمات ربات و سفیر بله برای همین نانوایی. */
class BaleSetting extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'bot_token',
        'safir_api_key',
        'safir_bot_id',
        'safir_enabled',
        'chat_id',
        'backup_bale_enabled',
        'last_tested_at',
        'last_test_succeeded',
        'last_test_error',
    ];

    protected $hidden = ['bot_token', 'safir_api_key'];

    protected function casts(): array
    {
        return [
            'safir_enabled' => 'boolean',
            'safir_bot_id' => 'integer',
            'backup_bale_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    /** رمزنگاری هنگام نوشتن و رمزگشایی هنگام خواندن. */
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

    /** کلید سفیر با همان روش توکن ربات رمزنگاری می‌شود. */
    protected function safirApiKey(): Attribute
    {
        return $this->botToken();
    }

    /** تنظیمات نانوایی جاری؛ نخستین بار یک ردیف خالی ساخته می‌شود. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** تنظیمات لازم برای ارسال آزمایشی ربات موجود است. */
    public function getIsConfiguredAttribute(): bool
    {
        return filled($this->bot_token) && filled($this->chat_id);
    }
}
