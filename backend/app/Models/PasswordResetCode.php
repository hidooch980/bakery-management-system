<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * A six-digit code, texted to somebody who cannot get into their phone.
 *
 * Not scoped to a bakery: the person asking is not signed in, so there is
 * no current bakery to scope by. The phone number is the key.
 *
 * The code is hashed. A reset table in plain text is a list of live keys
 * to every account in the shop, and a database is seen by more people than
 * a password ever is — backups, dumps, whoever is helping that day.
 */
class PasswordResetCode extends Model
{
    protected $fillable = [
        'phone',
        'user_id',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'requested_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Still worth trying: not spent, not expired, not guessed to death. */
    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', (int) config('sms.code.attempts', 5));
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('sms.code.attempts', 5);
    }

    public function matches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    /**
     * Six digits, never starting the count at a predictable place.
     *
     * random_int rather than rand: the difference does not show up in
     * testing and does not show up in use either, right up until somebody
     * works out that the codes are a sequence.
     */
    public static function freshCode(): string
    {
        $length = max(4, (int) config('sms.code.length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
