<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * The mail server the shop sends from, set in the panel rather than in a
 * file on the box.
 */
class MailSetting extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
        'backup_mail_to',
        'backup_mail_enabled',
        'last_tested_at',
        'last_test_succeeded',
        'last_test_error',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'backup_mail_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    /**
     * Encrypted going in, decrypted coming out.
     *
     * A row in a settings table is read by far more code than .env was, and
     * gets carried into every database backup — including the ones now
     * copied onto an admin's laptop. Storing it plainly would put the mail
     * password in all of them.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable) {
                    // A row written before this, or against a different
                    // APP_KEY. Returning null asks for it again rather than
                    // handing the mailer a scrambled password.
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
        return static::query()->firstOrCreate([], [
            'port' => 587,
            'encryption' => 'tls',
        ]);
    }

    /** Enough to attempt a send. */
    public function getIsConfiguredAttribute(): bool
    {
        return filled($this->host)
            && filled($this->username)
            && filled($this->password)
            && filled($this->from_address);
    }

    /**
     * The addresses the backup goes to, cleaned up.
     *
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return collect(explode(',', (string) $this->backup_mail_to))
            ->map(fn (string $address) => trim($address))
            ->filter(fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
